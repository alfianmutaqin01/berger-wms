<?php

namespace Database\Factories;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundDetail>
 */
class InboundDetailFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->numberBetween(1, 180);

        return [
            'inbound_header_id' => InboundHeader::factory(),
            'product_id' => Product::factory(),
            'production_order_no' => 'RMO'.fake()->numerify('########'),
            'batch_no' => 'I'.fake()->numerify('#########'),
            'total_qty' => $qty,
            'pallet_no' => 1,
            'pallet_qty' => $qty,
        ];
    }
}
