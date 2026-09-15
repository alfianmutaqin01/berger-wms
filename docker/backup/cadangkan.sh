#!/bin/sh
#
# Cadangan harian Berger WMS: basis data + berkas unggahan.
#
# Berjalan terus di container `backup` (docker-compose.prod.yml). Sekali
# sehari setelah BACKUP_JAM (WIB) ia membuat:
#
#   /backups/db/wms-YYYY-MM-DD.dump        pg_dump format custom (terkompresi)
#   /backups/berkas/storage-YYYY-MM-DD.tgz foto surat jalan, foto sampai,
#                                          dokumen PO, foto profil
#
# lalu menghapus cadangan yang lebih tua dari BACKUP_HARI (bawaan 30).
#
# KENAPA BERKAS IKUT DICADANGKAN
# ------------------------------
# Basis data hanya menyimpan JALUR foto. Memulihkan basis data tanpa
# berkasnya menghasilkan pesanan COMPLETED yang bukti Surat Jalannya hilang —
# padahal foto itulah alasan pesanan boleh ditutup.
#
# KENAPA TIAP DUMP LANGSUNG DIBACA ULANG
# --------------------------------------
# Cadangan yang tidak pernah dicoba dipulihkan hanya harapan. `pg_restore
# --list` membaca seluruh daftar isi dump; berkas terpotong (disk penuh,
# koneksi putus) gagal di sini, pada hari yang sama, bukan pada hari ia
# dibutuhkan.
#
# Cadangan di disk yang sama dengan basis data TIDAK melindungi dari VPS yang
# rusak atau terhapus. Salin /backups ke tempat lain secara berkala — lihat
# docs/9_panduan_go_live.md.

set -eu

# Tanpa tzdata: string POSIX "WIB-7" berarti UTC+7.
export TZ="${TZ:-WIB-7}"

BACKUP_JAM="${BACKUP_JAM:-01}"
BACKUP_HARI="${BACKUP_HARI:-30}"

mkdir -p /backups/db /backups/berkas

log() { echo "[cadangan $(date '+%F %T')] $*"; }

cadangkan() {
    tanggal=$(date +%F)
    dump="/backups/db/wms-$tanggal.dump"
    arsip="/backups/berkas/storage-$tanggal.tgz"

    log "mulai: $dump"
    pg_dump --format=custom --no-owner --file="$dump.tmp" "$DB_DATABASE"
    pg_restore --list "$dump.tmp" > /dev/null
    mv "$dump.tmp" "$dump"

    log "berkas unggahan: $arsip"
    tar -czf "$arsip.tmp" -C /data storage/app
    tar -tzf "$arsip.tmp" > /dev/null
    mv "$arsip.tmp" "$arsip"

    find /backups/db /backups/berkas -type f -mtime +"$BACKUP_HARI" -print -delete

    log "selesai ($(du -h "$dump" | cut -f1) basis data, $(du -h "$arsip" | cut -f1) berkas)"
}

if [ "${1:-}" = "sekarang" ]; then
    cadangkan
    exit 0
fi

log "siaga — cadangan harian setelah pukul $BACKUP_JAM:00 WIB, disimpan $BACKUP_HARI hari"

while true; do
    tanggal=$(date +%F)
    jam=$(date +%H)

    # Diperiksa tiap 10 menit, bukan menunggu satu menit tertentu: VPS yang
    # restart pukul 01:00 tetap membuat cadangan hari itu begitu hidup lagi.
    if [ ! -f "/backups/db/wms-$tanggal.dump" ] && [ "$jam" -ge "$BACKUP_JAM" ]; then
        cadangkan || log "GAGAL — dicoba lagi 10 menit lagi"
    fi

    sleep 600
done
