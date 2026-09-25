<?php

namespace App\Http\Requests\Wms;

use App\Models\DeliveryNoteHandoverItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CA menutup sebuah amplop setelah memeriksa isinya satu per satu.
 *
 * ALASAN WAJIB untuk yang tidak sesuai, dan panjangnya diberi lantai dengan
 * alasan yang sama seperti penolakan foto bukti: yang membaca catatan ini
 * adalah orang gudang yang harus memutuskan mencari kertasnya atau tidak,
 * dan "salah" tidak memberitahunya apa pun.
 */
class ConfirmSjHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Batas gudang diperiksa di controller lewat WarehouseScope::assert()
        // supaya jawabannya 403, bukan 422 — lihat catatan yang sama di
        // RejectDeliveryProofRequest.
        return true;
    }

    public function rules(): array
    {
        return [
            'periksa' => ['required', 'array', 'min:1'],
            'periksa.*.status' => [
                'required',
                Rule::in(array_keys(DeliveryNoteHandoverItem::CHECK_LABELS)),
            ],
            'periksa.*.note' => ['nullable', 'string', 'max:500'],
            'received_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Catatan wajib begitu status bukan "sesuai".
     *
     * Ditulis di after(), bukan sebagai required_if di rules(): nama kolomnya
     * bersarang dengan id baris di tengah (periksa.12.note), dan required_if
     * tidak bisa menunjuk saudara kandungnya sendiri pada kedalaman itu.
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                foreach ((array) $this->input('periksa', []) as $id => $baris) {
                    $status = $baris['status'] ?? null;
                    $catatan = trim((string) ($baris['note'] ?? ''));

                    if (in_array($status, [
                        DeliveryNoteHandoverItem::CHECK_ISSUE,
                        DeliveryNoteHandoverItem::CHECK_MISSING,
                    ], true) && mb_strlen($catatan) < 5) {
                        $validator->errors()->add(
                            'periksa.'.$id.'.note',
                            'Sebutkan apa yang tidak beres, mis. "tanda tangan pelanggan tidak ada" atau "lembarnya tidak ketemu di amplop".',
                        );
                    }
                }
            },
        ];
    }
}
