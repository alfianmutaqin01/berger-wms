<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'WH-'.fake()->unique()->numberBetween(10, 99),
            'name' => fake()->city(),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }
}
