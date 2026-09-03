<?php

namespace App\Http\Requests\Wms;

use App\Support\PhoneNumber;
use App\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Menyatakan barang berangkat (F-OUT-04 #5).
 *
 * NOMOR SUPIR ADALAH ISIAN YANG PALING BERBAHAYA DI FORMULIR INI, dan
 * bahayanya diam: kalau nomornya salah ketik, pesannya "terkirim" ke nomor
 * orang lain atau ke nomor yang tidak ada, dan tidak ada satu pun yang
 * memberi tahu bahwa supir tidak pernah menerima tautannya. Yang menemukan
 * masalahnya adalah Logistik, keesokan harinya, saat menanyakan kenapa
 * pengiriman belum dikonfirmasi.
 *
 * Karena itu nomornya dinormalkan lebih dulu lalu diperiksa BENTUKNYA —
 * bukan sekadar "wajib diisi". Tidak ada master supir untuk melindunginya
 * (supir berganti tiap hari, sebagian besar dari perusahaan jasa lain), jadi
 * pemeriksaan bentuk inilah satu-satunya jaring yang ada.
 */
class ShipDeliveryNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Batas gudang ditegakkan DI SINI, bukan di controller: validasi
        // berjalan lebih dulu, sehingga permintaan ke gudang lain dengan
        // isian tak lengkap akan dijawab "isian kurang" alih-alih 403.
        return WarehouseScope::allows(
            $this->route('note')?->warehouse_id,
            $this->user(),
        );
    }

    public function rules(): array
    {
        return [
            'driver_name' => ['required', 'string', 'min:2', 'max:100'],
            'driver_phone' => ['required', 'string', 'max:30'],
            'vehicle_plate' => ['required', 'string', 'min:3', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return [
            'driver_name' => 'nama supir',
            'driver_phone' => 'nomor WhatsApp supir',
            'vehicle_plate' => 'plat nomor kendaraan',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // forWhatsApp(), BUKAN normalize(): yang kedua membiarkan
            // "081234567890" apa adanya, dan WhatsApp tidak mengenal awalan
            // nol nasional. Ia juga menolak sel berisi lebih dari satu nomor
            // — pesan hanya bisa dikirim ke satu tujuan.
            $nomor = PhoneNumber::forWhatsApp($this->input('driver_phone'));

            if ($nomor === null) {
                $validator->errors()->add(
                    'driver_phone',
                    'Isi satu nomor WhatsApp yang terbaca sebagai nomor telepon, bukan beberapa nomor sekaligus.'
                );

                return;
            }

            // Nomor Indonesia sesudah dinormalkan: 62 + 9..13 digit.
            // Batasnya dibuat longgar di ujung atas supaya operator seluler
            // baru tidak ikut tertolak, tetapi cukup ketat untuk menangkap
            // kesalahan yang lazim — digit kurang, atau nomor telepon rumah.
            if (! preg_match('/^62\d{9,13}$/', $nomor)) {
                $validator->errors()->add(
                    'driver_phone',
                    'Nomor WhatsApp supir tidak wajar. Pakai nomor ponsel Indonesia, mis. 081234567890.'
                );
            }
        });
    }
}
