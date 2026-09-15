#!/bin/sh
#
# Memulihkan cadangan Berger WMS ke basis data yang SEDANG DIPAKAI.
#
#   docker compose -f docker-compose.prod.yml exec backup \
#       sh /skrip/pulihkan.sh 2026-10-01
#
# Isi basis data sekarang DIGANTI seluruhnya dengan isi cadangan tanggal itu.
# Hentikan dulu aplikasinya supaya tidak ada transaksi yang masuk di tengah
# pemulihan:
#
#   docker compose -f docker-compose.prod.yml stop php-fpm queue scheduler
#
# Untuk UJI PULIH (tanpa menyentuh basis data produksi) pakai:
#
#   docker compose -f docker-compose.prod.yml exec backup \
#       sh /skrip/pulihkan.sh 2026-10-01 --uji

set -eu

tanggal="${1:?Pakai: pulihkan.sh YYYY-MM-DD [--uji]}"
mode="${2:-}"
dump="/backups/db/wms-$tanggal.dump"
arsip="/backups/berkas/storage-$tanggal.tgz"

[ -f "$dump" ] || { echo "Tidak ada $dump"; exit 1; }

if [ "$mode" = "--uji" ]; then
    uji="wms_uji_pulih"
    echo "Uji pulih ke basis data sementara $uji…"
    dropdb --if-exists "$uji"
    createdb "$uji"
    pg_restore --no-owner --dbname="$uji" "$dump"
    psql --dbname="$uji" -Atc "SELECT 'users: ' || count(*) FROM users UNION ALL SELECT 'sales_orders: ' || count(*) FROM sales_orders UNION ALL SELECT 'migrations: ' || count(*) FROM migrations"
    dropdb "$uji"
    [ -f "$arsip" ] && tar -tzf "$arsip" > /dev/null && echo "Arsip berkas terbaca: $arsip"
    echo "UJI PULIH BERHASIL."
    exit 0
fi

echo "Basis data $DB_DATABASE akan DIGANTI dengan cadangan $tanggal."
printf 'Ketik PULIHKAN untuk melanjutkan: '
read -r jawab
[ "$jawab" = "PULIHKAN" ] || { echo "Dibatalkan."; exit 1; }

pg_restore --clean --if-exists --no-owner --dbname="$DB_DATABASE" "$dump"

if [ -f "$arsip" ]; then
    tar -xzf "$arsip" -C /data
    chown -R 82:82 /data/storage/app
    echo "Berkas unggahan dipulihkan."
fi

echo "Selesai. Jalankan lagi aplikasinya:"
echo "  docker compose -f docker-compose.prod.yml up -d"
