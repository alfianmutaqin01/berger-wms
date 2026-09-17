<?php

use App\Models\ActivityLog;
use App\Support\JenisTransaksi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor dokumen disalin ke barisnya sendiri di log aktivitas.
 *
 * Sebelumnya log hanya menyimpan subject_type/subject_id, sehingga nomor
 * transaksinya cuma ada di dalam kalimat keterangan — bisa dibaca mata, tidak
 * bisa disaring, dan hilang sama sekali begitu dokumennya dihapus. Yang
 * ditanyakan auditor justru "tunjukkan semua jejak MRF2609001", dan pertanyaan
 * itu harus dijawab satu query, bukan dengan membaca ratusan kalimat.
 *
 * DISALIN, BUKAN DI-JOIN. Alasannya sama dengan user_name yang sudah lebih
 * dulu disalin di tabel ini: log harus tetap terbaca sebagaimana keadaannya
 * saat kejadian, termasuk ketika dokumen yang diacunya sudah tidak ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('reference_number', 50)->nullable()->after('subject_id');
            $table->index('reference_number');
        });

        $this->isiDataLama();
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['reference_number']);
            $table->dropColumn('reference_number');
        });
    }

    /**
     * Baris lama diisi dari dokumen yang masih ada.
     *
     * Dikerjakan per jenis subjek, bukan per baris log: satu jenis berarti satu
     * query dokumen, dan log yang sudah berjalan setahun bisa berisi puluhan
     * ribu baris. Dokumen yang sudah hilang membuat nomornya tetap null —
     * memang tidak ada lagi yang bisa dipulihkan, dan menebak lebih buruk
     * daripada kosong.
     */
    private function isiDataLama(): void
    {
        $jenisDipakai = ActivityLog::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->pluck('subject_type');

        foreach ($jenisDipakai as $kelas) {
            $kolom = JenisTransaksi::untuk($kelas)['kolom'] ?? null;

            if ($kolom === null || ! class_exists($kelas)) {
                continue;
            }

            $nomor = $kelas::query()
                ->whereIn('id', ActivityLog::where('subject_type', $kelas)->distinct()->pluck('subject_id'))
                ->pluck($kolom, 'id');

            foreach ($nomor as $id => $teks) {
                if (blank($teks)) {
                    continue;
                }

                // Lewat query builder, jadi penjagaan append-only di model
                // tidak dilanggar: yang diisi kolom baru yang sebelumnya
                // memang belum ada, bukan mengubah apa yang sudah tercatat.
                ActivityLog::where('subject_type', $kelas)
                    ->where('subject_id', $id)
                    ->update(['reference_number' => trim((string) $teks)]);
            }
        }

        $this->isiYangLewatRelasi($jenisDipakai);
    }

    /**
     * Subjek yang nomornya ada di dokumen INDUKNYA, bukan pada barisnya.
     *
     * Material di tangan divisi menyebut nomor MRF-nya, baris retur menyebut
     * nomor returnya. Keduanya justru yang paling sering ditelusuri, jadi
     * membiarkannya kosong berarti melubangi jejak tepat di tempat yang
     * dipakai. Jumlahnya sedikit, jadi ditempuh baris per baris lewat
     * JenisTransaksi::nomor() — satu sumber aturan dengan pencatatnya.
     *
     * @param  Collection<int, string>  $jenisDipakai
     */
    private function isiYangLewatRelasi($jenisDipakai): void
    {
        $lewatRelasi = $jenisDipakai->filter(
            fn (string $kelas) => class_exists($kelas)
                && isset(JenisTransaksi::DAFTAR[$kelas])
                && JenisTransaksi::DAFTAR[$kelas]['kolom'] === null,
        );

        foreach ($lewatRelasi as $kelas) {
            $dokumen = $kelas::query()
                ->whereIn('id', ActivityLog::where('subject_type', $kelas)->distinct()->pluck('subject_id'))
                ->get();

            foreach ($dokumen as $satu) {
                $nomor = JenisTransaksi::nomor($satu);

                if ($nomor === null) {
                    continue;
                }

                ActivityLog::where('subject_type', $kelas)
                    ->where('subject_id', $satu->getKey())
                    ->update(['reference_number' => $nomor]);
            }
        }
    }
};
