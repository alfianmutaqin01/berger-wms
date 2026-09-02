<?php

namespace App\Http\Requests\Wms;

use App\Models\Warehouse;
use App\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Mengirim stok ke gudang lain (F-INV-05).
 *
 * GUDANG ASAL TIDAK DIMINTA DARI FORMULIR. Ia selalu gudang akun pengirim —
 * lihat WarehouseScope::require() di controller. Menerimanya sebagai isian
 * berarti menyediakan kolom yang bisa dipalsukan untuk mengosongkan gudang
 * orang lain dari jauh.
 */
class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hak fiturnya ditegakkan middleware can:transfer.send pada route;
        // batas gudangnya ditegakkan controller lewat WarehouseScope.
        return true;
    }

    public function rules(): array
    {
        return [
            'to_warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],

            'item' => ['required', 'array', 'min:1', 'max:200'],
            'item.*.stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'item.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'to_warehouse_id' => 'gudang tujuan',
            'item' => 'batch yang dikirim',
        ];
    }

    public function messages(): array
    {
        return [
            'item.required' => 'Pilih minimal satu batch untuk dikirim.',
            'item.min' => 'Pilih minimal satu batch untuk dikirim.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->tolakGudangSendiri($validator);
            $this->tolakBatchGanda($validator);
        });
    }

    /**
     * Transfer ke gudang sendiri bukan transfer.
     *
     * Sudah dijaga CHECK constraint di database, tetapi ditolak di sini juga
     * supaya pengirim membaca kalimat yang menjelaskan, bukan galat SQL.
     */
    private function tolakGudangSendiri(Validator $validator): void
    {
        $asal = WarehouseScope::boundary($this->user());

        if ($asal !== null && (int) $this->input('to_warehouse_id') === $asal) {
            $validator->errors()->add(
                'to_warehouse_id',
                'Gudang tujuan sama dengan gudang Anda. Untuk memindahkan barang antar rak di gudang sendiri, gunakan tombol Pindah di halaman Data Stok.'
            );
        }
    }

    /**
     * Satu baris stok hanya boleh muncul sekali.
     *
     * Dua baris batch yang sama membuat pemeriksaan "qty melebihi stok
     * tersedia" dilakukan terhadap angka yang sama dua kali, sehingga
     * gabungannya bisa lolos melebihi stok yang sebenarnya ada.
     */
    private function tolakBatchGanda(Validator $validator): void
    {
        $ids = collect($this->input('item', []))->pluck('stock_id')->filter();

        if ($ids->count() !== $ids->unique()->count()) {
            $validator->errors()->add('item', 'Ada batch yang dipilih lebih dari sekali. Gabungkan qty-nya menjadi satu baris.');
        }
    }

    /** @return list<array{stock_id:int, qty:int}> */
    public function itemData(): array
    {
        return collect($this->validated('item'))
            ->map(fn (array $baris) => [
                'stock_id' => (int) $baris['stock_id'],
                'qty' => (int) $baris['qty'],
            ])
            ->values()
            ->all();
    }

    public function tujuan(): ?Warehouse
    {
        return Warehouse::find($this->validated('to_warehouse_id'));
    }
}
