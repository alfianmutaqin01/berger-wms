<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Urutan seeder mengikuti dependensi foreign key:
     * roles, departments, dan warehouses harus terisi sebelum users dibuat.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DepartmentSeeder::class,
            WarehouseSeeder::class,
            UserSeeder::class,
        ]);
    }
}
