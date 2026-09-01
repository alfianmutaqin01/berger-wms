<?php

namespace App\Http\Requests\Wms;

use App\Models\Product;
use App\Support\PackSize;
use App\Support\PalletCapacity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi ditegakkan middleware can:master.products pada route.
        return true;
    }

    /**
     * Angka dari form dan dari ekspor Excel bisa memakai koma sebagai pemisah
     * desimal ("4,05"). Dinormalkan lebih dulu agar validasi `numeric` tidak
     * menolaknya dan nilainya tidak terpotong saat disimpan.
     */
    protected function prepareForValidation(): void
    {
        // Ukuran kemasan nominal dibaca dari nama produk ("20Ltr") bila tidak
        // diisi, karena kolom itulah dasar aturan palet — bukan unit_volume
        // yang berisi volume isi sebenarnya (pail 20 L bisa berisi 19.4 L).
        $parsed = PackSize::parse($this->input('name'));

        $this->merge([
            'pack_size' => $this->normalizeDecimal($this->input('pack_size')) ?? ($parsed['size'] ?? null),
            'pack_unit' => $this->input('pack_unit') ?: ($parsed['unit'] ?? null),
            'unit_volume' => $this->normalizeDecimal($this->input('unit_volume')),
            'net_weight' => $this->normalizeDecimal($this->input('net_weight')),
            'gross_weight' => $this->normalizeDecimal($this->input('gross_weight')),
            'category_id' => $this->input('category_id') ?: null,
            'sku' => $this->resolveSku(),
        ]);
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],

            'product_code' => ['required', 'string', 'max:10'],
            'shade_code' => ['required', 'string', 'max:10'],
            'pack_code' => ['required', 'string', 'max:10'],

            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'uom' => ['required', 'string', 'max:20'],

            'pack_size' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'pack_unit' => ['nullable', Rule::in(PalletCapacity::UNITS)],
            'unit_volume' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'net_weight' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'gross_weight' => ['nullable', 'numeric', 'min:0', 'max:99999'],

            'max_qty_per_pallet' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'shelf_life_months' => ['required', 'integer', 'min:1', 'max:120'],
            'stock_threshold_low' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'SKU ini sudah terdaftar. Periksa kombinasi Product Code, Shade Code, dan Pack Code.',
            'pack_unit.in' => 'Satuan kemasan harus L (liter) atau KG.',
        ];
    }

    /**
     * Data siap simpan, lengkap dengan kapasitas palet.
     *
     * Kapasitas dihitung otomatis dari aturan gudang bila Manager tidak
     * mengisinya manual. Bila ukurannya tidak terdaftar di aturan, nilainya
     * dibiarkan NULL — bukan ditebak — dan produk akan ditandai di layar.
     */
    public function productData(): array
    {
        $data = $this->safe()->except('max_qty_per_pallet');

        $data['max_qty_per_pallet'] = $this->filled('max_qty_per_pallet')
            ? (int) $this->input('max_qty_per_pallet')
            : PalletCapacity::resolve($this->input('pack_unit'), $this->input('pack_size'));

        $data['is_active'] = $this->boolean('is_active');

        return $data;
    }

    /** SKU boleh diketik manual (mis. saat menyalin dari ERP); bila kosong, dibentuk dari tiga kode. */
    protected function resolveSku(): ?string
    {
        if (filled($this->input('sku'))) {
            return strtoupper(trim($this->input('sku')));
        }

        if (blank($this->input('product_code')) || blank($this->input('shade_code')) || blank($this->input('pack_code'))) {
            return null;
        }

        return Product::buildSku(
            $this->input('product_code'),
            $this->input('shade_code'),
            $this->input('pack_code'),
        );
    }

    protected function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_replace(',', '.', (string) $value);
    }
}
