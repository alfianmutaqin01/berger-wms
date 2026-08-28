<?php

namespace App\Http\Requests\Wms;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi ditegakkan middleware can:master.locations pada route.
        return true;
    }

    /**
     * Komponen rak/level/sel diturunkan dari kode yang diketik, agar keduanya
     * mustahil berbeda. Mengizinkan pengguna mengisi keduanya secara terpisah
     * akan membuka celah data tidak sinkron — mis. kode "B-01-01" dengan
     * level tersimpan 3.
     */
    protected function prepareForValidation(): void
    {
        $code = strtoupper(trim((string) $this->input('code')));
        $parsed = Location::parseCode($code);

        $this->merge([
            'code' => $code,
            'rack' => $parsed['rack'] ?? null,
            'level' => $parsed['level'] ?? null,
            'cell' => $parsed['cell'] ?? null,
            'zone' => Location::normalizeZone($this->input('zone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'code' => [
                'required', 'string', 'max:20',
                // Pola [Rak]-[Level]-[Sel]; rak boleh satu atau dua huruf (B..ZD).
                'regex:/^[A-Z]{1,2}-\d{1,2}-\d{1,3}$/',
                Rule::unique('locations', 'code')
                    ->where('warehouse_id', $this->input('warehouse_id'))
                    ->whereNull('deleted_at'),
            ],
            'rack' => ['required', 'string', 'max:5'],
            'level' => ['required', 'integer', 'min:1', 'max:'.Location::MAX_LEVEL],
            'cell' => ['required', 'integer', 'min:1', 'max:999'],
            'zone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Format kode harus [Rak]-[Level]-[Sel], contoh: B-01-01.',
            'code.unique' => 'Kode lokasi ini sudah ada di gudang tersebut.',
            'level.max' => 'Level maksimal '.Location::MAX_LEVEL.'.',
            'level.required' => 'Format kode tidak dikenali, sehingga level tidak dapat dibaca.',
        ];
    }

    public function locationData(): array
    {
        $data = $this->safe()->all();
        $data['is_active'] = $this->boolean('is_active');

        return $data;
    }
}
