<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Default: gudang PENYIMPANAN yang melayani semua wilayah.
     *
     * `has_production` sengaja false, sama dengan default kolomnya di basis
     * data. Kalau di sini true, sebuah test bisa lulus hanya karena factory
     * memberi hak produksi yang tidak akan dimiliki gudang sungguhan —
     * aturan "produksi hanya di Karawang" jadi tidak pernah benar-benar diuji.
     */
    public function definition(): array
    {
        return [
            'code' => 'WH-'.fake()->unique()->numberBetween(10, 99),
            'name' => fake()->city(),
            'address' => fake()->address(),
            'territory_mode' => Warehouse::MODE_ALL,
            'has_production' => false,
            'is_active' => true,
        ];
    }

    /** Gudang berlini produksi, seperti Karawang. */
    public function withProduction(): static
    {
        return $this->state(fn () => ['has_production' => true]);
    }

    /**
     * Gudang dengan cakupan wilayah terbatas.
     *
     * @param  list<string>  $territories
     */
    public function covering(string $mode, array $territories = []): static
    {
        return $this->state(fn () => ['territory_mode' => $mode])
            ->afterCreating(function (Warehouse $warehouse) use ($territories) {
                foreach ($territories as $kode) {
                    $warehouse->territories()->create(['territory_code' => $kode]);
                }
            });
    }
}
