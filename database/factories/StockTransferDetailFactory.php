<?php

namespace Database\Factories;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferDetail>
 */
class StockTransferDetailFactory extends Factory
{
    public function definition(): array
    {
        $produksi = fake()->dateTimeBetween('-1 year', '-1 month');

        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'product_id' => Product::factory(),
            'batch_no' => 'BT-'.fake()->unique()->numberBetween(1000, 9999),
            'production_date' => $produksi->format('Y-m-d'),
            'expiry_date' => (clone $produksi)->modify('+2 years')->format('Y-m-d'),
            'status' => InventoryStock::STATUS_ACTIVE,
            'qty_shipped' => fake()->numberBetween(10, 200),
        ];
    }
}
