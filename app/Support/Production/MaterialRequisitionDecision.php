<?php

namespace App\Support\Production;

use App\Models\InventoryStock;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\MaterialRequisitionRejection;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MRF — keputusan atas permintaan: atasan (lewat tautan WA) dan Logistik
 * menyetujui atau menolak, serta pembatalan yang melepas cadangan stok.
 *
 * Menyetujui di sisi Logistik MENCADANGKAN stok per batch dan membuat daftar
 * picking; membatalkan melepas cadangan itu lagi. Perjalanan lengkap MRF ada
 * di MaterialRequisitionSubmission.
 */
class MaterialRequisitionDecision
{
    /* ------------------------------------------------- Persetujuan atasan */

    /**
     * Atasan menekan Setuju di tautan WhatsApp.
     *
     * @throws RuntimeException
     */
    public function setujuiApprover(MaterialRequisition $mrf, ?string $catatan): MaterialRequisition
    {
        return DB::transaction(function () use ($mrf, $catatan) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            // Diperiksa ULANG di dalam kunci: tautan yang sama bisa dibuka di
            // dua HP, atau ditekan dua kali karena sinyal lambat.
            if ($terkunci->status !== MaterialRequisition::STATUS_PENDING_APPROVAL) {
                throw new RuntimeException($this->alasanTidakBisaDiputus($terkunci));
            }

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_PENDING_LOGISTICS,
                'approved_at' => now(),
                'approval_note' => blank($catatan) ? null : trim($catatan),
            ])->save();

