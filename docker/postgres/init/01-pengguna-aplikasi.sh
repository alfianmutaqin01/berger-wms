#!/bin/sh
#
# Pengguna basis data untuk APLIKASI — bukan superuser.
#
# Dijalankan image postgres SEKALI, saat volume pg_data masih kosong
# (docker-entrypoint-initdb.d). POSTGRES_USER dari .env.postgres menjadi
# superuser yang hanya dipakai container `backup` dan pemeliharaan manual;
# aplikasi masuk sebagai DB_USERNAME yang dibuat di sini.
#
# KENAPA DIPISAH
# --------------
# Sebelumnya DB_USERNAME aplikasi adalah POSTGRES_USER, yaitu superuser.
# Aplikasi yang tembus (satu celah injeksi, satu dependensi yang disusupi)
# lalu membawa seluruh server PostgreSQL: membaca berkas server, dan
# menjalankan perintah sistem lewat COPY ... TO PROGRAM. Sebagai pemilik
# basis data biasa, yang terbawa hanya tabel WMS.
#
# Pemilik basis data cukup untuk `php artisan migrate`: di PostgreSQL 15+
# skema public dimiliki pg_database_owner, jadi pemilik basis data boleh
# membuat tabel di sana. Aplikasi ini tidak memakai ekstensi yang menuntut
# superuser.
#
# Volume yang SUDAH berisi tidak menjalankan skrip ini. Untuk server yang
# terlanjur dipasang dengan superuser, lihat docs/9_panduan_go_live.md §4.

set -eu

: "${WMS_DB_USER:?WMS_DB_USER (DB_USERNAME) wajib diisi}"
: "${WMS_DB_PASSWORD:?WMS_DB_PASSWORD (DB_PASSWORD) wajib diisi}"

if [ "$WMS_DB_USER" = "$POSTGRES_USER" ]; then
    echo "DB_USERNAME di .env tidak boleh sama dengan POSTGRES_USER di .env.postgres." >&2
    exit 1
fi

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v pengguna="$WMS_DB_USER" -v sandi="$WMS_DB_PASSWORD" -v basisdata="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"pengguna" LOGIN PASSWORD :'sandi'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER DATABASE :"basisdata" OWNER TO :"pengguna";
REVOKE ALL ON DATABASE :"basisdata" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"basisdata" TO :"pengguna";
SQL

echo "Pengguna aplikasi $WMS_DB_USER dibuat (bukan superuser) dan menjadi pemilik $POSTGRES_DB."
