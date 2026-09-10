<?php

namespace App\Support\Inventory;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seluruh aturan stocktake, di satu tempat.
 *
 * TIGA KEPUTUSAN YANG MEMBENTUK BERKAS INI
 * ----------------------------------------
 *
 * 1. ANGKA SISTEM DIBEKUKAN SAAT SESI DIBUKA. Menghitung satu gudang makan
 *    waktu berjam-jam sampai berhari-hari, dan selama itu barang tetap
 *    keluar-masuk. Kalau pembandingnya angka "sekarang", tiap pengiriman yang
 *    berangkat di tengah penghitungan terbaca sebagai selisih stocktake —
 *    padahal ia pergerakan yang benar dan sudah tercatat rapi di ledger.
 *
 * 2. KOREKSINYA DITERAPKAN SEBAGAI SELISIH, BUKAN PENIMPAAN. Yang ditambahkan
 *    ke stok saat pengesahan adalah (fisik - beku), bukan angka fisiknya
 *    langsung. Dengan begitu barang yang sah keluar SETELAH raknya dihitung
 *    tidak dihidupkan kembali oleh laporan stocktake. Inilah yang membuat stocktake
 *    tidak perlu membekukan seluruh operasi gudang — dan tanpa itu, stocktake
 *    justru menjadi sumber selisih baru.
 *
 * 3. STOK BARU BERUBAH SAAT LAPORAN DISAHKAN (keputusan pemilik produk).
 *    Selama sesi berjalan, tidak satu pun angka stok tersentuh. Yang mengubah
 *    stok adalah satu tindakan yang jelas dan berpemilik.
 *
 * RAK YANG TIDAK SEMPAT DIHITUNG TIDAK DISENTUH sama sekali, dan jumlahnya
 * ditulis di laporan. Menganggapnya kosong berarti satu rak yang terlewat
 * langsung menghapus stoknya dari sistem — kerusakan yang jauh lebih mahal
 * daripada laporan yang mengaku belum lengkap.
 */
class StockTakeRun
{
    /**
     * Membuka sesi dan membekukan angka sistem seluruh baris stok dalam
     * cakupannya.
     *
     * @throws RuntimeException
     */
    public function open(
        Warehouse $gudang,
        string $scopeType,
        ?string $scopeValue,
        ?string $catatan,
        ?int $userId,
    ): StockTake {
        return DB::transaction(function () use ($gudang, $scopeType, $scopeValue, $catatan, $userId) {
            if (StockTake::berjalan()->where('warehouse_id', $gudang->id)->exists()) {
                throw new RuntimeException(
                    'Gudang ini masih punya sesi stocktake yang berjalan. Selesaikan atau batalkan sesi itu '.
                    'lebih dulu — dua sesi sekaligus berarti dua angka beku untuk rak yang sama.'
                );
            }

            $lokasiIds = $this->lokasiDalamCakupan($gudang, $scopeType, $scopeValue);

            if ($lokasiIds === []) {
                throw new RuntimeException(
                    'Tidak ada rak yang cocok dengan cakupan itu. Periksa lagi pilihan zona atau deretnya.'
                );
            }

            $sesi = StockTake::create([
                'reference' => $this->nomorBaru(),
                'warehouse_id' => $gudang->id,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'status' => StockTake::STATUS_COUNTING,
                'note' => $catatan,
                'opened_at' => now(),
                'opened_by' => $userId,
            ]);

            $this->bekukanAngkaSistem($sesi, $lokasiIds);

            return $sesi;
        });
    }

    /**
     * Menyimpan hasil hitungan fisik satu baris.
     *
     * @throws RuntimeException
     */
    public function count(StockTakeItem $item, int $qtyFisik, ?string $catatan, ?int $userId): void
    {
        DB::transaction(function () use ($item, $qtyFisik, $catatan, $userId) {
            $sesi = StockTake::query()->lockForUpdate()->findOrFail($item->stock_take_id);

            if (! $sesi->sedangDihitung()) {
                throw new RuntimeException(sprintf(
                    'Sesi %s sudah %s, hasil hitungan tidak bisa diubah lagi.',
                    $sesi->reference,
                    strtolower($sesi->status_label),
                ));
            }

            if ($qtyFisik < 0) {
                throw new RuntimeException('Hasil hitungan tidak boleh negatif.');
            }

            $this->pastikanTidakDiBawahCadangan($item, $qtyFisik);

            $item->forceFill([
                'qty_physical' => $qtyFisik,
                'count_note' => $catatan,
                'counted_at' => now(),
                'counted_by' => $userId,
            ])->save();
        });
    }

