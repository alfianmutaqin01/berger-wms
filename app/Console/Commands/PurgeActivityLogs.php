<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus log aktivitas yang umurnya sudah lewat batas simpan.
 *
 * Keputusan pemilik produk: log hilang otomatis setelah 90 hari. Angkanya ada
 * di ActivityLog::UMUR_SIMPAN_HARI supaya halaman log bisa mengatakannya
 * kepada pembaca — orang yang mencari kejadian empat bulan lalu berhak tahu
 * bahwa yang ia cari memang sudah tidak ada, bukan menyimpulkan sendiri bahwa
 * kejadiannya tidak pernah tercatat.
 *
 * SATU-SATUNYA PENGECUALIAN DARI APPEND-ONLY, dan bentuknya sengaja dibatasi.
 * ActivityLog::booted() melempar RuntimeException pada setiap delete lewat
 * model; perintah ini menembus lewat query builder, yang berarti ia HANYA
 * bisa menghapus menurut UMUR. Tidak ada jalan menghapus satu baris tertentu
 * dari mana pun di sistem — dan itu memang yang mau dijaga: yang berbahaya
 * bukan pembersihan berkala, melainkan orang yang menghilangkan jejak
 * dirinya sendiri.
 *
 * DIHAPUS PER POTONG, bukan satu DELETE raksasa. Tabel ini tumbuh terus dan
 * satu perintah yang mengunci ratusan ribu baris sekaligus akan menahan
 * penulisan log dari seluruh aplikasi selama ia berjalan — persis pada tabel
 * yang tidak boleh menolak tulisan.
 */
class PurgeActivityLogs extends Command
{
    protected $signature = 'activity:purge
        {--days= : Umur maksimal dalam hari (default ActivityLog::UMUR_SIMPAN_HARI)}
        {--dry-run : Hitung saja, tidak menghapus}';

    protected $description = 'Menghapus log aktivitas yang lebih tua daripada batas simpan';

    /** Sekali hapus, supaya kuncinya tidak dipegang lama-lama. */
    private const POTONG = 1000;

    public function handle(): int
    {
        $hari = (int) ($this->option('days') ?: ActivityLog::UMUR_SIMPAN_HARI);

        if ($hari < 1) {
            $this->error('Umur simpan minimal 1 hari.');

            return self::FAILURE;
        }

        $batas = now()->subDays($hari);

        $calon = DB::table('activity_logs')->where('created_at', '<', $batas);

        $jumlah = (clone $calon)->count();

        if ($jumlah === 0) {
            $this->info(sprintf('Tidak ada log lebih tua daripada %s.', $batas->format('d M Y H:i')));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn(sprintf('%d log akan dihapus (lebih tua daripada %s).', $jumlah, $batas->format('d M Y H:i')));

            return self::SUCCESS;
        }

        $terhapus = 0;

        /*
         * Dipotong lewat SUBQUERY ID, bukan `->limit()->delete()`.
         * PostgreSQL tidak mengenal DELETE ... LIMIT, dan Laravel diam saja
         * menjatuhkan limitnya — yang tersisa satu DELETE raksasa persis
         * seperti yang mau dihindari, tanpa satu pun tanda bahwa potongannya
         * tidak berlaku.
         */
        do {
            $id = DB::table('activity_logs')
                ->where('created_at', '<', $batas)
                ->limit(self::POTONG)
                ->pluck('id');

            $kena = $id->isEmpty()
                ? 0
                : DB::table('activity_logs')->whereIn('id', $id)->delete();

            $terhapus += $kena;
        } while ($kena > 0);

        $this->info(sprintf(
            '%d log dihapus (lebih tua daripada %s, batas simpan %d hari).',
            $terhapus,
            $batas->format('d M Y H:i'),
            $hari,
        ));

        return self::SUCCESS;
    }
}