            return $terkunci;
        });
    }

    /**
     * @throws RuntimeException
     */
    public function tolakApprover(MaterialRequisition $mrf, string $alasan): MaterialRequisition
    {
        if (blank($alasan)) {
            throw new RuntimeException('Alasan penolakan wajib diisi supaya Produksi tahu apa yang harus diperbaiki.');
        }

        return DB::transaction(function () use ($mrf, $alasan) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            if ($terkunci->status !== MaterialRequisition::STATUS_PENDING_APPROVAL) {
                throw new RuntimeException($this->alasanTidakBisaDiputus($terkunci));
            }

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_REJECTED_APPROVAL,
                'approver_rejected_at' => now(),
                'approver_rejection_reason' => trim($alasan),
            ])->save();

            $this->catatPenolakan(
                $terkunci,
                MaterialRequisitionRejection::STAGE_APPROVER,
                trim($alasan),
                $terkunci->approver_name,
                null,
            );

            return $terkunci;
        });
    }

    /* ----------------------------------------------- Persetujuan Logistik */

    /**
     * Logistik menyetujui dan memilih batch sungguhannya.
     *
     * BARANGNYA BELUM BERGERAK DI SINI. Yang terjadi cuma satu: batch yang
     * dipilih berhenti bisa dijual siapa pun, dan seorang operator mendapat
     * tugas mengambilnya. Persis pola transfer antar gudang, dan memang harus
     * sama — kalau MRF memakai aturan buku besar sendiri, cepat atau lambat
     * salah satunya lupa menulis mutasi.
     *
     * @param  list<array{item_id:int, stock_id:int, qty:int}>  $baris
     *
     * @throws RuntimeException
     */
    public function setujuiLogistik(MaterialRequisition $mrf, array $baris, ?int $userId): MaterialRequisition
    {
        if ($baris === []) {
            throw new RuntimeException(
                'Belum ada satu batch pun yang dipilih. Kalau barangnya memang tidak ada di rak, '.
                'tolak permintaannya dengan alasan itu — jangan disetujui kosong.'
            );
        }

        return DB::transaction(function () use ($mrf, $baris, $userId) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            if ($terkunci->status !== MaterialRequisition::STATUS_PENDING_LOGISTICS) {
                throw new RuntimeException(sprintf(
                    'MRF %s berstatus "%s", bukan menunggu Logistik. Muat ulang halamannya.',
                    $terkunci->mrf_number,
                    $terkunci->status_label,
                ));
            }

            $daftar = PickingList::create([
                'list_number' => DocumentNumber::forPickingList(),
                'warehouse_id' => $terkunci->warehouse_id,
                'status' => PickingList::STATUS_OPEN,
                'created_by' => $userId,
                'notes' => sprintf('MRF %s — %s', $terkunci->mrf_number, $terkunci->jenis_label),
            ]);

            $perItem = [];

            foreach ($baris as $satu) {
                $item = $this->itemMilik($terkunci, (int) $satu['item_id']);
                $qty = (int) $satu['qty'];

                $perItem[$item->id] = ($perItem[$item->id] ?? 0) + $qty;

                // Tidak boleh MELEBIHI yang diminta. Kurang boleh — barangnya
                // memang bisa tidak cukup di rak, dan Produksi berhak tahu
                // itu. Lebih tidak boleh: gudang tidak sedang mengirim hadiah,
                // dan kelebihan yang tidak diminta tidak akan pernah ada yang
                // bertanggung jawab memakainya.
                if ($perItem[$item->id] > $item->qty_requested) {
                    throw new RuntimeException(sprintf(
                        'Batch yang dipilih untuk %s berjumlah %d, melebihi %d yang diminta Produksi.',
                        $item->product?->sku ?? 'produk itu',
                        $perItem[$item->id],
                        $item->qty_requested,
                    ));
                }

                $this->cadangkanSatuBatch($terkunci, $daftar, $item, (int) $satu['stock_id'], $qty, $userId);
            }

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_PENDING_PICKING,
                'picking_list_id' => $daftar->id,
                'logistics_approved_at' => now(),
                'logistics_approved_by' => $userId,
            ])->save();

            return $terkunci;
        });
    }

    /**
     * @throws RuntimeException
     */
    public function tolakLogistik(MaterialRequisition $mrf, string $alasan, ?int $userId): MaterialRequisition
    {
        if (blank($alasan)) {
            throw new RuntimeException('Alasan penolakan wajib diisi — Produksi perlu tahu apakah ia harus menunggu atau mencari jalan lain.');
        }

        return DB::transaction(function () use ($mrf, $alasan, $userId) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            if ($terkunci->status !== MaterialRequisition::STATUS_PENDING_LOGISTICS) {
                throw new RuntimeException(sprintf(
                    'MRF %s berstatus "%s", bukan menunggu Logistik.',
                    $terkunci->mrf_number,
                    $terkunci->status_label,
                ));
            }

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_REJECTED_LOGISTICS,
                'logistics_rejected_at' => now(),
                'logistics_rejected_by' => $userId,
                'logistics_rejection_reason' => trim($alasan),
            ])->save();

            $this->catatPenolakan(
                $terkunci,
                MaterialRequisitionRejection::STAGE_LOGISTICS,
                trim($alasan),
                User::find($userId)?->full_name,
                $userId,
            );

            return $terkunci;
        });
    }

    /* ---------------------------------------------------------- Pembatalan */

    /**
     * Membatalkan permintaan yang belum turun dari rak.
     *
     * Cadangannya dilepas dan daftar picking-nya ikut dibubarkan. Dibiarkan
     * hidup, daftar itu menggantung di antrean sebagai tugas yang tidak
     * menuju ke mana-mana — dan operator yang mengerjakannya akan menurunkan
     * barang untuk permintaan yang sudah tidak ada.
     *
     * @throws RuntimeException
     */
    public function batal(MaterialRequisition $mrf, string $alasan, ?int $userId): void
    {
        if (blank($alasan)) {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        DB::transaction(function () use ($mrf, $alasan, $userId) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            if (! $terkunci->bolehDibatalkan()) {
                throw new RuntimeException(sprintf(
                    'MRF %s berstatus "%s" dan tidak bisa dibatalkan lagi.%s',
                    $terkunci->mrf_number,
                    $terkunci->status_label,
                    in_array($terkunci->status, [
                        MaterialRequisition::STATUS_READY_FOR_PICKUP,
                        MaterialRequisition::STATUS_RECEIVED,
                    ], true)
                        ? ' Barangnya sudah turun dari rak dan berpindah ke tangan Produksi — '.
                          'urus kelebihannya lewat MRF Picked atau Penyesuaian Stok.'
                        : '',
                ));
            }

            if ($terkunci->picking_list_id !== null) {
                $this->lepaskanCadangan($terkunci, $alasan, $userId);
            }

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => trim($alasan),
            ])->save();
        });
    }

    /* --------------------------------------------------------------- Dalam */

    /**
     * Mencatat satu penolakan ke riwayat yang tidak pernah dibersihkan.
     *
     * Nomor pengajuannya dihitung dari jumlah baris yang sudah ada: penolakan
     * pertama adalah pengajuan ke-1, dan seterusnya. Dihitung di sini, bukan
     * disimpan sebagai penghitung di MRF-nya, supaya angka itu tidak bisa
     * berbeda dari isi tabelnya sendiri.
     */
    private function catatPenolakan(
        MaterialRequisition $mrf,
        string $tahap,
        string $alasan,
        ?string $namaPenolak,
        ?int $userId,
    ): void {
        $mrf->rejections()->create([
            'stage' => $tahap,
            'reason' => $alasan,
            'attempt_no' => $mrf->rejections()->count() + 1,
            'rejected_by_name' => $namaPenolak,
            'rejected_by' => $userId,
            'rejected_at' => now(),
        ]);
    }

    /**
     * Mencadangkan satu batch dan menuliskan barisnya di daftar picking.
     *
     * DICADANGKAN, BUKAN DIKURANGI. qty_available turun dan qty_allocated naik
     * sebesar yang sama — barangnya masih di rak, masih milik gudang, tetapi
     * tidak bisa dijanjikan ke pelanggan mana pun. Pola yang sama persis
     * dengan FifoAllocator dan WarehouseTransfer.
     *
     * @throws RuntimeException
     */
    private function cadangkanSatuBatch(
        MaterialRequisition $mrf,
        PickingList $daftar,
        MaterialRequisitionItem $item,
        int $stockId,
        int $qty,
        ?int $userId,
    ): void {
        // Dikunci: angka yang dilihat Logistik di layar BISA SUDAH BASI saat
        // tombol ditekan — alokasi pesanan atau transfer lain mungkin sudah
        // mengambil batch yang sama di sela itu.
        $stok = InventoryStock::query()->lockForUpdate()->find($stockId);

        if ($stok === null) {
            throw new RuntimeException('Salah satu batch yang dipilih sudah tidak ada. Muat ulang halaman lalu pilih lagi.');
        }

        if ($stok->warehouse_id !== $mrf->warehouse_id) {
            throw new RuntimeException("Batch {$stok->batch_no} bukan milik gudang yang diminta Produksi.");
        }

        if ($stok->product_id !== $item->product_id) {
            throw new RuntimeException(sprintf(
                'Batch %s bukan produk %s. Baris permintaan dan batch yang dipilih harus produk yang sama.',
                $stok->batch_no ?? '—',
                $item->product?->sku ?? 'yang diminta',
            ));
        }

        if ($qty < 1) {
            throw new RuntimeException("Qty untuk batch {$stok->batch_no} harus minimal 1.");
        }

        if ($qty > $stok->qty_available) {
            throw new RuntimeException(sprintf(
                'Qty untuk batch %s (%d) melebihi stok tersedia (%d). Sisanya mungkin sudah dialokasikan ke pesanan.',
                $stok->batch_no,
                $qty,
                $stok->qty_available,
            ));
        }

        $sebelum = $stok->qty_available;
        $stok->qty_available = $sebelum - $qty;
        $stok->qty_allocated = $stok->qty_allocated + $qty;
        $stok->save();

        $alokasi = $mrf->allocations()->create([
            'material_requisition_item_id' => $item->id,
            'product_id' => $stok->product_id,
            'source_stock_id' => $stok->id,
            'batch_no' => $stok->batch_no,
            'production_date' => $stok->production_date?->toDateString(),
            'expiry_date' => $stok->expiry_date?->toDateString(),
            'status' => $stok->status,
            'ddp_reason' => $stok->ddp_reason,
            'qty_allocated' => $qty,
            // NULL sampai operator selesai. Bukan nol: nol berarti "sudah
            // dicari di rak dan tidak ada satu pun".
            'qty_picked' => null,
        ]);

        StockMovement::create([
            'product_id' => $stok->product_id,
            'location_id' => $stok->location_id,
            'warehouse_id' => $stok->warehouse_id,
            'movement_type' => StockMovement::TYPE_ALLOCATED,
            // Cadangan MENGURANGI yang tersedia; qty_change negatif supaya
            // penjumlahan ledger tetap setara dengan qty_available.
            'qty_change' => -$qty,
            'qty_before' => $sebelum,
            'qty_after' => $stok->qty_available,
            'reference_type' => StockMovement::REF_MATERIAL_REQUISITION,
            'reference_id' => $mrf->id,
            'batch_no' => $stok->batch_no,
            'notes' => sprintf(
                'Dicadangkan untuk %s (%s), menunggu picking.',
                $mrf->mrf_number,
                $mrf->jenis_label,
            ),
            'user_id' => $userId,
        ]);

        $daftar->items()->create([
            'material_requisition_allocation_id' => $alokasi->id,
            'product_id' => $stok->product_id,
            'inventory_stock_id' => $stok->id,
            'location_id' => $stok->location_id,
            'batch_no' => $stok->batch_no,
            'production_date' => $stok->production_date?->toDateString(),
            'qty_to_pick' => $qty,
            'status' => PickingListItem::STATUS_PENDING,
        ]);
    }

    /**
     * Melepas cadangan MRF yang dibatalkan sebelum barangnya turun dari rak.
     *
     * DEALLOCATED, bukan IN. Barangnya tidak pernah keluar; menuliskan mutasi
     * masuk akan MENAMBAH barang yang tidak pernah berkurang, dan stok
     * bertambah dari ketiadaan.
     *
     * @throws RuntimeException
     */
    private function lepaskanCadangan(MaterialRequisition $mrf, string $alasan, ?int $userId): void
    {
        $daftar = PickingList::query()->lockForUpdate()->find($mrf->picking_list_id);

        if ($daftar !== null && $daftar->status === PickingList::STATUS_COMPLETED) {
            throw new RuntimeException(sprintf(
                'Daftar picking %s untuk permintaan ini sudah diselesaikan operator, jadi barangnya sudah turun dari rak.',
                $daftar->list_number,
            ));
        }

        foreach ($mrf->allocations as $alokasi) {
            $stok = $alokasi->source_stock_id === null
                ? null
                : InventoryStock::query()->lockForUpdate()->find($alokasi->source_stock_id);

            if ($stok === null) {
                throw new RuntimeException(sprintf(
                    'Baris stok asal batch %s sudah tidak ada, sehingga cadangannya tidak bisa dilepas ke rak yang benar. '.
                    'Perbaiki dulu lewat Penyesuaian Stok.',
                    $alokasi->batch_no ?? '—',
                ));
            }

            $qty = (int) $alokasi->qty_allocated;
            $sebelum = $stok->qty_available;

            $stok->qty_available = $sebelum + $qty;
            $stok->qty_allocated = max(0, $stok->qty_allocated - $qty);
            $stok->save();

            StockMovement::create([
                'product_id' => $alokasi->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $stok->warehouse_id,
                'movement_type' => StockMovement::TYPE_DEALLOCATED,
                'qty_change' => $qty,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_MATERIAL_REQUISITION,
                'reference_id' => $mrf->id,
                'batch_no' => $alokasi->batch_no,
                'notes' => sprintf(
                    'Pembatalan %s sebelum barang turun dari rak, cadangan dilepas: %s',
                    $mrf->mrf_number,
                    $alasan,
                ),
                'user_id' => $userId,
            ]);
        }

        if ($daftar !== null && $daftar->status !== PickingList::STATUS_CANCELLED) {
            $daftar->fill([
                'status' => PickingList::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => sprintf('MRF %s dibatalkan: %s', $mrf->mrf_number, $alasan),
            ])->save();
        }
    }

    /**
     * @throws RuntimeException
     */
    private function itemMilik(MaterialRequisition $mrf, int $itemId): MaterialRequisitionItem
    {
        $item = $mrf->items()->with('product:id,sku,name')->find($itemId);

        if ($item === null) {
            throw new RuntimeException('Salah satu baris yang dipilih bukan bagian dari permintaan ini. Muat ulang halamannya.');
        }

        return $item;
    }

    private function alasanTidakBisaDiputus(MaterialRequisition $mrf): string
    {
        return match ($mrf->status) {
            MaterialRequisition::STATUS_REJECTED_APPROVAL => sprintf(
                'Permintaan %s sudah ditolak sebelumnya. Alasannya: %s',
                $mrf->mrf_number,
                $mrf->approver_rejection_reason ?? '—',
            ),
            MaterialRequisition::STATUS_CANCELLED => sprintf(
                'Permintaan %s sudah dibatalkan Produksi sendiri.',
                $mrf->mrf_number,
            ),
            default => sprintf(
                'Permintaan %s sudah diputus sebelumnya dan kini berstatus "%s". Tidak ada lagi yang perlu Anda tekan.',
                $mrf->mrf_number,
                $mrf->status_label,
            ),
        };
    }
}
