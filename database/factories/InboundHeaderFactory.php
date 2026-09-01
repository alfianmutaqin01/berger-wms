<?php

namespace Database\Factories;

use App\Models\InboundHeader;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundHeader>
 */
class InboundHeaderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_number' => DocumentNumber::format(
                DocumentNumber::PREFIX_INBOUND,
                fake()->unique()->numberBetween(1, 999)
            ),
            'warehouse_id' => Warehouse::factory(),
            'production_date' => now()->toDateString(),
            'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function onDate(string $date): static
    {
        return $this->state(fn () => ['production_date' => $date]);
    }
}
