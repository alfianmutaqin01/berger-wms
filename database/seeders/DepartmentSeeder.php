<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Departemen awal, mengikuti pilihan yang sudah ada di form Manajemen User.
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Logistik & Supply Chain', 'slug' => 'logistik'],
            ['name' => 'Sales & Marketing', 'slug' => 'sales'],
            ['name' => 'Produksi Inti', 'slug' => 'produksi'],
            ['name' => 'Finance', 'slug' => 'finance'],
            ['name' => 'Human Resource', 'slug' => 'hr'],
            ['name' => 'IT & Sistem', 'slug' => 'it'],
            // Dua divisi yang tidak punya akun WMS tetapi sesekali meminta
            // material lewat tautan divisi — lihat MrfRequestLink.
            ['name' => 'Quality Control', 'slug' => 'qc'],
            ['name' => 'Research & Development', 'slug' => 'rnd'],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(['slug' => $department['slug']], $department);
        }
    }
}
