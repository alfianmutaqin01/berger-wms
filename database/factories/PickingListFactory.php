<?php

namespace Database\Factories;

use App\Models\PickingList;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickingList>
 */
class PickingListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'list_number' => 'PL'.now()->format('ymd').fake()->unique()->numberBetween(100, 999),
            'warehouse_id' => Warehouse::factory(),
            'status' => PickingList::STATUS_OPEN,
        ];
    }

    public function picking(?int $operatorId = null): static
    {
        return $this->state(fn () => [
            'status' => PickingList::STATUS_PICKING,
            'claimed_by' => $operatorId,
            'claimed_at' => now(),
        ]);
    }

    public function completed(?int $operatorId = null): static
    {
        return $this->state(fn () => [
            'status' => PickingList::STATUS_COMPLETED,
            'claimed_by' => $operatorId,
            'claimed_at' => now(),
            'completed_by' => $operatorId,
            'completed_at' => now(),
        ]);
    }
}
