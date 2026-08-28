<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Syarat pembayaran + limit kreditnya.
 *
 * Sengaja BERDIRI SENDIRI, tidak menempel di `customers`. Alasannya keputusan
 * bisnis: syarat pembayaran dipilih Sales saat membuat pesanan, bukan sifat
 * tetap milik pelanggan — satu pelanggan bisa memakai termin berbeda antar
 * pesanan. Dengan tabel terpisah, dropdown pada form Sales tinggal membacanya
 * dan Manager bisa menambah/mengubah termin tanpa menyentuh data pelanggan.
 *
 * Sistem belum punya proses pembayaran sama sekali; tabel ini disiapkan agar
 * modul Billing (Fase 8) tidak perlu mengubah struktur lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);

            // Jumlah hari jatuh tempo; 0 berarti dibayar di muka (cash/transfer).
            $table->unsignedSmallInteger('days')->default(0);

            // Plafon kredit yang melekat pada termin, bukan pada pelanggan.
            $table->decimal('credit_limit', 15, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