    /**
     * Mencatat barang yang DITEMUKAN di rak tetapi tidak ada di sistem.
     *
     * Kebalikan dari menghitung 0 — dan sampai sekarang satu-satunya arah yang
     * tidak punya jalur sama sekali. Operator yang menemukan palet di luar
     * daftar mencatatnya di kertas, lalu kertasnya hilang.
     *
     * BARIS INI TIDAK ISTIMEWA. Ia masuk ke tabel yang sama, muncul di layar
     * yang sama, ikut ke laporan yang sama, dan baru menyentuh stok saat
     * laporannya disahkan seperti baris lain. Yang membedakan hanya angka
     * sistemnya nol dan penandanya `is_found`.
     *
     * @param  array{location_id:int, product_id:int, batch_no:string, production_date:string, qty:int, note:?string}  $data
     *
     * @throws RuntimeException
     */
    public function catatTemuan(StockTake $sesi, array $data, ?int $userId): StockTakeItem
    {
        return DB::transaction(function () use ($sesi, $data, $userId) {
            $terkunci = StockTake::query()->lockForUpdate()->findOrFail($sesi->id);

            if (! $terkunci->sedangDihitung()) {
                throw new RuntimeException(sprintf(
                    'Sesi %s sudah %s, temuan baru tidak bisa ditambahkan.',
                    $terkunci->reference,
                    strtolower($terkunci->status_label),
                ));
            }

            $lokasi = Location::find($data['location_id']);

            if ($lokasi === null || $lokasi->warehouse_id !== $terkunci->warehouse_id) {
                throw new RuntimeException('Rak itu bukan milik gudang sesi ini.');
            }

            // Rak di LUAR cakupan sesi ditolak. Sesi "deret B" yang memuat
            // temuan dari deret C akan menerbitkan laporan yang menyentuh rak
            // yang tidak pernah diperiksa siapa pun dalam sesi itu.
            if (! in_array($lokasi->id, $this->lokasiDalamCakupan(
                $terkunci->warehouse, $terkunci->scope_type, $terkunci->scope_value,
            ), true)) {
                throw new RuntimeException(sprintf(
                    'Rak %s di luar cakupan sesi ini (%s). Buka sesi yang mencakup rak itu untuk mencatatnya.',
                    $lokasi->code,
                    $terkunci->scope_label,
                ));
            }

            $batch = trim($data['batch_no']);

            // Kalau batch itu SUDAH ada di daftar hitungan, ini bukan temuan —
            // barisnya tinggal diisi. Membiarkan keduanya berdiri berdampingan
            // akan menjumlahkan barang yang sama dua kali saat pengesahan.
            $sudahAda = $terkunci->items()
                ->where('location_id', $lokasi->id)
                ->where('product_id', $data['product_id'])
                ->where('batch_no', $batch)
                ->first();

            if ($sudahAda !== null) {
                throw new RuntimeException(sprintf(
                    'Batch %s untuk produk itu sudah ada di daftar rak %s. Isi hitungannya di baris tersebut, '.
                    'jangan ditambahkan sebagai temuan — kalau ditambahkan, barang yang sama terhitung dua kali.',
                    $batch,
                    $lokasi->code,
                ));
            }

            return StockTakeItem::create([
                'stock_take_id' => $terkunci->id,
                'inventory_stock_id' => null,
                'is_found' => true,
                'found_production_date' => $data['production_date'],
                'location_id' => $lokasi->id,
                'product_id' => $data['product_id'],
                'batch_no' => $batch,
                'qty_system' => 0,
                'qty_physical' => $data['qty'],
                'count_note' => $data['note'] ?? null,
                'counted_at' => now(),
                'counted_by' => $userId,
            ]);
        });
    }

