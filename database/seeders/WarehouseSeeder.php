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
        $warehouses = [
            ['code' => 'WH-01', 'name' => 'Karawang', 'address' => 'Kawasan Industri Karawang, Jawa Barat'],
            ['code' => 'WH-02', 'name' => 'Pekanbaru', 'address' => 'Pekanbaru, Riau'],
            ['code' => 'WH-03', 'name' => 'Cikarang', 'address' => 'Cikarang, Bekasi, Jawa Barat'],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::updateOrCreate(['code' => $warehouse['code']], $warehouse);
        }
    }
}
