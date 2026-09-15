# ==============================================================
# Berger WMS — Nginx untuk production
# ==============================================================
#
# Berkas statis (CSS, gambar) DISALIN ke dalam image. Di pengembangan nginx
# membaca folder proyek lewat bind mount; di production tidak ada folder
# proyek di server yang boleh dibaca langsung — yang berjalan adalah image
# hasil build untuk versi yang di-deploy.
#
# Dibangun dari akar repositori:
#   docker build -f docker/nginx/Dockerfile .

FROM nginx:1.27-alpine

RUN rm /etc/nginx/conf.d/default.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

COPY public /var/www/html/public

# Foto profil disajikan dari volume storage yang dipasang read-only di
# /var/www/html/storage (lihat docker-compose.prod.yml).
RUN rm -rf /var/www/html/public/storage \
    && ln -s /var/www/html/storage/app/public /var/www/html/public/storage

EXPOSE 80
