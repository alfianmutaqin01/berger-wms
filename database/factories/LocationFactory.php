<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        $rack = fake()->randomLetter();
        $level = fake()->numberBetween(1, Location::MAX_LEVEL);
        $cell = fake()->unique()->numberBetween(1, 999);

        return [
            'warehouse_id' => Warehouse::factory(),
            'code' => Location::buildCode($rack, $level, $cell),
            'rack' => strtoupper($rack),
            'level' => $level,
            'cell' => $cell,
            'zone' => fake()->randomElement(Location::ZONES),
            'is_active' => true,
        ];
    }

    /** Lokasi pada posisi tertentu, kode dibentuk dari komponennya. */
    public function at(string $rack, int $level, int $cell): static
    {
        return $this->state(fn () => [
            'code' => Location::buildCode($rack, $level, $cell),
            'rack' => strtoupper($rack),
            'level' => $level,
            'cell' => $cell,
        ]);
    }

    public function zone(string $zone): static
    {
        return $this->state(fn () => ['zone' => $zone]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
