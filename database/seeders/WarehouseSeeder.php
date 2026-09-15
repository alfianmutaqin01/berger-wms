<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Tiga gudang aktif sesuai PRD §4.3.
 *
 * KODENYA ADALAH KODE RESMI PERUSAHAAN, bukan nomor urut buatan sendiri —
 * ID11_1001 (Karawang), ID1I_1001 (Pekanbaru), ID1B_1001 (Sidoarjo/Surabaya).
 * Semula WH-01/02/03; diganti lewat migration 2026_09_28_000001 supaya
 * pemasangan yang sudah berjalan ikut berpindah, bukan cuma yang baru.
 *
 * Gudang baru dapat ditambahkan lewat menu Admin tanpa perubahan kode.
 */
class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        // `has_production` hanya Karawang: dua gudang lain menyimpan stok yang
        // dikirim dari sana, tidak memproduksi apa pun (keputusan pemilik
        // produk). Cakupan wilayahnya diatur WarehouseTerritorySeeder.
        $warehouses = [
            ['code' => 'ID11_1001', 'name' => 'Karawang', 'address' => 'Kawasan Industri Karawang, Jawa Barat', 'has_production' => true],
            ['code' => 'ID1I_1001', 'name' => 'Pekanbaru', 'address' => 'Pekanbaru, Riau', 'has_production' => false],
            ['code' => 'ID1B_1001', 'name' => 'Sidoarjo/Surabaya', 'address' => 'Surabaya, Jawa Timur', 'has_production' => false],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::updateOrCreate(['code' => $warehouse['code']], $warehouse);
        }
    }
}
