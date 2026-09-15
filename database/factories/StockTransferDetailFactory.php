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
        $qty = fake()->numberBetween(10, 200);

        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'product_id' => Product::factory(),
            'batch_no' => 'BT-'.fake()->unique()->numberBetween(1000, 9999),
            'production_date' => $produksi->format('Y-m-d'),
            'expiry_date' => (clone $produksi)->modify('+2 years')->format('Y-m-d'),
            'status' => InventoryStock::STATUS_ACTIVE,
            // Bawaannya kiriman yang SUDAH berangkat utuh: yang diminta sama
            // dengan yang turun dari rak. Keadaan "menunggu picking" dinyatakan
            // terang-terangan lewat state menungguPicking().
            'qty_requested' => $qty,
            'qty_shipped' => $qty,
        ];
    }

    /** Barisnya belum dipicking: belum ada satu unit pun yang berangkat. */
    public function menungguPicking(): static
    {
        return $this->state(fn () => ['qty_shipped' => null]);
    }
}
