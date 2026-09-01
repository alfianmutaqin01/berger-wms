<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderDetail>
 */
class SalesOrderDetailFactory extends Factory
{
    protected $model = SalesOrderDetail::class;

    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'product_id' => Product::factory(),
            'qty_ordered' => 100,
            'qty_approved' => 0,
            'qty_shipped' => 0,
            'lost_qty' => 0,
        ];
    }
}