    /**
     * Mengesahkan laporan: seluruh selisih diterapkan ke stok.
     *
     * SATU-SATUNYA titik di mana stocktake menyentuh angka stok.
     *
     * @return array{baris:int, disesuaikan:int, naik:int, turun:int, belum:int}
     *
     * @throws RuntimeException
     */
    public function finalize(StockTake $sesi, ?int $userId): array
    {
        return DB::transaction(function () use ($sesi, $userId) {
            $terkunci = StockTake::query()->lockForUpdate()->findOrFail($sesi->id);

            if (! $terkunci->sedangDihitung()) {
                throw new RuntimeException(sprintf(
                    'Sesi %s sudah %s dan tidak bisa disahkan lagi.',
                    $terkunci->reference,
                    strtolower($terkunci->status_label),
                ));
            }

            $ringkasan = ['baris' => 0, 'disesuaikan' => 0, 'naik' => 0, 'turun' => 0, 'belum' => 0];

            // Diurutkan menurut baris stok supaya dua proses yang kebetulan
            // menyentuh baris yang sama menguncinya dalam urutan yang sama.
            $baris = $terkunci->items()->orderBy('inventory_stock_id')->orderBy('id')->get();

            foreach ($baris as $item) {
                $ringkasan['baris']++;

                if (! $item->sudahDihitung()) {
                    // TIDAK DISENTUH. Rak yang tidak sempat dihitung bukan rak
                    // kosong, dan menyamakan keduanya menghapus stok yang ada.
                    $ringkasan['belum']++;

                    continue;
                }

                $hasil = $this->terapkanSelisih($item, $terkunci, $userId);

                if ($hasil !== 0) {
                    $ringkasan['disesuaikan']++;
                    $hasil > 0 ? $ringkasan['naik'] += $hasil : $ringkasan['turun'] += abs($hasil);
                }
            }

            $terkunci->fill([
                'status' => StockTake::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $userId,
            ])->save();

            return $ringkasan;
        });
    }

    /**
     * Membatalkan sesi tanpa mengubah stok apa pun.
     *
     * @throws RuntimeException
     */
    public function cancel(StockTake $sesi, ?int $userId): void
    {
        DB::transaction(function () use ($sesi, $userId) {
            $terkunci = StockTake::query()->lockForUpdate()->findOrFail($sesi->id);

            if (! $terkunci->sedangDihitung()) {
                throw new RuntimeException(sprintf(
                    'Sesi %s sudah %s.',
                    $terkunci->reference,
                    strtolower($terkunci->status_label),
                ));
            }

            // Hasil hitungannya SENGAJA tidak dihapus. Sesi yang dibatalkan
            // tetap memberi tahu rak mana saja yang sudah sempat diperiksa —
            // dan itu yang menentukan dari mana penghitungan berikutnya mulai.
            $terkunci->fill([
                'status' => StockTake::STATUS_CANCELLED,
                'finalized_by' => $userId,
            ])->save();
        });
    }

    /* ------------------------------------------------------------ Internal */

    /**
     * Menerapkan selisih satu baris ke stoknya, lalu mencatatnya di ledger.
     *
     * @return int selisih yang benar-benar diterapkan
     */
    private function terapkanSelisih(StockTakeItem $item, StockTake $sesi, ?int $userId): int
    {
        $selisih = (int) $item->selisih;

        // TEMUAN diperiksa lebih dulu, sebelum cabang "baris stoknya hilang"
        // di bawah. Keduanya sama-sama ber-inventory_stock_id NULL tetapi
        // berlawanan artinya: yang ini melahirkan baris stok, yang itu tidak
        // menghasilkan apa pun.
        if ($item->is_found) {
            return $this->wujudkanTemuan($item, $sesi, $userId);
        }

        $stok = $item->inventory_stock_id === null
            ? null
            : InventoryStock::query()->lockForUpdate()->find($item->inventory_stock_id);

        if ($stok === null) {
            // Baris stoknya sudah tidak ada — batchnya habis dan dibersihkan
            // di tengah sesi. Tidak ada tempat menerapkan selisihnya, dan itu
            // memang benar: barangnya sudah bukan bagian dari stok mana pun.
            $item->forceFill(['applied_delta' => 0, 'qty_after' => 0])->save();

            return 0;
        }

        $sebelum = (int) $stok->qty_available;
        // Dijaga tidak negatif. Hasil hitungan di bawah jumlah yang sudah
        // dicadangkan sudah ditolak saat dimasukkan (lihat
        // pastikanTidakDiBawahCadangan), jadi penjagaan ini hanya menangkap
        // pergerakan yang terjadi setelah raknya dihitung.
        $sesudah = max(0, $sebelum + $selisih);
        $diterapkan = $sesudah - $sebelum;

        if ($diterapkan !== 0) {
            $stok->qty_available = $sesudah;
            $stok->save();

            StockMovement::create([
                'product_id' => $item->product_id,
                'location_id' => $item->location_id,
                'warehouse_id' => $sesi->warehouse_id,
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'qty_change' => $diterapkan,
                'qty_before' => $sebelum,
                'qty_after' => $sesudah,
                'reference_type' => StockMovement::REF_ADJUSTMENT,
                'reference_id' => $sesi->id,
                'batch_no' => $item->batch_no,
                'notes' => sprintf(
                    'Stocktake %s: hitungan fisik %d, sistem %d (batch %s).',
                    $sesi->reference,
                    (int) $item->qty_physical,
                    (int) $item->qty_system,
                    $item->batch_no ?? '—',
                ),
                'user_id' => $userId,
            ]);
        }

        $item->forceFill([
            'applied_delta' => $diterapkan,
            'qty_after' => $sesudah + (int) $stok->qty_allocated,
        ])->save();

        return $diterapkan;
    }

