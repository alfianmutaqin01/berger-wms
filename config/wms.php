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

    /*
     |--------------------------------------------------------------------
     | Slide iklan di Dashboard Sales
     |--------------------------------------------------------------------
     |
     | Menggantikan deretan tombol Quick Action, yang isinya sudah ada di
     | sidebar — dua jalan ke tempat yang sama memakan ruang layar HP tanpa
     | menambah satu pun kemampuan.
     |
     | JUMLAHNYA BOLEH 1, 2, ATAU 3. Layar menyesuaikan sendiri:
     |   0 slide  -> bagiannya tidak digambar sama sekali
     |   1 slide  -> tanpa titik indikator dan tanpa pergantian otomatis
     |   2-3      -> titik indikator muncul, berganti sendiri tiap 6 detik
     | Lebih dari 3 dipotong; empat slide di layar HP tidak pernah terbaca
     | sampai habis sebelum orang menggulir lewat.
     |
     | Tiap slide:
     |   label      : teks kecil di atas judul (opsional)
     |   judul      : satu baris, dibaca sekilas
     |   keterangan : satu kalimat pendek (opsional)
     |   tautan     : ke mana slide ini membawa (opsional; tanpa ini tidak diklik)
     |   gambar     : URL gambar latar (opsional)
     |   warna      : gradien latar bila tidak ada gambar
     |
     | ISINYA CONTOH. Ganti dengan materi pemasaran yang sungguhan sebelum
     | go-live, atau kosongkan array ini supaya bagian iklannya hilang —
     | promo karangan di layar Sales akan ditawarkan ke pelanggan sungguhan.
     */
    'promo_sales' => [
        [
            'label' => 'Promo Spesial',
            'judul' => 'Diskon 20% Cat Interior',
            'keterangan' => 'Berlaku untuk semua SKU ukuran Pail. Tawarkan sekarang!',
            'tautan' => null,
            'gambar' => null,
            'warna' => 'linear-gradient(45deg, #1e3a8a, #3b82f6)',
        ],
        [
            'label' => 'Bundling',
            'judul' => 'Beli 10 Gratis 1 Galon',
            'keterangan' => 'Produk WeatherShield khusus order via aplikasi.',
            'tautan' => null,
            'gambar' => null,
            'warna' => 'linear-gradient(45deg, #b91c1c, #f97316)',
        ],
        [
            'label' => 'Info Produk',
            'judul' => 'Warna Baru Tiba!',
            'keterangan' => 'Tersedia 5 varian warna pastel baru. Cek Master Produk.',
            'tautan' => null,
            'gambar' => null,
            'warna' => 'linear-gradient(45deg, #065f46, #10b981)',
        ],
    ],
];
