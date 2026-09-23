<?php

namespace App\Http\Requests\Wms;

use Illuminate\Validation\Rule;

/**
 * Sama dengan StoreProductCategoryRequest, hanya aturan keunikan nama yang
 * perlu mengecualikan kategori yang sedang disunting.
 */
class UpdateProductCategoryRequest extends StoreProductCategoryRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = [
            'required', 'string', 'max:100',
            Rule::unique('product_categories', 'name')
                ->ignore($this->route('category'))
                ->whereNull('deleted_at'),
        ];

        return $rules;
    }
}
