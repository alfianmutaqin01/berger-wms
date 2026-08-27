<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Enam peran baku sesuai docs/2_database_design.md §7.1.
 *
 * `level` menentukan urutan tampil di dropdown sekaligus hierarki wewenang
 * (angka lebih kecil = wewenang lebih tinggi).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Super Admin', 'slug' => Role::SUPER_ADMIN, 'level' => 1, 'description' => 'Akses penuh Portal Warehouse termasuk tugas operasional. Tidak memiliki akses Portal Sales.'],
            ['name' => 'Manager', 'slug' => Role::MANAGER, 'level' => 2, 'description' => 'Master data, manajemen user, pengaturan dokumen, dan adjustment stok.'],
            ['name' => 'Tim Logistik', 'slug' => Role::LOGISTICS, 'level' => 3, 'description' => 'Verifikasi inbound, approval pesanan, surat jalan, dan billing.'],
            ['name' => 'Tim Produksi', 'slug' => Role::PRODUCTION, 'level' => 4, 'description' => 'Input hasil produksi (inbound).'],
            ['name' => 'Operator Gudang', 'slug' => Role::WAREHOUSE_OPERATOR, 'level' => 5, 'description' => 'Put-away dan picking barang di gudang.'],
            ['name' => 'Tim Sales', 'slug' => Role::SALES, 'level' => 6, 'description' => 'Portal Sales: buat pesanan, lacak status, lapor penolakan.'],
        ];

        foreach ($roles as $role) {
            // updateOrCreate agar seeder aman dijalankan berulang tanpa menduplikasi
            // data maupun memutus foreign key user yang sudah menunjuk role ini.
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
