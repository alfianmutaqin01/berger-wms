<?php

namespace App\Http\Requests\Wms;

use App\Http\Requests\Concerns\MembacaTeks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
{
    use MembacaTeks;

    public function authorize(): bool
    {
        // Otorisasi ditegakkan middleware can:master.products pada route.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->teks('name'),
            'description' => $this->input('description') ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            /*
             * whereNull('deleted_at') — kategori memakai SoftDeletes. Tanpa
             * ini, nama yang pernah dipakai lalu dibuang akan menolak nama
             * yang sama selamanya, padahal barisnya sudah tidak terlihat di
             * layar mana pun dan tidak ada yang bisa menjelaskan penolakannya.
             */
            'name' => ['required', 'string', 'max:100', Rule::unique('product_categories', 'name')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.unique' => 'Kategori dengan nama ini sudah ada.',
        ];
    }

    public function categoryData(): array
    {
        $data = $this->safe()->all();
        $data['is_active'] = $this->boolean('is_active');

        return $data;
    }
}
