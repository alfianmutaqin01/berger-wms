<?php

namespace App\Support\Inventory;

use App\Models\InventoryStock;
use App\Models\Location;
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
