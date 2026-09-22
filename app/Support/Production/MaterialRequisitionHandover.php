<?php

namespace App\Support\Production;

use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\PickingList;
use App\Models\ProductionMaterialConsumption;
use App\Models\ProductionMaterialHolding;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MRF — serah terima barang ke Produksi dan buku material milik Produksi:
 * siap diambil setelah picking, diambil pemohon lewat tautan divisi,
 * diterima Produksi, lalu dipakai sebagian demi sebagian.
 *
 * DI MANA STOKNYA BERKURANG. Bukan di terima(), melainkan saat operator
 * menekan Siap Loading — dan itu memang saat barangnya benar-benar turun dari
 * rak. Yang dikerjakan terima() hanya memindahkan kepemilikannya: dari barang
 * yang berdiri di rak serah terima menjadi barang yang tercatat di buku
 * Produksi, lengkap dengan sisa yang bisa ditagih kemudian. Mutasinya sendiri
 * ditulis PickingRun, memakai TYPE_PRODUCTION_OUT — lihat alasannya di sana.
 *
 * Perjalanan lengkap MRF ada di MaterialRequisitionSubmission.
 */
class MaterialRequisitionHandover
{
    /** Area produksi bila tempat serah terimanya tidak bernama. */
    private const AREA_BAWAAN = 'Transit Produksi';

    /* ------------------------------------------------------------ Picking */

    /**
     * Operator menekan Serah Terima: barang turun dari rak dan LANGSUNG
     * menjadi milik Produksi.
     *
     * TIDAK ADA LAGI KONFIRMASI TERPISAH DARI PRODUKSI (keputusan pemilik
     * produk). Dulu barangnya menggantung di keadaan "siap diambil" sampai
     * seseorang di Produksi membuka WMS dan menekan Diterima — sebuah langkah
     * yang dikerjakan di depan layar, jauh dari barang yang sudah berpindah
     * tangan di lantai gudang. Yang terjadi kemudian selalu sama: barangnya
     * sudah dibawa, tombolnya tidak pernah ditekan, dan buku Produksi kosong
     * sementara stok gudang sudah berkurang.
     *
     * Serah terima di layar operator TERJADI bersamaan dengan serah terima
     * sungguhan, jadi di situlah kepemilikannya berpindah.
     *
     * Tempat serah terima yang dipilih operator menjadi AREA AWAL material itu
     * di buku Produksi — keterangan pembuka, bukan keputusan akhir: Produksi
     * boleh memindahkannya sendiri setelahnya.
     *
     * WAJIB dipanggil di dalam transaksi milik PickingRun::complete(), dan
     * SESUDAH baris-barisnya dikeluarkan dari rak — qty_picked yang dibaca di
     * sini hasil pekerjaan operator, bukan angka yang dijanjikan Logistik.
     *
     * @return array{diambil:int, kurang:int, lewat_tautan:bool, baris:int, unit:int}
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
                'Tempat serah terima belum diisi. Produksi perlu tahu barangnya berdiri di mana — tanpa itu '.
                'barangnya tidak punya alamat, dan itulah yang membuat permintaan material sering hilang '.
                'dari ingatan.'
            );
        }

        $lokasiSerah = Location::find($handoverLocationId);

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

        /*
         | DUA AKHIR YANG BERBEDA, menurut dari mana permintaannya datang.
         |
         | Permintaan dari AKUN (Produksi, Sales) berpindah tangan di sini
         | juga: serah terima di layar operator terjadi bersamaan dengan serah
         | terima sungguhan di lantai gudang, dan orangnya ada di tempat.
         |
         | Permintaan lewat TAUTAN DIVISI belum bisa selesai: pemohonnya tidak
         | punya akun, tidak berdiri di gudang, dan baru akan dikabari lewat
         | WhatsApp bahwa barangnya sudah bisa diambil. Ia berhenti di "siap
         | diambil" sampai ada yang benar-benar datang mengambilnya, dan nama
         | orang itu yang menutup dokumennya.
         */
        $lewatTautan = $terkunci->lewatTautan();

        $terkunci->fill([
            'status' => $lewatTautan
                ? MaterialRequisition::STATUS_READY_FOR_PICKUP
                : MaterialRequisition::STATUS_RECEIVED,
            'handover_location_id' => $handoverLocationId,
            'handover_note' => blank($catatan) ? null : trim($catatan),
            'picked_at' => now(),
            // Yang menyerahkan, bukan yang menerima. Ditulis apa adanya:
            // memalsukannya sebagai pemohon akan membuat log mengaku Produksi
            // menekan tombol yang tidak pernah ia lihat.
            'received_at' => $lewatTautan ? null : now(),
            'received_by' => $lewatTautan ? null : $userId,
        ])->save();

        $masuk = $lewatTautan
            ? ['baris' => 0, 'unit' => 0]
            : $this->wujudkanDiTanganProduksi(
                $terkunci,
                $lokasiSerah?->nama_serah_terima ?? self::AREA_BAWAAN,
                $userId,
            );

