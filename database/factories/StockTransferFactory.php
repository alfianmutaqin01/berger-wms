<?php

namespace Database\Factories;

use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transfer_number' => 'TF'.now()->format('ymd').fake()->unique()->numberBetween(100, 999),
            'from_warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => Warehouse::factory(),
            'status' => StockTransfer::STATUS_IN_TRANSIT,
            'shipped_at' => now(),
        ];
    }

    public function received(): static
    {
        return $this->state(fn () => [
            'status' => StockTransfer::STATUS_RECEIVED,
            'received_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => StockTransfer::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Truk batal berangkat.',
        ]);
    }
}
