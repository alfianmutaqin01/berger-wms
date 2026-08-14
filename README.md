# Berger WMS

Sistem Terintegrasi **Warehouse Management & Sales Order** untuk PT Berger Paints Indonesia.

Satu aplikasi dengan dua portal yang terpisah tegas:

- **Portal WMS** — barang masuk dari Produksi, put-away ke rak, stok, stock take, permintaan material (MRF), picking, Surat Jalan, retur, laporan.
- **Portal Sales** — pembuatan pesanan, pemantauan status, unggah bukti Surat Jalan, penolakan pelanggan.

Alur intinya satu garis lurus: **Produksi → rak → pesanan → picking → Surat Jalan → barang sampai → bukti**, dan setiap perpindahan stok punya barisnya sendiri di buku besar stok.

## Teknologi

| | |
|---|---|
| Aplikasi | PHP 8.3, Laravel 13, Blade, Bootstrap 5 |
| Basis data | PostgreSQL 16 |
| Antrean & cache | Redis |
| Pengembangan | Docker Compose (nginx, php-fpm, postgres, redis, queue, scheduler) |
| Notifikasi | Email (SMTP) dan WhatsApp (driver dipilih lewat konfigurasi) |

## Menjalankan di komputer sendiri

```bash
cp .env.example .env
docker compose up -d
docker compose exec -u www-data php-fpm php artisan key:generate
docker compose exec -u www-data php-fpm php artisan migrate --seed
```

Aplikasi berjalan di http://localhost:8080.

> **Penting:** semua perintah `artisan` dijalankan sebagai **`www-data`**, bukan root. Perintah yang jalan sebagai root membuat berkas cache milik root, dan php-fpm yang berjalan sebagai `www-data` tidak bisa lagi menyentuhnya — halaman mati dengan `touch(): Utime failed`. Jalan pintasnya sudah disediakan di `bin/artisan`.

## Pengujian

```bash
./uji.sh ubah      # hanya modul yang sedang disentuh, hitungan detik
./uji.sh penuh     # seluruh suite, ~4 menit — wajib sebelum commit
```

Suite penuh juga dijalankan CI untuk setiap pull request, bersama pemeriksaan gaya penulisan kode (`pint --test`).

## Dokumentasi

Seluruh dokumen ada di [`docs/`](docs/):

| Berkas | Isi |
|---|---|
| `1_prd.md` | Product Requirements Document — acuan utama fitur dan aturan |
| `2_database_design.md` | Rancangan tabel dan relasinya |
| `3_system_architecture.md` | Lapisan sistem dan alur data |
| `4_ui_ux_guidelines.md` | Pedoman tampilan dan interaksi |
| `5_testing_strategy.md` | Strategi pengujian |
| `6_cicd_docker_setup.md` | Docker dan pipeline CI |
| `8_panduan_email_gmail.md` | Penyiapan pengiriman email |
| `9_panduan_go_live.md` | Langkah menuju go-live |
| `10_checklist_uat.md` | Daftar periksa UAT |

## Alur kerja repo

`main` adalah cabang rilis. Pekerjaan dilakukan di cabang fitur, masuk lewat pull request ke `develop` setelah CI hijau, dan `develop` di-merge ke `main` saat rilis.

Seluruh komentar kode, pesan commit, nama test, dan teks antarmuka ditulis dalam **bahasa Indonesia**.
