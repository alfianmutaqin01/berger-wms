<?php

namespace App\Http\Requests\Wms;

use Illuminate\Validation\Rule;

/**
 * Sama dengan StoreLocationRequest, hanya aturan keunikan kode yang perlu
 * mengecualikan lokasi yang sedang disunting.
 */
class UpdateLocationRequest extends StoreLocationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['code'] = [
            'required', 'string', 'max:20',
            'regex:/^[A-Z]{1,2}-\d{1,2}-\d{1,3}$/',
            Rule::unique('locations', 'code')
                ->where('warehouse_id', $this->input('warehouse_id'))
                ->ignore($this->route('location'))
                ->whereNull('deleted_at'),
        ];

        return $rules;
    }
}
