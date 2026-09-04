<?php

namespace App\Http\Requests\Wms;

use App\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Menolak foto bukti Surat Jalan (F-OUT-06).
 *
 * ALASAN WAJIB, dan panjangnya diberi lantai. Sales yang menerima penolakan
 * berada di jalan, membaca dari HP, dan hanya punya satu pertanyaan: harus
 * memotret apa lagi? "Tidak jelas" tidak menjawabnya, dan penolakan yang
 * tidak menjawabnya akan kembali lagi sebagai foto salah yang kedua.
 */
class RejectDeliveryProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Diperiksa DI SINI, bukan di controller: kalau muatannya cacat,
        // validasi berjalan lebih dulu dan pengguna gudang lain akan
        // menerima 422 alih-alih 403 — jawaban yang membocorkan bahwa
        // pesanannya ada.
        return WarehouseScope::allows(
            $this->route('order')?->warehouse_id,
            $this->user(),
        );
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'alasan penolakan'];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Tulis alasannya minimal 10 karakter, mis. "tanda tangan pelanggan tidak terlihat, foto terlalu buram".',
        ];
    }
}
