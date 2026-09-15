#!/bin/sh
#
# Entrypoint production: menyiapkan cache Laravel lalu menjalankan perintahnya
# (php-fpm, queue:work, atau loop scheduler — lihat docker-compose.prod.yml).
#
# KENAPA CACHE DIBANGUN DI SINI, BUKAN DI DOCKERFILE
# --------------------------------------------------
# config:cache membekukan isi .env. .env berisi rahasia dan sengaja TIDAK ikut
# image (lihat .dockerignore) — ia dipasang dari server saat container jalan.
# Membangun cache di Dockerfile berarti membekukan konfigurasi kosong.
#
# Cache dibangun ulang tiap container start, jadi mengubah .env cukup diikuti
# `docker compose -f docker-compose.prod.yml up -d --force-recreate`.

set -e

cd /var/www/html

if [ ! -f .env ]; then
    echo "[entrypoint] .env tidak ditemukan di /var/www/html/.env — pasang lewat volume." >&2
    exit 1
fi

# Volume storage bisa saja dibuat pertama kali oleh container lain yang tidak
# membawa kerangka foldernya (mis. `backup`). Laravel mati tanpa folder ini.
mkdir -p storage/app/public storage/app/private storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage
fi

# SELALU sebagai www-data. php-fpm berjalan sebagai root (master-nya yang
# menurunkan hak worker), dan berkas cache milik root di volume storage yang
# DIPAKAI BERSAMA queue dan scheduler membuat keduanya mati dengan
# "Permission denied" / "touch(): Utime failed".
artisan() {
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh www-data -c "php artisan $*"
    else
        php artisan "$@"
    fi
}

artisan config:cache --no-interaction
artisan route:cache --no-interaction
artisan event:cache --no-interaction

# View dikompilasi ke volume bersama; cukup satu container yang melakukannya
# supaya tiga container tidak menulis berkas yang sama bersamaan.
if [ "$1" = "php-fpm" ]; then
    artisan view:cache --no-interaction
fi

exec docker-php-entrypoint "$@"
