<?php

namespace Database\Factories;

use App\Models\DeliveryNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryNote>
 */
class DeliveryNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_no' => (string) fake()->unique()->numberBetween(200000, 299999),
            'bc_so_number' => 'SO'.fake()->unique()->numberBetween(260001, 269999),
            'status' => DeliveryNote::STATUS_IMPORTED,
            'shipment_date' => now()->toDateString(),
            'imported_at' => now(),
        ];
    }

    public function shipped(): static
    {
        return $this->state(fn () => ['status' => DeliveryNote::STATUS_SHIPPED]);
    }
}
