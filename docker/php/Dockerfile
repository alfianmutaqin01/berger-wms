# ==============================================================
# Berger WMS — PHP-FPM Dockerfile (Multi-Stage Build)
# ==============================================================
#
# TIDAK ADA TAHAP NODE/VITE. Seluruh halaman memuat Bootstrap, ikon, dan
# SweetAlert dari CDN ditambah public/css/soms-style.css — tidak satu view pun
# memanggil @vite. Tahap build frontend yang dulu ada di sini hanya
# menambah waktu build dan menarik paket (Echo, Pusher) yang tidak dipakai.

# --- Stage 1: Composer Dependencies ---
FROM composer:2.8 AS composer-deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

# --- Stage 2: Development Image ---
FROM php:8.3-fpm-alpine AS development

RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip unzip curl

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql gd zip pcntl bcmath opcache

RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html
EXPOSE 9000
CMD ["php-fpm"]

# --- Stage 3: Production Image ---
FROM development AS production

# Kode tidak berubah selama container hidup, jadi OPcache tidak perlu
# memeriksa mtime berkas di tiap permintaan. Ditimpa di sini, bukan di
# php.ini bersama, karena di pengembangan pemeriksaan itulah yang membuat
# perubahan kode langsung terlihat.
COPY docker/php/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini

COPY --from=composer-deps /app/vendor /var/www/html/vendor
COPY . /var/www/html

# public/storage tidak ikut repositori (dibuat `storage:link`). Tanpa tautan
# ini foto profil pengguna tidak tampil di production.
RUN ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache \
    && chmod +x /var/www/html/docker/php/prod-entrypoint.sh

ENTRYPOINT ["/bin/sh", "/var/www/html/docker/php/prod-entrypoint.sh"]
CMD ["php-fpm"]
