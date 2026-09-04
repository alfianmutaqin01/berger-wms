<?php

namespace App\Http\Requests\Sales;

use App\Models\DeliveryProof;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sales mengunggah foto Surat Jalan bertanda tangan (PRD §6.5 F-OUT-05 #3).
 *
 * Batasannya diambil apa adanya dari PRD: PNG/JPG saja, maksimal 5 MB per
 * berkas, paling banyak 3 foto.
 *
 * `mimetypes` DIPAKAI BERSAMA `mimes`, bukan salah satunya. `mimes` hanya
 * melihat ekstensi berkas — berkas apa pun yang dinamai .jpg akan lolos.
 * `mimetypes` membaca isinya. Yang diunggah di sini nantinya ditampilkan
 * kembali di layar Logistik, jadi "berkas yang mengaku gambar" tidak cukup.
 */
class UploadDeliveryProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kepemilikan pesanan diperiksa controller (404, bukan 403, supaya
        // nomor pesanan Sales lain tidak bocor).
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.DeliveryProof::MAKS_FOTO],
            'photos.*' => [
                'required', 'file',
                'mimes:jpg,jpeg,png',
                'mimetypes:'.implode(',', DeliveryProof::MIME_DIIZINKAN),
                'max:'.DeliveryProof::MAKS_UKURAN_KB,
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'photos' => 'foto Surat Jalan',
            'photos.*' => 'foto Surat Jalan',
        ];
    }

    public function messages(): array
    {
        return [
            'photos.required' => 'Pilih dulu foto Surat Jalan yang mau diunggah.',
            'photos.max' => 'Paling banyak '.DeliveryProof::MAKS_FOTO.' foto sekali unggah.',
            'photos.*.mimes' => 'Foto harus JPG atau PNG.',
            'photos.*.mimetypes' => 'Berkas ini bukan foto JPG atau PNG.',
            'photos.*.max' => 'Ukuran tiap foto maksimal 5 MB. Potret ulang dengan resolusi lebih kecil.',
        ];
    }
}
