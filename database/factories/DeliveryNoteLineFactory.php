<?php

namespace Database\Factories;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryNoteLine>
 */
class DeliveryNoteLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'delivery_note_id' => DeliveryNote::factory(),
            'sku' => 'ID1-F'.fake()->unique()->numberBetween(10000000000, 99999999999),
            'product_id' => Product::factory(),
            'description' => fake()->words(3, true),
            'qty' => 10,
            'uom_code' => 'PAI',
        ];
    }
}
