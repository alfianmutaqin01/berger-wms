#!/bin/sh
#
# Memulihkan cadangan Berger WMS (terenkripsi age) ke basis data yang SEDANG
# DIPAKAI.
#
# KUNCI PRIVAT DIBUTUHKAN, dan sengaja tidak tinggal di server. Taruh
# sementara di ./kunci-pemulihan/cadangan.key (di-mount read-only ke /kunci),
# jalankan, lalu HAPUS lagi:
#
#   install -m 600 /dev/stdin kunci-pemulihan/cadangan.key   # tempel, Ctrl+D
#   docker compose -f docker-compose.prod.yml exec backup \
#       sh /skrip/pulihkan.sh 2026-10-01
#   shred -u kunci-pemulihan/cadangan.key
#
# Isi basis data sekarang DIGANTI seluruhnya dengan isi cadangan tanggal itu.
# Hentikan dulu aplikasinya supaya tidak ada transaksi yang masuk di tengah
# pemulihan:
#
#   docker compose -f docker-compose.prod.yml stop php-fpm queue scheduler
#
# Untuk UJI PULIH (tanpa menyentuh basis data produksi) tambahkan --uji.

set -eu
umask 077

export PGUSER="${POSTGRES_USER:?POSTGRES_USER wajib ada (env_file .env.postgres)}"
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD wajib ada (env_file .env.postgres)}"

tanggal="${1:?Pakai: pulihkan.sh YYYY-MM-DD [--uji]}"
mode="${2:-}"
kunci="${BACKUP_KUNCI_PRIVAT:-/kunci/cadangan.key}"
dump="/backups/db/wms-$tanggal.dump.age"
arsip="/backups/berkas/storage-$tanggal.tgz.age"

[ -f "$dump" ] || { echo "Tidak ada $dump"; exit 1; }
[ -f "$kunci" ] || { echo "Kunci privat tidak ada di $kunci — lihat keterangan di awal skrip ini."; exit 1; }

kerja=$(mktemp -d)
trap 'rm -rf "$kerja"' EXIT INT TERM

age --decrypt --identity "$kunci" --output "$kerja/wms.dump" "$dump"
[ -f "$arsip" ] && age --decrypt --identity "$kunci" --output "$kerja/storage.tgz" "$arsip"

if [ "$mode" = "--uji" ]; then
    uji="wms_uji_pulih"
    echo "Uji pulih ke basis data sementara $uji…"
    # Basis data sementara ikut dibuang bila uji gagal di tengah jalan.
    trap 'dropdb --if-exists "$uji" >/dev/null 2>&1; rm -rf "$kerja"' EXIT INT TERM
    dropdb --if-exists "$uji"
    createdb "$uji"
    pg_restore --no-owner --dbname="$uji" "$kerja/wms.dump"
    psql --dbname="$uji" -Atc "SELECT 'users: ' || count(*) FROM users UNION ALL SELECT 'sales_orders: ' || count(*) FROM sales_orders UNION ALL SELECT 'migrations: ' || count(*) FROM migrations"
    dropdb "$uji"
    [ -f "$kerja/storage.tgz" ] && tar -tzf "$kerja/storage.tgz" > /dev/null && echo "Arsip berkas terbaca: $arsip"
    echo "UJI PULIH BERHASIL."
    exit 0
fi

echo "Basis data $DB_DATABASE akan DIGANTI dengan cadangan $tanggal."
printf 'Ketik PULIHKAN untuk melanjutkan: '
read -r jawab
[ "$jawab" = "PULIHKAN" ] || { echo "Dibatalkan."; exit 1; }

# --role: objek dibuat sebagai pengguna aplikasi, bukan milik superuser —
# kalau tidak, aplikasi kehilangan hak atas tabelnya sendiri setelah pulih.
pg_restore --clean --if-exists --no-owner --role="$DB_USERNAME" --dbname="$DB_DATABASE" "$kerja/wms.dump"

if [ -f "$kerja/storage.tgz" ]; then
    tar -xzf "$kerja/storage.tgz" -C /data
    chown -R 82:82 /data/storage/app
    echo "Berkas unggahan dipulihkan."
fi

echo "Selesai. Jalankan lagi aplikasinya:"
echo "  docker compose -f docker-compose.prod.yml up -d"
echo "Lalu HAPUS kunci privatnya dari server: shred -u kunci-pemulihan/cadangan.key"
