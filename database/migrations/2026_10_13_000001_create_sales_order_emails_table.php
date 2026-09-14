<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan setiap email kabar pesanan untuk Sales pemilik pesanan.
 *
 * TABEL SENDIRI, BUKAN KOLOM notify_* SEPERTI WHATSAPP. Satu pesanan
 * menghasilkan beberapa email pada waktu yang berbeda — diterima, tiap Surat
 * Jalan berangkat, tiap Surat Jalan sampai, selesai — dan satu pesanan bisa
 * ditolak lalu diajukan ulang lalu ditolak lagi. Sepasang kolom di pesanan
 * hanya bisa mengingat yang terakhir.
 *
 * SATU BARIS PER KEJADIAN, ditulis saat kejadiannya, bukan saat emailnya
 * keluar. Baris itulah yang membuat percobaan ulang antrean tidak mengirim
 * email yang sama dua kali: job melihat statusnya sudah `sent` lalu berhenti.
 *
 * recipient_email DISALIN saat terkirim. Alamat email orang berganti; yang
 * perlu terjawab kemudian adalah "email waktu itu dikirim ke alamat mana".
 *
 * `data` menyimpan angka yang hanya benar PADA SAAT kejadiannya — misalnya
 * berapa unit yang tercadang saat pesanan diterima. Angka itu bergerak begitu
 * picking berjalan, dan email yang tertunda di antrean tidak boleh
 * menyebut angka hari ini sebagai angka saat penerimaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('delivery_note_id')->nullable()->constrained('delivery_notes')->nullOnDelete();
            $table->string('type', 30);
            $table->json('data')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email', 150)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'type']);
        });

        DB::statement("
            ALTER TABLE sales_order_emails
            ADD CONSTRAINT sales_order_emails_status_check
            CHECK (status IN ('pending', 'sent', 'failed', 'skipped'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_emails');
    }
};
