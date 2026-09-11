<?php

namespace App\Http\Requests\Wms;

use App\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Menyusun daftar picking dari beberapa pesanan (F-OUT-03).
 *
 * PEMERIKSAAN GUDANG DI authorize(), BUKAN DI CONTROLLER. Validasi berjalan
 * SEBELUM controller; kalau pemeriksaannya hanya di controller, permintaan
 * untuk gudang lain dengan isian tak lengkap dijawab "isian kurang" alih-alih
 * 403 — dan lubangnya tetap terbuka bagi yang isiannya lengkap.
 */
class StorePickingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WarehouseScope::allows(
            $this->integer('warehouse_id') ?: null,
            $this->user(),
        );
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],

            // Batas atasnya bukan hiasan: satu daftar adalah satu kali jalan
            // kaki seorang operator. Ratusan pesanan dalam satu daftar bukan
            // tugas, melainkan seharian penuh yang tidak bisa diserahkan
            // sebagian ke orang lain.
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['required', 'integer', 'exists:sales_orders,id'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'order_ids' => 'pesanan yang dipilih',
        ];
    }

    public function messages(): array
    {
        return [
            'order_ids.required' => 'Pilih minimal satu pesanan untuk dibuatkan daftar picking.',
            'order_ids.min' => 'Pilih minimal satu pesanan untuk dibuatkan daftar picking.',
            'order_ids.max' => 'Satu daftar picking paling banyak 50 pesanan — lebih dari itu bukan lagi satu kali jalan.',
        ];
    }
}
