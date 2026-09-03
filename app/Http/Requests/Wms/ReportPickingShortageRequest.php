<?php

namespace App\Http\Requests\Wms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Melaporkan barang di rak kurang dari yang tertulis di daftar (F-OUT-03).
 *
 * KEADAAN KHUSUS, BUKAN ALUR UTAMA (keputusan pemilik produk). Jalur normal
 * hanya satu ketuk "Ambil". Pintu ini ada supaya operator yang menemukan rak
 * kurang punya jalan yang jujur — tanpanya ia hanya punya dua pilihan:
 * menandai baris yang tidak ia ambil, atau berhenti dan menahan pengiriman.
 *
 * ALASAN WAJIB dan minimalnya panjang. Selisih stok tanpa keterangan adalah
 * angka yang hilang tanpa jejak, dan itu persis yang paling sering dicari
 * saat opname berikutnya. "Kurang" bukan keterangan.
 */
class ReportPickingShortageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hak fiturnya ditegakkan middleware can:outbound.picking.process,
        // batas gudangnya dan kepemilikan barisnya oleh controller.
        return true;
    }

    public function rules(): array
    {
        return [
            // Boleh 0: rak yang ternyata kosong sama sekali adalah temuan
            // yang paling penting dilaporkan, bukan yang paling tidak.
            'qty_picked' => ['required', 'integer', 'min:0'],
            'discrepancy_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'qty_picked' => 'qty yang ditemukan',
            'discrepancy_reason' => 'alasan selisih',
        ];
    }

    public function messages(): array
    {
        return [
            'discrepancy_reason.min' => 'Tulis alasannya minimal 10 karakter, mis. "rak kosong, sisa fisik hanya 8 kaleng".',
        ];
    }
}
