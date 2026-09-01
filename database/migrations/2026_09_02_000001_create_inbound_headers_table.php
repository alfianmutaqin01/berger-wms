<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Header dokumen produksi masuk (PRD §6.3 F-INB-01, docs/2 §3.3).
 *
 * `document_number` DIBANGKITKAN SISTEM, berbeda dari rancangan awal yang
 * menyebutnya input manual — keputusan pemilik produk: Tim Produksi cukup
 * mengunggah berkas Excel, nomor dokumen dan tanggal terisi sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_headers', function (Blueprint $table) {
            $table->id();

            // Dibangkitkan sistem, format IN-YYMMDD-NNN. Unik agar dua input
            // bersamaan tidak bisa memakai nomor yang sama.
            $table->string('document_number', 50)->unique();

            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('production_date');

            $table->string('status', 30)->default('putaway_pending');

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Pola query daftar put-away & verifikasi: saring status, urut terbaru.
            $table->index(['warehouse_id', 'status', 'production_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_headers');
    }
};
