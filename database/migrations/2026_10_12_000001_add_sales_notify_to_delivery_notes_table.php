<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status kabar WhatsApp "barang sampai" untuk Sales pemesan.
 *
 * KOLOM SENDIRI, TIDAK MENUMPANG notify_*. Kolom yang sudah ada milik pesan
 * untuk SUPIR — dua pesan berbeda, ke dua orang berbeda, pada dua waktu
 * berbeda. Menumpukkannya berarti kabar yang sukses ke Sales menimpa catatan
 * bahwa tautan supir dulu gagal terkirim, dan sebaliknya.
 *
 * NULL PADA sales_notify_status BERARTI "BELUM SAATNYA", bukan "pending".
 * Surat Jalan yang barangnya belum sampai memang tidak punya kabar apa pun
 * untuk dikirim. Mengisinya dengan 'pending' sejak awal membuat seluruh Surat
 * Jalan lama tampak seperti antrean pesan yang tersangkut.
 *
 * sales_notify_phone DISALIN, bukan dibaca dari akun Sales saat dibutuhkan.
 * Nomor HP orang berganti; yang perlu terjawab kemudian adalah "pesan waktu
 * itu dikirim ke nomor mana" — terutama ketika Sales berkata tidak pernah
 * menerimanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->string('sales_notify_status', 20)->nullable()->after('notify_error');
            $table->unsignedSmallInteger('sales_notify_attempts')->default(0)->after('sales_notify_status');
            $table->timestamp('sales_notified_at')->nullable()->after('sales_notify_attempts');
            $table->text('sales_notify_error')->nullable()->after('sales_notified_at');
            $table->string('sales_notify_phone', 25)->nullable()->after('sales_notify_error');
        });

        DB::statement("
            ALTER TABLE delivery_notes
            ADD CONSTRAINT delivery_notes_sales_notify_status_check
            CHECK (sales_notify_status IS NULL OR sales_notify_status IN ('pending', 'manual', 'sent', 'failed'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS delivery_notes_sales_notify_status_check');

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropColumn([
                'sales_notify_status', 'sales_notify_attempts', 'sales_notified_at',
                'sales_notify_error', 'sales_notify_phone',
            ]);
        });
    }
};
