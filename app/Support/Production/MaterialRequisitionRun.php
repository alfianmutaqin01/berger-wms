<?php

namespace App\Support\Production;

use App\Models\InventoryStock;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\MrfApproverContact;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\ProductionMaterialConsumption;
use App\Models\ProductionMaterialHolding;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seluruh aturan MRF — permintaan material Produksi ke Logistik.
 *
 * SATU JALUR TULIS, seperti WarehouseTransfer untuk transfer antar gudang dan
 * PickingRun untuk picking. Stok adalah angka yang dipercaya keuangan; kalau
 * jalur tulisnya lebih dari satu, cepat atau lambat salah satunya lupa
 * menulis mutasi.
 *
 * PERJALANANNYA, DAN SIAPA YANG MENGERJAKAN TIAP LANGKAH
 * ------------------------------------------------------
 *   ajukan()           Produksi  menyusun permintaan; tautan WA dibuat
 *   setujuiApprover()  atasan    menekan Setuju di tautan WA (tanpa akun)
 *   setujuiLogistik()  Logistik  memilih batch; stok DICADANGKAN, daftar
 *                                picking masuk antrean operator
 *   siapDiambil()      Operator  selesai picking; dipanggil PickingRun
 *   terima()           Produksi  mengambil barangnya; stok pindah ke buku
 *                                Produksi dan HILANG dari inventory Logistik
 *   pakai()            Produksi  memakai sebagian atau seluruhnya, berkali-kali
 *
 * DI MANA STOKNYA BERKURANG. Bukan di terima(), melainkan saat operator
 * menekan Siap Loading — dan itu memang saat barangnya benar-benar turun dari
 * rak. Yang dikerjakan terima() hanya memindahkan kepemilikannya: dari barang
 * yang berdiri di rak serah terima menjadi barang yang tercatat di buku
 * Produksi, lengkap dengan sisa yang bisa ditagih kemudian. Mutasinya sendiri
 * ditulis PickingRun, memakai TYPE_PRODUCTION_OUT — lihat alasannya di sana.
 */