        return ['diambil' => $diambil, 'kurang' => $kurang, 'lewat_tautan' => $lewatTautan] + $masuk;
    }

    /**
     * Barang permintaan lewat tautan diambil pemohonnya. Selesai di sini.
     *
     * TIDAK MASUK BUKU PEMAKAIAN BERTAHAP, dan itu disengaja: divisi lain
     * lazimnya minta satu-dua pcs yang langsung habis, dan baris sekecil itu
     * di daftar sisa berjalan hanya menenggelamkan sisa Produksi yang
     * benar-benar perlu dikejar.
     *
     * TETAPI TETAP MASUK BUKU, sebagai baris yang lahir dan habis sekaligus.
     * Dengan begitu ia muncul di Riwayat Pemakaian seperti pengeluaran
     * material lainnya, dan Logistik bisa menelusurinya setahun kemudian lewat
     * layar yang sama — bukan lewat layar khusus yang harus diingat ada.
     *
     * @return array{baris:int, unit:int}
     *
     * @throws RuntimeException
     */
    public function tandaiDiambil(MaterialRequisition $mrf, string $namaPengambil, ?int $userId): array
    {
        if (trim($namaPengambil) === '') {
            throw new RuntimeException(
                'Nama orang yang mengambil wajib diisi — inilah satu-satunya catatan siapa yang membawa '.
                'barang ini keluar gudang.'
            );
        }

        return DB::transaction(function () use ($mrf, $namaPengambil, $userId) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            if ($terkunci->status !== MaterialRequisition::STATUS_READY_FOR_PICKUP) {
                throw new RuntimeException(sprintf(
                    'MRF %s berstatus "%s", jadi belum ada barang yang menunggu diambil.',
                    $terkunci->mrf_number,
                    $terkunci->status_label,
                ));
            }

            $area = $terkunci->handoverLocation?->nama_serah_terima ?? self::AREA_BAWAAN;
            $hasil = $this->wujudkanDiTanganProduksi($terkunci, $area, $userId);

            // Lahir dan habis sekaligus. Dicatat sebagai pemakaian sungguhan,
            // lengkap dengan nama pengambilnya, supaya Riwayat Pemakaian
            // menjawab pertanyaan yang sama untuk kedua jalur.
            foreach ($terkunci->holdings()->whereNull('finished_at')->get() as $holding) {
                $this->pakai(
                    $holding,
                    (int) $holding->qty_sisa,
                    sprintf('Diambil %s (%s).', trim($namaPengambil), $terkunci->department_name ?? 'divisi pemohon'),
                    $userId,
                );
            }

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $userId,
                'collected_by_name' => trim($namaPengambil),
            ])->save();

            return $hasil;
        });
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

            $hasil = $this->wujudkanDiTanganProduksi($terkunci, $areaProduksi, $userId);

            $terkunci->fill([
                'status' => MaterialRequisition::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $userId,
            ])->save();

            return $hasil;
        });
    }

    /**
     * Menjadikan alokasi yang benar-benar terambil sebagai baris di buku
     * Produksi.
     *
     * DIPAKAI DUA PEMANGGIL: serah terima operator (alur sekarang) dan tombol
     * Diterima milik Produksi (MRF lama yang keburu berstatus siap diambil
     * sebelum alurnya berubah). Ditulis sekali supaya keduanya menghasilkan
     * baris yang sama persis — kalau disalin, suatu hari salah satunya diberi
     * kolom baru dan yang lain tidak, dan tidak ada yang menyadarinya karena
     * keduanya tetap menghasilkan angka yang masuk akal.
     *
     * TIDAK ADA MUTASI STOK DI SINI, dan itu bukan kelalaian: stoknya sudah
     * berkurang saat barangnya turun dari rak. Menulis mutasi kedua di sini
     * akan mengurangi barang yang sama untuk kedua kalinya.
     *
     * @return array{baris:int, unit:int}
     */
    private function wujudkanDiTanganProduksi(
        MaterialRequisition $terkunci,
        string $areaProduksi,
        ?int $userId,
    ): array {
        $area = trim($areaProduksi) === '' ? self::AREA_BAWAAN : trim($areaProduksi);
        $baris = 0;
        $unit = 0;

        foreach ($terkunci->allocations as $alokasi) {
            $qty = (int) $alokasi->qty_picked;

            $alokasi->forceFill(['qty_received' => $qty])->save();

            // Batch yang ternyata tidak ada di rak tidak melahirkan baris di
            // buku Produksi. Barisnya tetap ada di alokasi beserta alasannya,
            // jadi tidak ada yang hilang — yang dihindari adalah baris
            // bernilai nol yang menua di daftar sisa dan menutupi baris yang
            // benar-benar masih ada barangnya.
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

        return ['baris' => $baris, 'unit' => $unit];
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
}
