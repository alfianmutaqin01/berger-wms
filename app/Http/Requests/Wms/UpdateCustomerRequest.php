<?php

namespace App\Http\Requests\Wms;

use Illuminate\Validation\Rule;

/**
 * Sama dengan StoreCustomerRequest, hanya aturan keunikan kode yang perlu
 * mengecualikan pelanggan yang sedang disunting.
 */
class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['code'] = [
            'required', 'string', 'max:30',
            Rule::unique('customers', 'code')
                ->ignore($this->route('customer'))
                ->whereNull('deleted_at'),
        ];

        return $rules;
    }
}
