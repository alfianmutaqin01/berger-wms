<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Kategori '.fake()->unique()->numberBetween(1, 9999),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
