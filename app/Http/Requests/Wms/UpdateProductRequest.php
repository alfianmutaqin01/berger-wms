<?php

namespace App\Http\Requests\Wms;

use Illuminate\Validation\Rule;

/**
 * Sama dengan StoreProductRequest, hanya aturan keunikan SKU yang perlu
 * mengecualikan produk yang sedang disunting.
 */
class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['sku'] = [
            'required', 'string', 'max:50',
            Rule::unique('products', 'sku')
                ->ignore($this->route('product'))
                ->whereNull('deleted_at'),
        ];

        return $rules;
    }
}
