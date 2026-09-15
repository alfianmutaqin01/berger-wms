<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickingListItem>
 */
class PickingListItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'picking_list_id' => PickingList::factory(),
            'sales_order_id' => SalesOrder::factory(),
            'sales_order_detail_id' => SalesOrderDetail::factory(),
            'product_id' => Product::factory(),
            'location_id' => Location::factory(),
            'batch_no' => 'B'.fake()->numberBetween(1000, 9999),
            'production_date' => now()->subMonths(2)->toDateString(),
            'qty_to_pick' => 10,
            'status' => PickingListItem::STATUS_PENDING,
        ];
    }

    public function picked(?int $operatorId = null): static
    {
        return $this->state(fn (array $atribut) => [
            'qty_picked' => $atribut['qty_to_pick'],
            'status' => PickingListItem::STATUS_PICKED,
            'picked_at' => now(),
            'picked_by' => $operatorId,
        ]);
    }
}
