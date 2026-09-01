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

    /** User dengan role tertentu berdasarkan slug; role dibuat bila belum ada. */
    public function withRole(string $slug): static
    {
        return $this->state(fn () => [
            'role_id' => Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug), 'level' => 99]
            )->id,
        ]);
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
