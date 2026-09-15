<?php

namespace Database\Factories;

use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialRequisitionItem>
 */
class MaterialRequisitionItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'material_requisition_id' => MaterialRequisition::factory(),
            'product_id' => Product::factory(),
            'qty_requested' => 100,
            'note' => null,
        ];
    }
}
