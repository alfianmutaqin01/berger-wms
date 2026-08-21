# ==============================================================
# Berger WMS â€” PHP-FPM Dockerfile (Multi-Stage Build)
# ==============================================================

# --- Stage 1: Composer Dependencies ---
FROM composer:2.8 AS composer-deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

# --- Stage 2: Frontend Assets ---
FROM node:20-alpine AS frontend-build
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --ignore-scripts 2>/dev/null || npm install
COPY . .
RUN npm run build

# --- Stage 3: Development Image ---
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

# --- Stage 4: Production Image ---
FROM development AS production

COPY --from=composer-deps /app/vendor /var/www/html/vendor
COPY --from=frontend-build /app/public/build /var/www/html/public/build
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

CMD ["php-fpm"]
