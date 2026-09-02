<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Tiga gudang aktif sesuai PRD §4.3.
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
            ['code' => 'WH-01', 'name' => 'Karawang', 'address' => 'Kawasan Industri Karawang, Jawa Barat', 'has_production' => true],
            ['code' => 'WH-02', 'name' => 'Pekanbaru', 'address' => 'Pekanbaru, Riau', 'has_production' => false],
            ['code' => 'WH-03', 'name' => 'Surabaya', 'address' => 'Surabaya, Jawa Timur', 'has_production' => false],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::updateOrCreate(['code' => $warehouse['code']], $warehouse);
        }
    }
}
