<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Sampai wajib berfoto" pindah dari CHECK ... NOT VALID ke trigger — temuan SQA.
 *
 * Migrasi 2026_10_04_000001 memasang CHECK NOT VALID dengan niat yang benar:
 * surat jalan yang dikonfirmasi SEBELUM foto diwajibkan dibiarkan apa
 * adanya. Tetapi PostgreSQL tidak bekerja begitu. NOT VALID hanya melewati
 * pemeriksaan baris lama SAAT constraint dipasang; setiap UPDATE berikutnya
 * pada baris itu diperiksa penuh. Akibatnya surat jalan lama tidak bisa
 * diubah SAMA SEKALI — bahkan menekan "Kirim ulang" WhatsApp-nya (yang hanya
 * menyentuh notify_status) dijawab galat 500. Pengujian SQA menemukannya pada
 * data pengembangan.
 *
 * Trigger ini menegakkan aturan yang sebenarnya dimaksud: yang ditolak adalah
 * PERUBAHAN MENUJU "sampai tanpa foto" — baris baru, status yang berubah
 * menjadi delivered, atau foto yang dihapus dari surat jalan yang sudah
 * sampai. Baris lama yang memang tidak pernah punya foto tetap bisa diubah
 * kolom lainnya.
 *
 * Kode galatnya 23514 (check_violation), sama dengan CHECK sebelumnya, supaya
 * penangkap galat mana pun yang mengenali pelanggaran constraint tetap bekerja.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS delivery_notes_sampai_wajib_berfoto');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION delivery_notes_sampai_wajib_berfoto() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'delivered'
                   AND NEW.arrival_photo_path IS NULL
                   AND (
                       TG_OP = 'INSERT'
                       OR OLD.status IS DISTINCT FROM 'delivered'
                       OR OLD.arrival_photo_path IS NOT NULL
                   )
                THEN
                    RAISE EXCEPTION 'delivery_notes_sampai_wajib_berfoto: surat jalan % tidak boleh berstatus delivered tanpa foto sampai', NEW.document_no
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER delivery_notes_sampai_wajib_berfoto
                BEFORE INSERT OR UPDATE ON delivery_notes
                FOR EACH ROW EXECUTE FUNCTION delivery_notes_sampai_wajib_berfoto();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS delivery_notes_sampai_wajib_berfoto ON delivery_notes;
            DROP FUNCTION IF EXISTS delivery_notes_sampai_wajib_berfoto();
            ALTER TABLE delivery_notes
                ADD CONSTRAINT delivery_notes_sampai_wajib_berfoto
                CHECK (status <> 'delivered' OR arrival_photo_path IS NOT NULL)
                NOT VALID;
        SQL);
    }
};
