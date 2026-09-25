<?php

namespace App\Http\Requests\Wms;

use App\Models\DeliveryNoteHandover;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Menyatakan satu amplop Surat Jalan fisik berangkat ke Kantor Pusat.
 *
 * NAMA PEMBAWA WAJIB, apa pun caranya. Pertanyaan pertama saat sebuah amplop
 * tidak sampai selalu sama — "dibawa siapa?" — dan kolom yang boleh kosong
 * akan kosong justru pada amplop yang hilang.
 */
class StoreSjHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gudangnya diperiksa di SjHandover::buat(), setelah daftar Surat
        // Jalannya diketahui: paket belum ada saat request ini tiba, jadi
        // tidak ada warehouse_id yang bisa dibandingkan di sini.
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_note_id' => ['required', 'array', 'min:1'],
            'delivery_note_id.*' => ['required', 'integer', 'exists:delivery_notes,id'],

            'carrier_type' => ['required', Rule::in(array_keys(DeliveryNoteHandover::CARRIER_LABELS))],
            'carrier_name' => ['required', 'string', 'max:100'],

            // Resi hanya masuk akal untuk ekspedisi, dan di situ ia wajib:
            // tanpa resi, "dikirim lewat JNE" tidak bisa dilacak siapa pun.
            'tracking_no' => [
                Rule::requiredIf(fn () => $this->input('carrier_type') === DeliveryNoteHandover::CARRIER_EKSPEDISI),
                'nullable', 'string', 'max:50',
            ],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'delivery_note_id' => 'Surat Jalan',
            'carrier_type' => 'cara kirim',
            'carrier_name' => 'nama pembawa',
            'tracking_no' => 'nomor resi',
            'notes' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_note_id.required' => 'Pilih dulu Surat Jalan yang mau dikirim.',
            'carrier_name.required' => 'Tulis nama orang yang membawanya atau nama ekspedisinya.',
            'tracking_no.required' => 'Kiriman lewat ekspedisi harus disertai nomor resi.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Resi yang tertinggal dari pilihan sebelumnya ikut terkirim dan
        // membuat "dititipkan ke Pak Budi" punya nomor resi JNE.
        if ($this->input('carrier_type') !== DeliveryNoteHandover::CARRIER_EKSPEDISI) {
            $this->merge(['tracking_no' => null]);
        }
    }
}
