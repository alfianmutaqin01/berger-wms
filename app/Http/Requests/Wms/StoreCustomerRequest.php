<?php

namespace App\Http\Requests\Wms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi ditegakkan middleware can:master.customers pada route.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => filled($this->input('code')) ? strtoupper(trim($this->input('code'))) : null,
            'ship_to_code' => $this->input('ship_to_code') ?: null,
            'contact_name' => $this->input('contact_name') ?: null,
            'email' => $this->input('email') ?: null,
            'address_2' => $this->input('address_2') ?: null,
            'territory_code' => filled($this->input('territory_code'))
                ? strtoupper(trim($this->input('territory_code')))
                : null,
            // Nomor dari ERP memakai kode negara tanpa tanda plus dan kadang
            // mengandung spasi/strip. Disimpan sebagai digit saja agar
            // pencarian dan pencocokan tidak terganggu variasi penulisan.
            'phone' => filled($this->input('phone'))
                ? preg_replace('/\D/', '', $this->input('phone'))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('customers', 'code')->whereNull('deleted_at')],
            'ship_to_code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:25'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'address_2' => ['nullable', 'string', 'max:500'],
            'territory_code' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Kode pelanggan ini sudah terdaftar.',
            'address.required' => 'Alamat wajib diisi.',
        ];
    }

    public function customerData(): array
    {
        $data = $this->safe()->all();
        $data['is_active'] = $this->boolean('is_active');

        return $data;
    }
}