    /**
     * Menjadikan baris temuan sebagai stok sungguhan.
     *
     * BARU DI SINI barangnya masuk sistem — bukan saat dicatat di rak. Sampai
     * laporannya disahkan, temuan tidak berbeda dengan hitungan lain: catatan
     * yang belum mengubah apa pun. Kalau sesinya dibatalkan, temuannya ikut
     * tidak jadi, dan itu memang benar.
     *
     * BATCH YANG SAMA DIGABUNG, TIDAK DIDUPLIKASI. Antara sesi dibuka dan
     * laporannya disahkan bisa saja ada inbound yang memasukkan batch itu ke
     * rak yang sama. Membuat baris kedua akan memecah satu tumpukan fisik
     * menjadi dua baris sistem yang diambil FIFO secara terpisah.
     *
     * @return int qty yang benar-benar masuk
     */
    private function wujudkanTemuan(StockTakeItem $item, StockTake $sesi, ?int $userId): int
    {
        $qty = (int) $item->qty_physical;

        if ($qty <= 0) {
            // Temuan yang akhirnya dihitung nol. Tidak ada yang perlu
            // dilahirkan, dan barisnya tetap tinggal di laporan sebagai
            // keterangan bahwa rak itu sudah diperiksa.
            $item->forceFill(['applied_delta' => 0, 'qty_after' => 0])->save();

            return 0;
        }

        $stok = InventoryStock::query()
            ->lockForUpdate()
            ->where('location_id', $item->location_id)
            ->where('product_id', $item->product_id)
            ->where('batch_no', $item->batch_no)
            ->first();

        $sebelum = (int) ($stok?->qty_available ?? 0);

        if ($stok === null) {
            $produk = Product::find($item->product_id);

            $stok = InventoryStock::create([
                'product_id' => $item->product_id,
                'location_id' => $item->location_id,
                'warehouse_id' => $sesi->warehouse_id,
                'batch_no' => $item->batch_no,
                'qty_available' => $qty,
                'qty_allocated' => 0,
                'production_date' => $item->found_production_date->toDateString(),
                'expiry_date' => InventoryStock::calculateExpiry(
                    $item->found_production_date,
                    $produk?->shelf_life_months,
                )->toDateString(),
                'status' => InventoryStock::STATUS_ACTIVE,
                // Pengesahan laporan stocktake ADALAH verifikasinya. Yang
                // menekan tombolnya cuma Super Admin & Manager, dan tanda
                // tangannya tercatat di sini — bukan dibiarkan kosong seolah
                // barang ini muncul tanpa ada yang bertanggung jawab.
                'verified_by' => $userId,
                'verified_at' => now(),
            ]);
        } else {
            $stok->qty_available = $sebelum + $qty;
            $stok->save();
        }

        StockMovement::create([
            'product_id' => $item->product_id,
            'location_id' => $item->location_id,
            'warehouse_id' => $sesi->warehouse_id,
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'qty_change' => $qty,
            'qty_before' => $sebelum,
            'qty_after' => $sebelum + $qty,
            'reference_type' => StockMovement::REF_ADJUSTMENT,
            'reference_id' => $sesi->id,
            'batch_no' => $item->batch_no,
            'notes' => sprintf(
                'Stocktake %s: TEMUAN di rak — %d unit batch %s tidak ada di sistem sebelumnya.',
                $sesi->reference,
                $qty,
                $item->batch_no ?? '—',
            ),
            'user_id' => $userId,
        ]);

        $item->forceFill([
            'inventory_stock_id' => $stok->id,
            'applied_delta' => $qty,
            'qty_after' => (int) $stok->qty_available + (int) $stok->qty_allocated,
        ])->save();

        return $qty;
    }

