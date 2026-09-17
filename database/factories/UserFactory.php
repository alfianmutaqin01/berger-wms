<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Hash password di-cache agar pembuatan banyak user di test tetap cepat. */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => 'EMP-'.fake()->unique()->numberBetween(100000, 999999),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'phone_number' => '08'.fake()->numerify('##########'),
            'role_id' => Role::factory(),
            'department_id' => Department::factory(),
            'warehouse_id' => null,
            'manager_id' => null,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Departemen bawaan tiap peran, mengikuti DepartmentSeeder.
     *
     * Di lapangan seluruh orang Produksi duduk di satu departemen, dan layar
     * MRF membatasi bacaannya PER DEPARTEMEN. Factory yang memberi tiap user
     * departemen acak membuat dua rekan sedivisi saling tidak terlihat —
     * keadaan yang tidak pernah ada di data sungguhan, dan yang membuat test
     * gagal karena datanya mustahil, bukan karena aturannya salah.
     *
     * @var array<string, array{slug:string, name:string}>
     */
    private const DEPARTEMEN_PERAN = [
        Role::PRODUCTION => ['slug' => 'produksi', 'name' => 'Produksi Inti'],
        Role::SALES => ['slug' => 'sales', 'name' => 'Sales & Marketing'],
        Role::LOGISTICS => ['slug' => 'logistik', 'name' => 'Logistik & Supply Chain'],
        Role::WAREHOUSE_OPERATOR => ['slug' => 'logistik', 'name' => 'Logistik & Supply Chain'],
    ];

    /** User dengan role tertentu berdasarkan slug; role dibuat bila belum ada. */
    public function withRole(string $slug): static
    {
        return $this->state(function () use ($slug) {
            $keadaan = [
                'role_id' => Role::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => Str::headline($slug), 'level' => 99]
                )->id,
            ];

            if (isset(self::DEPARTEMEN_PERAN[$slug])) {
                $departemen = self::DEPARTEMEN_PERAN[$slug];
                $keadaan['department_id'] = Department::firstOrCreate(
                    ['slug' => $departemen['slug']],
                    ['name' => $departemen['name']],
                )->id;
            }

            return $keadaan;
        });
    }

    public function superAdmin(): static
    {
        return $this->withRole(Role::SUPER_ADMIN);
    }

    public function manager(): static
    {
        return $this->withRole(Role::MANAGER);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function atWarehouse(?Warehouse $warehouse = null): static
    {
        return $this->state(fn () => [
            'warehouse_id' => ($warehouse ?? Warehouse::factory()->create())->id,
        ]);
    }
}
