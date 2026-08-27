<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word().' Division';

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name, '_'),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