    /**
     * Menolak hitungan di bawah jumlah yang sudah dicadangkan untuk pesanan.
     *
     * Kekurangan sebanyak itu menyentuh barang yang SUDAH DIJANJIKAN ke
     * pelanggan, dan itu keputusan orang — batalkan alokasinya, atau perbaiki
     * pesanannya — bukan sesuatu yang boleh diselesaikan diam-diam oleh
     * pengesahan laporan.
     *
     * @throws RuntimeException
     */
    private function pastikanTidakDiBawahCadangan(StockTakeItem $item, int $qtyFisik): void
    {
        $stok = $item->inventory_stock_id === null
            ? null
            : InventoryStock::find($item->inventory_stock_id);

        if ($stok === null || $qtyFisik >= $stok->qty_allocated) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Rak ini punya %d unit yang sudah dicadangkan untuk pesanan, sementara hasil hitungan hanya %d. '.
            'Barang yang sudah dijanjikan ke pelanggan tidak boleh hilang lewat stocktake — batalkan dulu '.
            'alokasinya atau perbaiki pesanannya, baru hitungan ini bisa disimpan.',
            $stok->qty_allocated,
            $qtyFisik,
        ));
    }

    /**
     * Menyalin seluruh baris stok dalam cakupan menjadi baris hitungan.
     *
     * @param  list<int>  $lokasiIds
     */
    private function bekukanAngkaSistem(StockTake $sesi, array $lokasiIds): void
    {
        InventoryStock::query()
            ->whereIn('location_id', $lokasiIds)
            // BARIS NOL TIDAK IKUT DIBEKUKAN. Baris dengan tersedia DAN
            // teralokasi sama-sama nol bukan stok — ia sisa batch yang sudah
            // habis atau seluruhnya dipindah ke rak lain. Menyuruh orang
            // berjalan ke rak untuk memastikan nol memang nol menghabiskan
            // waktu yang seharusnya dipakai menghitung barang sungguhan, dan
            // pada gudang yang sudah lama berjalan baris semacam ini menumpuk
            // sampai menenggelamkan yang perlu dihitung.
            //
            // Kalau ternyata di rak itu ADA barangnya, jalurnya bukan baris
            // ini melainkan catatTemuan() — dan hasilnya sama-sama masuk
            // laporan sesi ini.
            ->whereRaw('(qty_available + qty_allocated) > 0')
            ->orderBy('id')
            ->chunkById(500, function ($baris) use ($sesi) {
                $sekarang = now();

                StockTakeItem::insert($baris->map(fn (InventoryStock $s) => [
                    'stock_take_id' => $sesi->id,
                    'inventory_stock_id' => $s->id,
                    'location_id' => $s->location_id,
                    'product_id' => $s->product_id,
                    'batch_no' => $s->batch_no,
                    // Barang FISIK di rak: yang tersedia ditambah yang sudah
                    // dicadangkan. Yang dicadangkan tetap berdiri di rak
                    // sampai operator benar-benar mengambilnya, dan orang yang
                    // menghitung akan ikut menghitungnya.
                    'qty_system' => $s->qty_available + $s->qty_allocated,
                    'created_at' => $sekarang,
                    'updated_at' => $sekarang,
                ])->all());
            });
    }

    /**
     * Rak yang masuk cakupan sesi.
     *
     * @return list<int>
     */
    private function lokasiDalamCakupan(Warehouse $gudang, string $scopeType, ?string $scopeValue): array
    {
        return Location::query()
            ->where('warehouse_id', $gudang->id)
            ->when($scopeType === StockTake::SCOPE_ZONE, fn ($q) => $q->where('zone', $scopeValue))
            ->when($scopeType === StockTake::SCOPE_RACK, fn ($q) => $q->where('rack', $scopeValue))
            ->pluck('id')
            ->all();
    }

    /**
     * Nomor sesi: ST{YYMMDD}{urut 3 digit}.
     *
     * TANPA lockForUpdate: PostgreSQL menolak FOR UPDATE pada query beragregat.
     * Pengamannya ada di tempat yang lebih kuat — indeks unik pada `reference`
     * dan indeks "satu sesi berjalan per gudang", yang bersama-sama membuat
     * dua pembukaan bersamaan mustahil menghasilkan dua baris.
     */
    private function nomorBaru(): string
    {
        $hariIni = now()->format('ymd');

        $urut = StockTake::query()
            ->where('reference', 'like', 'ST'.$hariIni.'%')
            ->count() + 1;

        return 'ST'.$hariIni.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }
}
