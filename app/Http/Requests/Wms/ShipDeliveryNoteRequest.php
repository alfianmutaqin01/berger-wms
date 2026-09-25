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
        /*
         * KIRIMAN LUAR PULAU MENGUBAH ISIAN WAJIBNYA, bukan menambahinya.
         *
         * Plat kendaraan tidak lagi diminta: truk yang mengantar ke pelabuhan
         * bukan yang tiba di toko, dan platnya tidak menolong siapa pun dua
         * minggu kemudian. Yang menggantikannya nomor kontainer — itulah yang
         * dicari saat barangnya dipertanyakan.
         *
         * Nomor pelanggan dan tanggal perkiraan WAJIB, karena keduanyalah yang
         * membuat tautannya terbit sendiri nanti. Tanpa salah satunya, kiriman
         * itu akan diam di status "berangkat" sampai ada yang kebetulan
         * menanyakannya berminggu-minggu kemudian.
         */
        $luarPulau = $this->boolean('epod_to_customer');

        return [
            'epod_to_customer' => ['nullable', 'boolean'],

            'driver_name' => ['required', 'string', 'min:2', 'max:100'],
            'driver_phone' => ['required', 'string', 'max:30'],
            'vehicle_plate' => [$luarPulau ? 'nullable' : 'required', 'string', 'min:3', 'max:20'],

            'customer_phone' => [$luarPulau ? 'required' : 'nullable', 'string', 'max:30'],
            // Tanggal kemarin berarti tautannya terbit pada sapuan terdekat,
            // jadi batas bawahnya hari ini. Batas atas dua belas bulan: bukan
            // karena ada pelayaran selama itu, melainkan karena salah ketik
            // tahun ("2027") akan membuat tautannya tidak pernah terbit dan
            // tidak ada seorang pun yang melihat kesalahannya.
            'eta_date' => [
                $luarPulau ? 'required' : 'nullable', 'date',
                'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString(),
            ],
            'forwarder_name' => ['nullable', 'string', 'max:100'],
            'container_no' => [$luarPulau ? 'required' : 'nullable', 'string', 'min:4', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return [
            'driver_name' => 'nama supir',
            'driver_phone' => 'nomor WhatsApp supir',
            'vehicle_plate' => 'plat nomor kendaraan',
            'customer_phone' => 'nomor WhatsApp penerima di toko',
            'eta_date' => 'perkiraan tanggal sampai',
            'forwarder_name' => 'nama ekspedisi',
            'container_no' => 'nomor kontainer',
        ];
    }

    public function messages(): array
    {
        return [
            'eta_date.after_or_equal' => 'Perkiraan tanggal sampai tidak boleh sebelum hari ini — '
                .'tautan konfirmasi baru dikirim pada tanggal tersebut.',
            'container_no.required' => 'Nomor kontainer wajib diisi pada kiriman luar pulau. '
                .'Inilah satu-satunya penanda kiriman ini selama barangnya di perjalanan.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Nomor pelanggan diperiksa dengan jaring yang SAMA, bukan yang
            // lebih longgar. Kesalahannya justru lebih mahal: salah ketik
            // nomor supir ketahuan sore itu juga saat pengirimannya belum
            // dikonfirmasi, sedangkan salah ketik nomor pelanggan baru
            // ketahuan dua minggu kemudian — saat tautannya terbit ke nomor
            // orang lain dan tidak ada yang menunggu jawabannya.
            $kolom = ['driver_phone' => 'supir'];

            if ($this->boolean('epod_to_customer')) {
                $kolom['customer_phone'] = 'penerima di toko';
            }

            foreach ($kolom as $isian => $sebutan) {
                // Aturan dasarnya sudah gagal (kosong, array, terlalu panjang):
                // pesan itu yang ditampilkan, bukan galat 500 dari forWhatsApp(?string).
                if ($validator->errors()->has($isian)) {
                    continue;
                }

                // forWhatsApp(), BUKAN normalize(): yang kedua membiarkan
                // "081234567890" apa adanya, dan WhatsApp tidak mengenal awalan
                // nol nasional. Ia juga menolak sel berisi lebih dari satu nomor
                // — pesan hanya bisa dikirim ke satu tujuan.
                $nomor = PhoneNumber::forWhatsApp($this->input($isian));

                if ($nomor === null) {
                    $validator->errors()->add(
                        $isian,
                        'Isi satu nomor WhatsApp yang terbaca sebagai nomor telepon, bukan beberapa nomor sekaligus.'
                    );

                    continue;
                }

                // Nomor Indonesia sesudah dinormalkan: 62 + 9..13 digit.
                // Batasnya dibuat longgar di ujung atas supaya operator seluler
                // baru tidak ikut tertolak, tetapi cukup ketat untuk menangkap
                // kesalahan yang lazim — digit kurang, atau nomor telepon rumah.
                if (! preg_match('/^62\d{9,13}$/', $nomor)) {
                    $validator->errors()->add(
                        $isian,
                        sprintf(
                            'Nomor WhatsApp %s tidak wajar. Pakai nomor ponsel Indonesia, mis. 081234567890.',
                            $sebutan,
                        )
                    );
                }
            }
        });
    }
}
