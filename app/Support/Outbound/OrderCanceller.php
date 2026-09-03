<?php

namespace App\Support\Outbound;

use App\Models\InventoryStock;
use App\Models\SalesOrder;
use App\Models\SalesOrderCancellation;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Membatalkan pesanan yang SUDAH diterima Logistik.
 *
 * MENGAPA ADA
 * -----------
 * Setelah pesanan diterima dan nomor SO dari BC dicatat, dua hal masih bisa
 * terjadi di lapangan: customer membatalkan, atau BC ternyata tidak
 * menyetujuinya. Di BC, nomor SO yang gagal itu DIPAKAI ULANG untuk pesanan
 * berikutnya yang berhasil — tidak ada nomor yang terbuang. Tanpa jalan
 * pembatalan di sini, nomor tersebut terkunci selamanya di WMS dan pesanan
 * berikutnya ditolak dengan alasan yang salah ("nomor SO sudah dipakai").
 *
 * TIGA HAL YANG DILAKUKAN, DAN URUTANNYA PENTING
 * -----------------------------------------------
 *   1. Melepas seluruh alokasi stok — kebalikan persis dari FifoAllocator.
 *   2. Mengosongkan nomor SO, sehingga indeks unik melepasnya sendiri.
 *   3. Mengembalikan pesanan ke ANTREAN (status menunggu), bukan menutupnya.
 *
 * Nomor 3 keputusan pemilik produk: pesanan yang ditolak BC lazimnya
 * diperbaiki lalu diajukan lagi dengan nomor SO baru, dan Sales tidak perlu
 * mengetik ulang seluruh item. Bila pembatalannya memang final (customer
 * benar-benar batal), Logistik menolaknya dari antrean dengan alasan.
 *
 * SAMPAI KAPAN BOLEH DIBATALKAN: selama barangnya BELUM BERANGKAT. Begitu
 * Surat Jalan terbit, mengembalikan barang bukan lagi urusan pesanan
 * melainkan RETUR (Fase 7) — barangnya sudah di tangan orang lain, dan
 * mencabut catatannya di sini hanya membuat angka stok berbohong.
 */
class OrderCanceller
{
    /** Status yang masih boleh dibatalkan — barangnya belum berangkat. */
    private const DAPAT_DIBATALKAN = [
        SalesOrder::STATUS_APPROVED,
        SalesOrder::STATUS_PICKING,
        SalesOrder::STATUS_READY_TO_SHIP,
    ];

    /**
     * @return array{qty_dilepas:int, nomor_so:?string}
     *
     * @throws RuntimeException
     */
    public function cancel(SalesOrder $order, string $source, string $reason, ?int $userId): array
    {
        return DB::transaction(function () use ($order, $source, $reason, $userId) {
            $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            // Diperiksa ULANG di dalam kunci: dua orang yang membuka layar
            // riwayat yang sama sama-sama melihat tombol Batalkan aktif.
            $this->pastikanBolehDibatalkan($terkunci);

            $nomorSo = $terkunci->bc_so_number;
            $qtyDilepas = $this->lepasSeluruhAlokasi($terkunci, $userId);

            SalesOrderCancellation::create([
                'sales_order_id' => $terkunci->id,
                'bc_so_number' => $nomorSo,
                'source' => $source,
                'reason' => $reason,
                'qty_released' => $qtyDilepas,
                // Cuplikan penerimaan yang dibatalkan, sebelum ditimpa.
                'approved_at' => $terkunci->approved_at,
                'approved_by' => $terkunci->approved_by,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
            ]);

            // Pesanan tambahan yang menumpang nomor SO ini ikut terlepas.
            // Kalau tidak, mereka menunjuk induk yang nomornya sudah kosong —
            // dan "satu invoice" itu jadi tidak berarti apa-apa lagi.
            $this->lepaskanPesananTambahan($terkunci, $userId);

            $terkunci->fill([
                'status' => SalesOrder::STATUS_PENDING,
                // Dikosongkan supaya nomornya kembali bisa dipakai. Salinannya
                // sudah aman di sales_order_cancellations.
                'bc_so_number' => null,
                'so_merged_into_id' => null,
                'approved_at' => null,
                'approved_by' => null,
                'approval_note' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_source' => $source,
                'cancellation_reason' => $reason,
            ])->save();

            return ['qty_dilepas' => $qtyDilepas, 'nomor_so' => $nomorSo];
        });
    }