class MaterialRequisitionRun
{
    /**
     * Produksi menyimpan permintaan.
     *
     * @param  list<array{product_id:int, qty:int, note:?string}>  $baris
     *
     * @throws RuntimeException
     */
    public function ajukan(
        User $pemohon,
        int $warehouseId,
        string $jenis,
        string $keperluan,
        array $baris,
        string $namaApprover,
        string $nomorApprover,
        bool $simpanKontak,
    ): MaterialRequisition {
        if ($baris === []) {
            throw new RuntimeException('Belum ada satu produk pun yang diminta. Tambahkan minimal satu baris.');
        }

        $nomor = PhoneNumber::forWhatsApp($nomorApprover);

        if ($nomor === null) {
            throw new RuntimeException(
                'Nomor WhatsApp atasan tidak terbaca sebagai satu nomor telepon. '.
                'Pakai satu nomor ponsel Indonesia, mis. 081234567890.'
            );
        }

        return DB::transaction(function () use (
            $pemohon, $warehouseId, $jenis, $keperluan, $baris, $namaApprover, $nomor, $simpanKontak
        ) {
            $mrf = MaterialRequisition::create([
                'mrf_number' => DocumentNumber::forMaterialRequisition(),
                'warehouse_id' => $warehouseId,
                'requested_by' => $pemohon->id,
                'department_id' => $pemohon->department_id,
                // Disalin sebagai teks: departemen boleh berganti nama, dan
                // dokumen lama harus tetap menyebut bagian yang benar saat itu.
                'department_name' => $pemohon->department?->name,
                'request_type' => $jenis,
                'purpose' => trim($keperluan),
                'status' => MaterialRequisition::STATUS_PENDING_APPROVAL,
                'approver_name' => trim($namaApprover),
                'approver_phone' => $nomor,
                // Token acak 64 karakter, BUKAN disusun dari id: tautan yang
                // bisa ditebak dari nomor urut membuat siapa pun menyetujui
                // permintaan orang lain. Aturan yang sama dengan tautan supir.
                'approval_token' => Str::random(64),
                'notify_status' => MaterialRequisition::NOTIFY_PENDING,
            ]);

            foreach ($baris as $item) {
                $qty = (int) ($item['qty'] ?? 0);

                if ($qty < 1) {
                    throw new RuntimeException('Qty tiap baris permintaan harus minimal 1.');
                }

                $mrf->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'qty_requested' => $qty,
                    'note' => blank($item['note'] ?? null) ? null : trim((string) $item['note']),
                ]);
            }

            if ($simpanKontak) {
                $this->simpanKontak($pemohon, $warehouseId, trim($namaApprover), $nomor);
            }

            return $mrf;
        });
    }

    /**
     * Menyimpan nomor approver supaya MRF berikutnya tinggal diklik.
     *
     * updateOrCreate, bukan create: nomor yang sama disimpan dua kali hanya
     * menghasilkan dua pilihan kembar di formulir, dan yang memilih di antara
     * keduanya tidak punya cara tahu mana yang benar. Kalau namanya berbeda,
     * yang terbaru menang — orang berganti jabatan, nomornya tidak.
     */
    public function simpanKontak(User $pemilik, ?int $warehouseId, string $nama, string $nomor): MrfApproverContact
    {
        return MrfApproverContact::updateOrCreate(
            ['warehouse_id' => $warehouseId, 'phone' => $nomor],
            ['name' => $nama, 'created_by' => $pemilik->id],
        );
    }

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

            return $terkunci;
        });
    }

    /* ------------------------------------------------------------ Picking */

    /**
     * Operator menekan Siap Loading: barang turun dari rak, menunggu Produksi.
     *
     * WAJIB dipanggil di dalam transaksi milik PickingRun::complete(), dan
     * SESUDAH baris-barisnya dikeluarkan dari rak — qty_picked yang dibaca di
     * sini hasil pekerjaan operator, bukan angka yang dijanjikan Logistik.
     *
     * RAK SERAH TERIMA WAJIB DIISI, dan di situlah seluruh guna langkah ini.
     * Tanpa ia, barang yang sudah turun berdiri entah di mana dan Produksi
     * harus menelepon Logistik untuk bertanya — persis kebiasaan yang hendak
     * dihapus fitur ini.
     *
     * @return array{diambil:int, kurang:int}
     *
     * @throws RuntimeException
     */
    public function siapDiambil(
        MaterialRequisition $mrf,
        PickingList $daftar,
        ?int $handoverLocationId,
        ?string $catatan,
        ?int $userId,
    ): array {
        $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

        if ($terkunci->status !== MaterialRequisition::STATUS_PENDING_PICKING) {
            throw new RuntimeException(sprintf(
                'MRF %s berstatus "%s", bukan menunggu picking. Daftar ini tidak bisa diselesaikan lagi.',
                $terkunci->mrf_number,
                $terkunci->status_label,
            ));
        }

        if ($handoverLocationId === null) {
            throw new RuntimeException(
                'Rak tempat barang ini ditaruh belum diisi. Produksi yang akan mengambilnya perlu tahu '.
                'harus berjalan ke mana — tanpa itu barangnya berdiri tanpa alamat, dan itulah yang '.
                'membuat permintaan material sering hilang dari ingatan.'
            );
        }

        $diambil = 0;
        $kurang = 0;

        foreach ($terkunci->allocations as $alokasi) {
            $item = $daftar->items()->where('material_requisition_allocation_id', $alokasi->id)->first();

            // Baris tanpa pasangan di daftar picking berarti daftarnya disusun
            // ulang di luar alur ini. Diperlakukan sebagai tidak terambil —
            // bukan diam-diam dianggap lengkap.
            $qty = $item === null ? 0 : (int) $item->qty_picked;

            $alokasi->forceFill([
                'qty_picked' => $qty,
                'discrepancy_reason' => $item?->discrepancy_reason,
            ])->save();

            $diambil += $qty;
            $kurang += max(0, (int) $alokasi->qty_allocated - $qty);
        }

        $terkunci->fill([
            'status' => MaterialRequisition::STATUS_READY_FOR_PICKUP,
            'handover_location_id' => $handoverLocationId,
            'handover_note' => blank($catatan) ? null : trim($catatan),
            'picked_at' => now(),
        ])->save();

        return ['diambil' => $diambil, 'kurang' => $kurang];
    }

    /* ------------------------------------------------ Penerimaan Produksi */

    /**
     * Produksi mengambil barangnya. Di sinilah kepemilikannya berpindah.
     *
     * TIDAK ADA MUTASI STOK DI SINI, dan itu bukan kelalaian: stoknya sudah
     * berkurang saat operator menekan Siap Loading — barangnya memang sudah
     * turun dari rak sejak saat itu. Menulis mutasi kedua di sini akan
     * mengurangi barang yang sama untuk kedua kalinya.
     *
     * @return array{baris:int, unit:int}
     *
     * @throws RuntimeException
     */
    public function terima(MaterialRequisition $mrf, string $areaProduksi, ?int $userId): array
    {
        return DB::transaction(function () use ($mrf, $areaProduksi, $userId) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            if ($terkunci->status !== MaterialRequisition::STATUS_READY_FOR_PICKUP) {
                throw new RuntimeException(sprintf(
                    'MRF %s berstatus "%s", jadi belum ada barang yang menunggu diambil.',
                    $terkunci->mrf_number,
                    $terkunci->status_label,
                ));
            }

            $area = trim($areaProduksi) === '' ? 'Transit Produksi' : trim($areaProduksi);
            $baris = 0;
            $unit = 0;

            foreach ($terkunci->allocations as $alokasi) {
                $qty = (int) $alokasi->qty_picked;

                $alokasi->forceFill(['qty_received' => $qty])->save();

                // Batch yang ternyata tidak ada di rak tidak melahirkan baris
                // di buku Produksi. Barisnya tetap ada di alokasi beserta
                // alasannya, jadi tidak ada yang hilang — yang dihindari
                // adalah baris bernilai nol yang menua di daftar sisa dan
                // menutupi baris yang benar-benar masih ada barangnya.
                if ($qty < 1) {
                    continue;
                }

                ProductionMaterialHolding::create([
                    'material_requisition_id' => $terkunci->id,
                    'material_requisition_allocation_id' => $alokasi->id,
                    'product_id' => $alokasi->product_id,
                    'warehouse_id' => $terkunci->warehouse_id,
                    'batch_no' => $alokasi->batch_no,
                    'production_date' => $alokasi->production_date?->toDateString(),
                    'expiry_date' => $alokasi->expiry_date?->toDateString(),
                    'production_area' => $area,
                    'qty_received' => $qty,
                    'qty_consumed' => 0,
                    'received_at' => now(),
                    'received_by' => $userId,
                ]);

                $baris++;
                $unit += $qty;
            }

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $userId,
            ])->save();

            return ['baris' => $baris, 'unit' => $unit];
        });
    }

    /**
     * Produksi memakai sebagian — atau seluruh sisanya.
     *
     * DICATAT SEBAGAI BARIS SENDIRI, tidak menimpa angka sisa. Pemilik produk
     * bertanya persis ini: "masuk 300 tanggal berapa, dipakai pertama tanggal
     * berapa, dan berikutnya sampai habis". Kolom sisa saja hanya tahu keadaan
     * hari ini dan melupakan jalan yang ditempuh untuk sampai ke sana.
     *
     * @throws RuntimeException
     */
    public function pakai(ProductionMaterialHolding $holding, int $qty, ?string $catatan, ?int $userId): ProductionMaterialConsumption
    {
        return DB::transaction(function () use ($holding, $qty, $catatan, $userId) {
            $terkunci = ProductionMaterialHolding::query()->lockForUpdate()->findOrFail($holding->id);

            if ($terkunci->sudahHabis()) {
                throw new RuntimeException(sprintf(
                    'Material %s batch %s sudah habis dipakai pada %s.',
                    $terkunci->product?->sku ?? 'ini',
                    $terkunci->batch_no ?? '—',
                    $terkunci->finished_at->format('d/m/Y'),
                ));
            }

            if ($qty < 1) {
                throw new RuntimeException('Qty pemakaian harus minimal 1.');
            }

            if ($qty > $terkunci->qty_sisa) {
                throw new RuntimeException(sprintf(
                    'Qty pemakaian (%d) melebihi sisa yang ada (%d). Kalau di lapangan ternyata lebih banyak, '.
                    'berarti ada penerimaan lain yang belum dicatat — periksa dulu MRF lainnya.',
                    $qty,
                    $terkunci->qty_sisa,
                ));
            }

            $pemakaian = $terkunci->consumptions()->create([
                'qty' => $qty,
                'note' => blank($catatan) ? null : trim($catatan),
                'consumed_at' => now(),
                'consumed_by' => $userId,
            ]);

            $terpakai = $terkunci->qty_consumed + $qty;

            $terkunci->fill([
                'qty_consumed' => $terpakai,
                // Ditutup begitu habis. Kolom inilah yang membedakan "sisa
                // yang masih menunggu dikerjakan" dari "sudah selesai", dan
                // daftar sisa yang tidak pernah menutup dirinya akan berhenti
                // dibaca orang.
                'finished_at' => $terpakai >= $terkunci->qty_received ? now() : null,
            ])->save();

            return $pemakaian;
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
                    $terkunci->status === MaterialRequisition::STATUS_READY_FOR_PICKUP
                        ? ' Barangnya sudah turun dari rak dan berdiri menunggu di rak serah terima — '.
                          'terima dulu, lalu urus kelebihannya lewat Penyesuaian Stok.'
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
