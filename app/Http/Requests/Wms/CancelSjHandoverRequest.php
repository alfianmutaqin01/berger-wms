<?php

namespace App\Http\Requests\Wms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Membatalkan amplop yang terlanjur dibuat.
 *
 * ALASAN WAJIB. Pembatalan mengembalikan seluruh isinya ke daftar belum
 * kirim — daftar yang dikerjakan orang lain besok paginya, yang akan melihat
 * lembar yang sama muncul lagi dan bertanya kenapa. Jawabannya harus sudah
 * tertulis sebelum pertanyaannya muncul.
 */
class CancelSjHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Batas gudang diperiksa di controller lewat WarehouseScope::assert().
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'alasan pembatalan'];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Tulis alasannya minimal 10 karakter, mis. "salah pilih, SJ 206215 belum ada lembarnya".',
        ];
    }
}
