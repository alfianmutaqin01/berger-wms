<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\PalletCapacity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $productCode = fake()->numerify('00##');
        $shadeCode = fake()->numerify('3###');
        $packCode = fake()->numerify('2##');

        return [
            'sku' => Product::buildSku($productCode, $shadeCode, $packCode),
            'name' => 'Royale '.fake()->word().' '.fake()->colorName(),
            'product_code' => $productCode,
            'shade_code' => $shadeCode,
            'pack_code' => $packCode,
            'category_id' => ProductCategory::factory(),
            'uom' => fake()->randomElement(['TIN', 'PAIL', 'KG', 'CAN']),
            'pack_size' => 2.5,
            'pack_unit' => PalletCapacity::UNIT_LITER,
            'unit_volume' => 2.425,
            'net_weight' => null,
            'gross_weight' => 4.05,
            'max_qty_per_pallet' => 180,
            'shelf_life_months' => 30,
            'stock_threshold_low' => 50,
            'is_active' => true,
        ];
    }

    /** Kemasan berbasis liter; $size = ukuran wadah, bukan volume isi. */
    public function liter(float $size): static
    {
        return $this->state(fn () => [
            'pack_size' => $size,
            'pack_unit' => PalletCapacity::UNIT_LITER,
            'unit_volume' => $size,
            'net_weight' => null,
            'max_qty_per_pallet' => PalletCapacity::resolve(PalletCapacity::UNIT_LITER, $size),
        ]);
    }

    /** Kemasan berbasis kilogram. */
    public function kilogram(float $size): static
    {
        return $this->state(fn () => [
            'pack_size' => $size,
            'pack_unit' => PalletCapacity::UNIT_KILOGRAM,
            'net_weight' => $size,
            'unit_volume' => null,
            'max_qty_per_pallet' => PalletCapacity::resolve(PalletCapacity::UNIT_KILOGRAM, $size),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Ukuran kemasan di luar aturan gudang — kapasitas palet belum diketahui. */
    public function withoutPalletCapacity(): static
    {
        return $this->state(fn () => ['max_qty_per_pallet' => null]);
    }
}
