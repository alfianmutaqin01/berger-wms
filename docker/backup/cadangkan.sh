#!/bin/sh
#
# Cadangan harian Berger WMS: basis data + berkas unggahan, TERENKRIPSI.
#
# Berjalan terus di container `backup` (docker-compose.prod.yml). Sekali
# sehari setelah BACKUP_JAM (WIB) ia membuat:
#
#   /backups/db/wms-YYYY-MM-DD.dump.age         pg_dump format custom
#   /backups/berkas/storage-YYYY-MM-DD.tgz.age  foto surat jalan, foto sampai,
#                                               dokumen PO, foto profil
#
# lalu menghapus cadangan yang lebih tua dari BACKUP_HARI (bawaan 30).
#
# KENAPA DIENKRIPSI DENGAN KUNCI PUBLIK
# -------------------------------------
# Isinya data operasional lengkap: pelanggan, nomor HP, hash sandi, foto
# dokumen bertanda tangan. Cadangan ini disalin ke luar server — ke tempat
# yang pengamanannya tidak kita kendalikan. age mengenkripsi dengan
# BACKUP_KUNCI_PUBLIK; kunci privat pasangannya TIDAK PERNAH tinggal di
# server. Penyerang yang menguasai VPS bisa menghapus cadangan, tetapi tidak
# bisa membacanya — dan salinan di luar server tetap aman walau tercecer.
#
# Tanpa BACKUP_KUNCI_PUBLIK skrip ini BERHENTI, bukan diam-diam menulis
# cadangan telanjang.
#
# KENAPA BERKAS IKUT DICADANGKAN
# ------------------------------
# Basis data hanya menyimpan JALUR foto. Memulihkan basis data tanpa
# berkasnya menghasilkan pesanan COMPLETED yang bukti Surat Jalannya hilang —
# padahal foto itulah alasan pesanan boleh ditutup.
#
# KENAPA TIAP DUMP DIBACA ULANG SEBELUM DIENKRIPSI
# ------------------------------------------------
# Cadangan yang tidak pernah dicoba dipulihkan hanya harapan. `pg_restore
# --list` membaca seluruh daftar isi dump; berkas terpotong (disk penuh,
# koneksi putus) gagal di sini, pada hari yang sama, bukan pada hari ia
# dibutuhkan. Setelah dienkripsi, server sendiri tidak bisa lagi membacanya.
#
# Salinan mentahnya hanya ada sebentar di /tmp DI DALAM container (bukan di
# folder backups milik host) dan dihapus apa pun hasilnya.

set -u
umask 077

# Tanpa tzdata: string POSIX "WIB-7" berarti UTC+7.
export TZ="${TZ:-WIB-7}"

# Superuser dari .env.postgres: pg_dump membaca seluruh basis data, dan uji
# pulih membuat basis data sementara.
export PGUSER="${POSTGRES_USER:?POSTGRES_USER wajib ada (env_file .env.postgres)}"
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD wajib ada (env_file .env.postgres)}"

BACKUP_JAM="${BACKUP_JAM:-01}"
BACKUP_HARI="${BACKUP_HARI:-30}"

case "${BACKUP_KUNCI_PUBLIK:-}" in
    age1*) ;;
    *)
        echo "BACKUP_KUNCI_PUBLIK kosong atau bukan kunci publik age (age1...)." >&2
        echo "Cadangan TIDAK dibuat. Lihat docs/9_panduan_go_live.md §9." >&2
        exit 1
        ;;
esac

mkdir -p /backups/db /backups/berkas

log() { echo "[cadangan $(date '+%F %T')] $*"; }

# SETIAP LANGKAH DIAKHIRI `|| return 1`, tidak mengandalkan `set -e`.
# Pemanggilan `cadangkan || log ...` di perulangan bawah membuat shell
# MENGABAIKAN set -e di dalam fungsi — tanpa return eksplisit, dump yang gagal
# dibaca ulang tetap dienkripsi dan dipindahkan seolah cadangan yang sah.
cadangkan() {
    tanggal=$(date +%F)
    kerja=$(mktemp -d) || return 1

    dump="/backups/db/wms-$tanggal.dump.age"
    arsip="/backups/berkas/storage-$tanggal.tgz.age"

    if ! buat_cadangan; then
        rm -rf "$kerja" "$dump.tmp" "$arsip.tmp"
        return 1
    fi

    rm -rf "$kerja"

    find /backups/db /backups/berkas -type f -mtime +"$BACKUP_HARI" -print -delete

    log "selesai ($(du -h "$dump" | cut -f1) basis data, $(du -h "$arsip" | cut -f1) berkas)"
}

buat_cadangan() {
    log "basis data: $dump"
    pg_dump --format=custom --no-owner --file="$kerja/wms.dump" "$DB_DATABASE" || return 1
    pg_restore --list "$kerja/wms.dump" > /dev/null || return 1
    age --encrypt --recipient "$BACKUP_KUNCI_PUBLIK" --output "$dump.tmp" "$kerja/wms.dump" || return 1

    log "berkas unggahan: $arsip"
    tar -czf "$kerja/storage.tgz" -C /data storage/app || return 1
    tar -tzf "$kerja/storage.tgz" > /dev/null || return 1
    age --encrypt --recipient "$BACKUP_KUNCI_PUBLIK" --output "$arsip.tmp" "$kerja/storage.tgz" || return 1

    # Dipindahkan berdua di akhir: tidak ada hari dengan dump baru tetapi
    # arsip berkas lama, atau sebaliknya.
    mv "$dump.tmp" "$dump" && mv "$arsip.tmp" "$arsip"
}

if [ "${1:-}" = "sekarang" ]; then
    # Keluar tidak-nol bila gagal: pipeline deploy berhenti sebelum migrasi.
    cadangkan || { log "GAGAL"; exit 1; }
    exit 0
fi

log "siaga — cadangan harian terenkripsi setelah pukul $BACKUP_JAM:00 WIB, disimpan $BACKUP_HARI hari"

while true; do
    tanggal=$(date +%F)
    jam=$(date +%H)

    # Diperiksa tiap 10 menit, bukan menunggu satu menit tertentu: VPS yang
    # restart pukul 01:00 tetap membuat cadangan hari itu begitu hidup lagi.
    if [ ! -f "/backups/db/wms-$tanggal.dump.age" ] && [ "$jam" -ge "$BACKUP_JAM" ]; then
        cadangkan || log "GAGAL — dicoba lagi 10 menit lagi"
    fi

    sleep 600
done
