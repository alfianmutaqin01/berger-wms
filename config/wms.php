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
];
