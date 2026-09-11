<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dua penanda stok yang diminta pemilik produk: Formula Lama & Karantina.
 *
 * CATATAN: kolom `is_old_formula` kemudian di-rename jadi `has_quality_issue`
 * oleh 2026_09_25_000001. Migration ini SENGAJA dibiarkan apa adanya — ia
 * sudah pernah dijalankan di data nyata, jadi mengubahnya di sini hanya akan
 * membuat riwayat migrasi berbeda dari yang sungguh-sungguh terjadi.
 *
 * KEDUANYA SENGAJA DIBEDAKAN, bukan disatukan jadi satu kolom "status" umum:
 *
 *   FORMULA LAMA — murni informasi. Stok tetap 'active', tetap ikut FIFO,
 *   tidak menghalangi penjualan sama sekali. Cuma penanda supaya Logistik
 *   tahu batch mana yang dibuat dengan formula sebelum ada pembaruan resep.
 *
 *   KARANTINA — penahanan SEMENTARA berbasis hari, bukan penandaan permanen
 *   seperti DDP. Dipasang Logistik setelah QC selesai memeriksa (biasanya
 *   1-2 hari setelah produksi naik rak), dan LEPAS SENDIRI begitu jangka
 *   waktunya lewat — tidak seperti DDP yang harus dikeluarkan manual oleh
 *   Manager/Super Admin lewat Stock Adjustment.
 *
 * KARANTINA DIMODELKAN SEBAGAI STATUS KETIGA (bukan flag terpisah dari
 * `status` yang sudah ada), supaya jalur alokasi yang SUDAH ADA otomatis
 * ikut benar tanpa disentuh: FifoAllocator dan Shipment::keluarkanKekurangan()
 * menyaring `status = 'active'` secara langsung, sehingga batch berstatus
 * 'quarantine' otomatis terlewati dari FIFO — persis "masih boleh dijual
 * tapi harus nunggu" tanpa perlu mengubah satu pun query alokasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            // Batch, bukan baris. Satu batch bisa terpisah di beberapa rak;
            // menandai formula pada satu baris saja akan membuat sisa rak
            // dari batch yang sama terlihat "formula baru" padahal produksinya
            // sama persis.
            $table->boolean('is_old_formula')->default(false)->after('ddp_reason');

            $table->unsignedSmallInteger('quarantine_days')->nullable()->after('is_old_formula');
            $table->date('quarantine_until')->nullable()->after('quarantine_days');
            $table->timestamp('quarantined_at')->nullable()->after('quarantine_until');
            $table->foreignId('quarantined_by')->nullable()->after('quarantined_at')
                ->constrained('users')->nullOnDelete();
            $table->text('quarantine_note')->nullable()->after('quarantined_by');

            // Diisi saat karantina berakhir — baik oleh sweep harian maupun
            // dibatalkan manual. Kolom yang lain (until/days/at/by) SENGAJA
            // TIDAK dikosongkan saat berakhir: itu jejak "batch ini pernah
            // dikarantina dan kapan", yang hilang kalau ditimpa null.
            $table->timestamp('quarantine_released_at')->nullable()->after('quarantine_note');

            $table->index(['status', 'quarantine_until']);
        });

        // Metadata karantina wajib lengkap SELAMA statusnya masih 'quarantine'.
        // Begitu dilepas, status kembali 'active' dan constraint ini otomatis
        // tidak lagi memeriksa barisnya — kolom historisnya boleh tetap terisi.
        DB::statement("
            ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_karantina_lengkap
            CHECK (
                (status = 'quarantine' AND quarantine_until IS NOT NULL
                    AND quarantined_by IS NOT NULL AND quarantined_at IS NOT NULL
                    AND quarantine_released_at IS NULL)
                OR (status <> 'quarantine')
            )
        ");

        DB::statement('
            ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_karantina_hari_positif
            CHECK (quarantine_days IS NULL OR quarantine_days > 0)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_stocks DROP CONSTRAINT IF EXISTS inventory_stocks_karantina_hari_positif');
        DB::statement('ALTER TABLE inventory_stocks DROP CONSTRAINT IF EXISTS inventory_stocks_karantina_lengkap');

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quarantined_by');
            $table->dropColumn([
                'is_old_formula', 'quarantine_days', 'quarantine_until',
                'quarantined_at', 'quarantine_note', 'quarantine_released_at',
            ]);
        });
    }
};
