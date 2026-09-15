<?php

/*
|--------------------------------------------------------------------------
| Setelan operasional WMS
|--------------------------------------------------------------------------
|
| Nilai-nilai di sini adalah aturan bisnis yang bisa berubah tanpa mengubah
| kode. Fase 10 akan memindahkan sumbernya ke tabel system_settings supaya
| Super Admin bisa mengubahnya sendiri; sampai saat itu, config inilah satu-
| satunya tempat angkanya ditulis — bukan disebar sebagai angka telanjang di
| dalam controller.
|
*/

return [
    /*
     * Batas jam submit pesanan (PRD §7.5). Lewat jam ini tombol Submit
     * dikunci, tetapi Simpan Draft TETAP aktif — lihat App\Support\OrderCutoff.
     */
    'order_cutoff_hour' => (int) env('WMS_ORDER_CUTOFF_HOUR', 15),

    /*
     * Zona waktu operasional gudang. Sengaja terpisah dari APP_TIMEZONE:
     * aturan "pukul 15:00 WIB" mengikuti jam gudang, bukan jam server.
     */
    'timezone' => env('WMS_TIMEZONE', 'Asia/Jakarta'),

    /*
     * Dokumen PO customer yang diunggah Sales (metode dokumen).
     * Batas 5 MB mengikuti batas bukti Surat Jalan di PRD §6.5 F-OUT-05.
     */
    'order_document' => [
        'max_kb' => 5120,
        'mimes' => ['pdf', 'xlsx', 'xls', 'csv', 'png', 'jpg', 'jpeg'],
    ],

    /*
     * Tautan konfirmasi supir (halaman publik /epod/{token}).
     *
     * berlaku_jam: sejak diterbitkan. Kiriman di wilayah gudang sampai dalam
     * hari yang sama atau esoknya; 72 jam memberi ruang untuk kendaraan yang
     * tertahan tanpa membiarkan tautannya hidup selamanya di chat orang lain.
     *
     * tampil_setelah_sampai_jam: supir yang membuka tautannya lagi masih
     * melihat "sudah tercatat" — tanpa nama pelanggan dan isi kiriman —
     * lalu tautannya mati.
     */
    'epod' => [
        'berlaku_jam' => (int) env('WMS_EPOD_BERLAKU_JAM', 72),
        'tampil_setelah_sampai_jam' => 24,
    ],
];
