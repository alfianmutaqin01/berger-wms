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
            // Kolom ini PENGECUALIAN, bukan salinan: dibiarkan kosong supaya
            // produk hasil factory berperilaku seperti produk sungguhan —
            // mengikuti aturan ukuran di pallet_capacity_rules (2.5 L = 180).
            'max_qty_per_pallet' => null,
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
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Kapasitas paletnya benar-benar TIDAK DIKETAHUI siapa pun.
     *
     * Ukuran kemasannya ikut dikosongkan, bukan cuma kolom pengecualiannya:
     * sejak kapasitas dibaca dari aturan ukuran, produk yang ukurannya
     * kebetulan terdaftar tetap punya kapasitas walau kolomnya kosong — dan
     * test yang memakai state ini justru sedang menguji keadaan sebaliknya.
     */
    public function withoutPalletCapacity(): static
    {
        return $this->state(fn () => [
            'max_qty_per_pallet' => null,
            'pack_size' => null,
            'pack_unit' => null,
        ]);
    }
}
