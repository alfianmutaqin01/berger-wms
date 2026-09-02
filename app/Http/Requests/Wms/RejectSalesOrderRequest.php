<?php

namespace App\Http\Requests\Wms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Menolak pesanan (Fase 6 tahap 1, PRD §6.5 F-OUT-02 langkah 5).
 *
 * Nomor SO TIDAK diminta di sini — keputusan pemilik produk. Pesanan yang
 * ditolak memang tidak pernah dimasukkan ke sistem BC, jadi tidak punya
 * nomor SO untuk diisi.
 *
 * Alasan WAJIB dan tidak boleh sekadar satu-dua huruf: alasannya terbaca
 * Sales di layar detail pesanannya, dan "x" tidak memberi tahu apa pun
 * tentang apa yang harus diperbaiki.
 */
class RejectSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi ditegakkan middleware can:outbound.approval pada route.
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'Alasan penolakan wajib diisi — Sales membacanya di layar pesanannya.',
            'rejection_reason.min' => 'Alasan penolakan terlalu singkat. Tulis minimal 10 karakter agar Sales tahu apa yang harus diperbaiki.',
        ];
    }
}
