<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konfirmasi "sampai" yang dilakukan Logistik, bukan supir.
 *
 * KENAPA PERLU. Sejak bukti Surat Jalan hanya boleh diunggah setelah barang
 * dinyatakan sampai, satu-satunya yang bisa membuka pintu itu adalah supir
 * lewat tautan ePOD-nya. Supir yang kehilangan tautannya, kehabisan baterai,
 * atau nomornya salah ketik membuat pesanannya macet selamanya — tidak ada
 * seorang pun di sistem yang bisa menyelesaikannya.
 *
 * TIDAK BOLEH TERLIHAT SAMA dengan konfirmasi supir. Konfirmasi supir adalah
 * kesaksian orang yang berdiri di tempat tujuan; penandaan Logistik adalah
 * keterangan orang yang TIDAK di sana. Menyimpan keduanya di kolom yang sama
 * membuat audit membaca keterangan kantor sebagai kesaksian lapangan, dan
 * justru pada pengiriman yang bermasalah — sebab itulah yang membuat jalur
 * ini dipakai.
 *
 * INVARIAN "SAMPAI WAJIB BERFOTO" DIPERLUAS, BUKAN DILUBANGI
 * ----------------------------------------------------------
 * Trigger dari 2026_10_16_000001 menolak status 'delivered' tanpa foto, dan
 * niat itu tetap benar: tidak boleh ada kedatangan tanpa bukti. Yang keliru
 * adalah menganggap foto satu-satunya bentuk bukti.
 *
 * Aturannya sekarang: 'delivered' wajib punya FOTO, ATAU penandaan manual
 * yang menyebut SIAPA yang menandainya dan KENAPA. Keduanya sama-sama bisa
 * dipertanggungjawabkan; yang tetap ditolak adalah kedatangan yang tidak
 * dijelaskan siapa pun. Alasan kosong tidak dihitung — kolom terisi spasi
 * sama saja dengan tidak ada keterangan.
 *
 * Fotonya sengaja TIDAK diminta pada jalur manual: yang memakainya justru
 * tidak berada di tempat tujuan, dan memaksanya memotret hanya akan
 * melahirkan foto karangan yang terlihat sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->foreignId('arrival_manual_by')->nullable()->after('received_by_name')
                ->constrained('users')->nullOnDelete();
            $table->text('arrival_manual_reason')->nullable()->after('arrival_manual_by');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION delivery_notes_sampai_wajib_berfoto() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'delivered'
                   AND NEW.arrival_photo_path IS NULL
                   AND (NEW.arrival_manual_by IS NULL OR COALESCE(BTRIM(NEW.arrival_manual_reason), '') = '')
                   AND (
                       TG_OP = 'INSERT'
                       OR OLD.status IS DISTINCT FROM 'delivered'
                       OR OLD.arrival_photo_path IS NOT NULL
                   )
                THEN
                    RAISE EXCEPTION 'delivery_notes_sampai_wajib_berfoto: surat jalan % tidak boleh berstatus delivered tanpa foto sampai atau penandaan manual beralasan', NEW.document_no
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Trigger dikembalikan ke bentuk semula LEBIH DULU: mengembalikannya
        // sesudah kolomnya hilang membuat fungsinya menunjuk kolom yang tidak
        // ada lagi, dan galatnya baru muncul pada UPDATE pertama sesudahnya.
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
        SQL);

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arrival_manual_by');
            $table->dropColumn('arrival_manual_reason');
        });
    }
};
