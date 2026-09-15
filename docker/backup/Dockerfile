# Container cadangan: klien PostgreSQL (versi sama dengan server) + age.
#
# age mengenkripsi cadangan dengan KUNCI PUBLIK. Server hanya memegang kunci
# publik, jadi penyerang yang menguasai VPS bisa menghapus cadangan tetapi
# tidak bisa membaca isinya; kunci privat untuk memulihkan disimpan di luar
# server. Lihat docs/9_panduan_go_live.md §9.
FROM postgres:16-alpine

RUN apk add --no-cache age
