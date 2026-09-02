<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Cakupan wilayah tiap gudang — keputusan pemilik produk, 2026-09-02.
 *
 *   Karawang  (WH-01) : SEMUA wilayah, tanpa kecuali. Satu-satunya gudang yang
 *                       pasti bisa mengirim ke mana pun.
 *   Pekanbaru (WH-02) : HANYA Sumatera 1 dan Sumatera 2.
 *   Surabaya  (WH-03) : semua KECUALI Sumatera 1 dan Sumatera 2.
 *
 * Satu wilayah boleh dilayani lebih dari satu gudang: Sumatera dikirim dari
 * Karawang MAUPUN Pekanbaru, dan Jawa Timur belum tentu dari Surabaya. Karena
 * itu ini bukan pembagian pelanggan, melainkan cakupan — dan tabel `customers`
 * tidak disentuh sama sekali.
 *
 * Karawang sengaja TIDAK diberi satu pun baris territory. Menyalin 14 kode
 * wilayah yang ada hari ini akan membuat wilayah ke-15 besok tidak terlayani
 * gudang mana pun; mode `all` membuat wilayah baru otomatis masuk cakupannya.
 * Alasan yang sama membuat Surabaya memakai `except`, bukan daftar 12 kode.
 */
class WarehouseTerritorySeeder extends Seeder
{
    /**
     * Kode wilayah Sumatera persis seperti tertulis di master pelanggan.
     *
     * Dicocokkan tanpa peka huruf besar/kecil di Warehouse::servesTerritory(),
     * tetapi ditulis di sini apa adanya supaya mudah dibandingkan dengan isi
     * kolom `customers.territory_code`.
     */
    private const SUMATERA = ['SUMATERA 1', 'SUMATERA 2'];

    public function run(): void
    {
        $aturan = [
            'WH-01' => [Warehouse::MODE_ALL, []],
            'WH-02' => [Warehouse::MODE_ONLY, self::SUMATERA],
            'WH-03' => [Warehouse::MODE_EXCEPT, self::SUMATERA],
        ];

        foreach ($aturan as $kode => [$mode, $wilayah]) {
            $gudang = Warehouse::where('code', $kode)->first();

            if ($gudang === null) {
                continue;
            }

            $gudang->update(['territory_mode' => $mode]);

            // Dihapus lalu ditulis ulang, bukan ditambahkan: seeder ini adalah
            // pernyataan keadaan akhir. Kalau hanya menambah, wilayah yang
            // dicabut dari daftar akan tetap tertinggal di database.
            $gudang->territories()->delete();

            foreach ($wilayah as $territory) {
                $gudang->territories()->create(['territory_code' => $territory]);
            }
        }
    }
}
