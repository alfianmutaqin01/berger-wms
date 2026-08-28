<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master pelanggan (PRD §6.2 F-MASTER-06, docs/2 §3.2).
 *
 * Struktur kolom mengikuti ekspor ERP Berger:
 *   No./id | Ship-to Code | Name | Phone No. | Contact | Email |
 *   Address | Address 2 | Territory Code
 *
 * Sejak PRD v1.1 pelanggan didaftarkan LANGSUNG oleh Manager/Super Admin —
 * tidak ada lagi antrean pengajuan dari Sales, sehingga tidak ada kolom
 * status/approved_by/rejection_reason.
 *
 * Syarat pembayaran & limit kredit TIDAK ada di sini: keduanya tinggal di
 * `payment_terms` karena dipilih per-pesanan oleh Sales, bukan sifat tetap
 * pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // "No./id" pada ekspor ERP, contoh: IDI10101.
            $table->string('code', 30)->unique();

            // "Ship-to Code" — nomor pelanggan di ERP. Nullable karena tidak
            // semua pelanggan sudah terdaftar di sana (4 dari 9 pada data contoh).
            $table->string('ship_to_code', 30)->nullable();

            $table->string('name', 200);
            $table->string('phone', 25)->nullable();

            // "Contact" — nama orang yang dihubungi di pelanggan tersebut.
            $table->string('contact_name', 100)->nullable();
            $table->string('email', 150)->nullable();

            // Disimpan terpisah persis seperti ekspor ERP (Address & Address 2)
            // agar impor/ekspor tetap setara. Untuk tampilan keduanya digabung
            // lewat accessor `full_address`, sesuai permintaan: alamat jalan dan
            // kelurahan/kota tampil sebagai satu kolom.
            $table->text('address');
            $table->text('address_2')->nullable();

            // "Territory Code", contoh: PROJECT. Nilai lain menyusul dari ERP.
            $table->string('territory_code', 30)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'territory_code']);
            $table->index('ship_to_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
