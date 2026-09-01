#!/bin/sh
#
# Entrypoint pengembangan: menyembuhkan kepemilikan berkas sebelum PHP-FPM
# mulai melayani.
#
# MASALAH YANG DIOBATI
# --------------------
# `docker compose exec php-fpm ...` tanpa `-u www-data` berjalan sebagai
# ROOT. Berkas yang dibuatnya — terutama view Blade terkompilasi di
# storage/framework/views — menjadi milik root. PHP-FPM berjalan sebagai
# www-data, dan Blade memanggil touch($path, $mtime) dengan mtime eksplisit;
# pemanggilan itu menuntut KEPEMILIKAN berkas, bukan sekadar izin tulis.
# Maka halaman yang bersangkutan mati dengan:
#
#   ErrorException: touch(): Utime failed: Operation not permitted
#
# Direktori 777 TIDAK menolong, dan trik setgid juga tidak — yang gagal
# adalah mengubah mtime berkas milik orang lain.
#
# Kejadian ini sudah berulang lima kali di proyek ini, selalu muncul
# BELAKANGAN dan di halaman yang tidak berhubungan dengan perubahan
# terakhir, sehingga mahal dilacak.
#
# BATAS OBAT INI: penyembuhan terjadi saat container START. Bila selama
# sesi berjalan ada perintah yang tidak sengaja dijalankan sebagai root,
# halaman bisa mati sampai container di-restart. Penawarnya:
#
#   docker compose restart php-fpm
#   ...atau langsung: docker compose exec -T php-fpm \
#        chown -R www-data:www-data storage bootstrap/cache
#
# Cara menghindarinya sejak awal: pakai ./bin/artisan (lihat berkas itu),
# atau selalu sertakan `-u www-data` pada docker compose exec.

set -e

for dir in storage bootstrap/cache; do
    if [ -d "/var/www/html/$dir" ]; then
        chown -R www-data:www-data "/var/www/html/$dir" 2>/dev/null || true
    fi
done

# Diteruskan ke entrypoint bawaan image php resmi, bukan langsung ke "$@" —
# entrypoint itulah yang menangani argumen bergaya `php ...` dan penyiapan
# lain milik image. Melewatinya berarti diam-diam mengubah perilaku image.
exec docker-php-entrypoint "$@"
