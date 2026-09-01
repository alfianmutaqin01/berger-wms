<?php

namespace Database\Factories;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<InventoryStock>
 */
class InventoryStockFactory extends Factory
{
    public function definition(): array
    {
        $productionDate = Carbon::parse('2026-01-15');

        return [
            'product_id' => Product::factory(),
            'location_id' => Location::factory(),
            'warehouse_id' => Warehouse::factory(),
            'batch_no' => 'I'.fake()->numerify('#########'),
            'qty_available' => 180,
            'qty_allocated' => 0,
            'production_date' => $productionDate->toDateString(),
            'expiry_date' => $productionDate->copy()->addMonths(30)->toDateString(),
            'status' => InventoryStock::STATUS_ACTIVE,
            'ddp_reason' => null,
            'verified_by' => User::factory(),
            'verified_at' => now(),
        ];
    }

    /** Batch dengan tanggal produksi tertentu; kedaluwarsa ikut disesuaikan. */
    public function producedOn(string $date, int $shelfLifeMonths = 30): static
    {
        return $this->state(fn () => [
            'production_date' => $date,
            'expiry_date' => Carbon::parse($date)->addMonths($shelfLifeMonths)->toDateString(),
        ]);
    }

    /** Batch yang kedaluwarsa pada tanggal tertentu, apa pun tanggal produksinya. */
    public function expiringOn(string $date): static
    {
        return $this->state(fn () => ['expiry_date' => $date]);
    }

    public function ddp(string $reason = InventoryStock::DDP_WRITE_OFF): static
    {
        return $this->state(fn () => [
            'status' => InventoryStock::STATUS_DDP,
            'ddp_reason' => $reason,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => InventoryStock::STATUS_EXPIRED,
            'ddp_reason' => InventoryStock::DDP_EXPIRED,
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function allocated(int $qty): static
    {
        return $this->state(fn () => ['qty_allocated' => $qty]);
    }
}
