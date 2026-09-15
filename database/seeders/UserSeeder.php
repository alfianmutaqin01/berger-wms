<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * User awal untuk pengembangan.
 *
 * DI PRODUCTION HANYA SATU SUPER ADMIN, BERSANDI ACAK. Akun contoh di bawah
 * bersandi `password` dan alamatnya mudah ditebak — di server yang terbuka ke
 * internet, itu pintu yang tidak terkunci. Sandi acaknya ditampilkan SEKALI
 * di layar; Super Admin menggantinya lewat halaman Profil, lalu membuat akun
 * tim sungguhan lewat Manajemen Pengguna. Menjalankan seeder lagi TIDAK
 * menyentuh Super Admin yang sudah ada (versi pengembangan justru
 * mengembalikan sandinya ke `password` tiap kali dijalankan).
 *
 * Nama dan email sengaja mengikuti data mock yang selama ini tampil di halaman
 * Manajemen User, supaya tampilan setelah beralih ke database tetap familier
 * bagi tim yang sudah meninjau prototipe.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'slug');
        $departments = Department::pluck('id', 'slug');
        $warehouses = Warehouse::pluck('id', 'code');

        if (app()->environment('production')) {
            $this->superAdminProduksi($roles[Role::SUPER_ADMIN], $departments['it']);

            return;
        }

        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@berger.co.id'],
            [
                'employee_id' => 'EMP-2020-001',
                'full_name' => 'Super Administrator',
                'password' => Hash::make('password'),
                'phone_number' => '081200000001',
                'role_id' => $roles[Role::SUPER_ADMIN],
                'department_id' => $departments['it'],
                // NULL = akses lintas gudang.
                'warehouse_id' => null,
                'is_active' => true,
            ]
        );

        $users = [
            [
                'email' => 'nisa.logistics@berger.co.id',
                'employee_id' => 'EMP-2023-019',
                'full_name' => 'Khoirun Nisa',
                'phone_number' => '081234567890',
                'role_slug' => Role::LOGISTICS,
                'department_slug' => 'logistik',
                'warehouse_code' => 'ID11_1001',
                'is_active' => true,
            ],
            [
                'email' => 'budi.s@berger.co.id',
                'employee_id' => 'EMP-2021-044',
                'full_name' => 'Budi Santoso',
                'phone_number' => '081298765432',
                'role_slug' => Role::SALES,
                'department_slug' => 'sales',
                'warehouse_code' => null,
                'is_active' => true,
            ],
            [
                'email' => 'andi.w@berger.co.id',
                'employee_id' => 'EMP-2020-008',
                'full_name' => 'Andi Wijaya',
                'phone_number' => '081377788899',
                'role_slug' => Role::PRODUCTION,
                'department_slug' => 'produksi',
                'warehouse_code' => 'ID1I_1001',
                // Contoh akun nonaktif (resign): datanya tetap ada, tidak bisa login.
                'is_active' => false,
            ],
            [
                'email' => 'manager.ops@berger.co.id',
                'employee_id' => 'EMP-2019-002',
                'full_name' => 'Rina Kartika',
                'phone_number' => '081211223344',
                'role_slug' => Role::MANAGER,
                'department_slug' => 'logistik',
                'warehouse_code' => null,
                'is_active' => true,
            ],
            [
                'email' => 'operator.krw@berger.co.id',
                'employee_id' => 'EMP-2024-031',
                'full_name' => 'Dedi Kurniawan',
                'phone_number' => '081255566677',
                'role_slug' => Role::WAREHOUSE_OPERATOR,
                'department_slug' => 'logistik',
                'warehouse_code' => 'ID11_1001',
                'is_active' => true,
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'employee_id' => $data['employee_id'],
                    'full_name' => $data['full_name'],
                    'password' => Hash::make('password'),
                    'phone_number' => $data['phone_number'],
                    'role_id' => $roles[$data['role_slug']],
                    'department_id' => $departments[$data['department_slug']],
                    'warehouse_id' => $data['warehouse_code'] ? $warehouses[$data['warehouse_code']] : null,
                    'is_active' => $data['is_active'],
                    'created_by' => $superAdmin->id,
                ]
            );
        }

        // Manajer operasional menjadi atasan langsung tim gudang, agar relasi
        // manager_id ikut terisi dan alur approval berjenjang bisa diuji.
        $manager = User::where('email', 'manager.ops@berger.co.id')->first();

        User::whereIn('email', ['nisa.logistics@berger.co.id', 'operator.krw@berger.co.id'])
            ->update(['manager_id' => $manager->id]);
    }

    private function superAdminProduksi(int $roleId, int $departmentId): void
    {
        if (User::where('role_id', $roleId)->exists()) {
            $this->command?->info('Super Admin sudah ada — tidak diubah.');

            return;
        }

        $sandi = Str::password(16, symbols: false);

        User::create([
            'employee_id' => 'EMP-ADMIN-001',
            'full_name' => 'Super Administrator',
            'email' => 'superadmin@berger.co.id',
            'password' => Hash::make($sandi),
            'role_id' => $roleId,
            'department_id' => $departmentId,
            'warehouse_id' => null,
            'is_active' => true,
        ]);

        $this->command?->warn('Super Admin dibuat: superadmin@berger.co.id');
        $this->command?->warn('Sandi sementara (TAMPIL SEKALI INI SAJA): '.$sandi);
        $this->command?->warn('Segera login dan ganti sandi lewat halaman Profil.');
    }
}