    private function pastikanBolehDibatalkan(SalesOrder $order): void
    {
        if (in_array($order->status, self::DAPAT_DIBATALKAN, true)) {
            return;
        }

        if ($order->status === SalesOrder::STATUS_PENDING) {
            throw new RuntimeException(
                "Pesanan {$order->order_number} belum diterima, jadi tidak ada yang perlu dibatalkan. ".
                'Gunakan tombol Tolak di layar penerimaan.'
            );
        }

        throw new RuntimeException(sprintf(
            'Pesanan %s sudah %s dan barangnya sudah berangkat. Pengembaliannya lewat alur Retur, bukan pembatalan — '.
            'mencabut catatannya di sini hanya membuat angka stok berbeda dari kenyataan di lapangan.',
            $order->order_number,
            strtolower($order->status_label)
        ));
    }

    /**
     * Mengembalikan seluruh stok yang sudah dicadangkan pesanan ini.
     *
     * Kebalikan persis dari FifoAllocator::allocate(): qty_available naik,
     * qty_allocated turun, dan ledger menerima baris DEALLOCATED bernilai
     * POSITIF. Barisnya ditambahkan, bukan alokasi lamanya dihapus dari
     * ledger — stock_movements bersifat append-only, dan koreksi selalu
     * berupa mutasi lawan.
     */
    private function lepasSeluruhAlokasi(SalesOrder $order, ?int $userId): int
    {
        $total = 0;

        $order->load('details.allocations');

        foreach ($order->details as $detail) {
            foreach ($detail->allocations as $alokasi) {
                $stok = InventoryStock::query()->lockForUpdate()->find($alokasi->inventory_stock_id);

                if ($stok === null) {
                    // Baris stoknya sudah tidak ada. Alokasinya tetap dihapus
                    // supaya tidak menggantung, tetapi tidak ada tempat untuk
                    // mengembalikan qty-nya — dan itu memang benar: barangnya
                    // sudah bukan bagian dari stok mana pun.
                    $alokasi->delete();

                    continue;
                }

                $qty = (int) $alokasi->qty_allocated;
                $sebelum = $stok->qty_available;

                $stok->qty_available = $sebelum + $qty;
                $stok->qty_allocated = max(0, $stok->qty_allocated - $qty);
                $stok->save();

                StockMovement::create([
                    'product_id' => $detail->product_id,
                    'location_id' => $stok->location_id,
                    'warehouse_id' => $stok->warehouse_id,
                    'movement_type' => StockMovement::TYPE_DEALLOCATED,
                    'qty_change' => $qty,
                    'qty_before' => $sebelum,
                    'qty_after' => $stok->qty_available,
                    'reference_type' => StockMovement::REF_SALES_ORDER,
                    'reference_id' => $order->id,
                    'batch_no' => $stok->batch_no,
                    'notes' => sprintf(
                        'Pembatalan %s, alokasi dilepas kembali ke stok (batch %s).',
                        $order->order_number,
                        $stok->batch_no ?? '—'
                    ),
                    'user_id' => $userId,
                ]);

                $total += $qty;
                $alokasi->delete();
            }

            // Pesanan kembali ke antrean, jadi keputusan Logistik atasnya
            // ikut dihapus: qty_approved 0 berarti "belum dinilai", bukan
            // "dinilai nol" — pembedanya status header (lihat SalesOrderDetail).
            $detail->fill(['qty_approved' => 0, 'outstanding_qty' => 0])->save();
        }

        return $total;
    }

    /**
     * Melepas pesanan tambahan yang berbagi nomor SO dengan pesanan ini.
     *
     * Mereka ikut kembali ke antrean: nomor SO yang mereka tumpangi sudah
     * tidak berlaku, dan membiarkannya berstatus diterima berarti ada pesanan
     * yang "sudah masuk BC" dengan nomor yang tidak ada di BC.
     */
    private function lepaskanPesananTambahan(SalesOrder $induk, ?int $userId): void
    {
        $tambahan = SalesOrder::where('so_merged_into_id', $induk->id)->get();

        foreach ($tambahan as $anak) {
            if (! in_array($anak->status, self::DAPAT_DIBATALKAN, true)) {
                throw new RuntimeException(sprintf(
                    'Pesanan tambahan %s yang menumpang nomor SO ini sudah %s dan barangnya berangkat. '.
                    'Batalkan atau selesaikan pesanan tambahan itu lebih dulu.',
                    $anak->order_number,
                    strtolower($anak->status_label)
                ));
            }

            $this->cancel(
                $anak,
                SalesOrderCancellation::SOURCE_INTERNAL,
                sprintf('Ikut batal karena pesanan induk %s dibatalkan.', $induk->order_number),
                $userId,
            );
        }
    }
}
