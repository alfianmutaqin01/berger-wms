<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konfirmasi barang beda SKU — Fase 6 tahap 5 (susulan).
 *
 * DITEMUKAN SAAT UJI COBA PEMILIK PRODUK: pesanan 5Kg, dipicking 5Kg, tetapi
 * Surat Jalan BC menyebut SKU lain dengan qty sama. Sistem MEMBIARKANNYA
 * berangkat dan menulis tiga hal yang salah sekaligus — mengeluarkan barang
 * yang tak pernah diambil, mengembalikan barang yang sudah naik kendaraan,
 * dan meninggalkan pesanan yang outstanding-nya tidak akan pernah tertutup.
 *
 * SEBABNYA: aturan "dokumen BC yang menang" diterapkan ke qty DAN SKU. Untuk
 * qty aturan itu benar — 12 lawan 10 berarti stok gudang yang kurang. Untuk
 * SKU tidak: mesin tidak punya cara tahu sisi mana yang keliru. Bisa BC salah
 * ketik SKU, bisa operator mengambil ukuran yang salah dari rak; keduanya
 * menuntut tindakan fisik yang berlawanan.
 *
 * Karena itu SKU berbeda MENGHENTIKAN pengiriman, dan hanya lewat kolom ini
 * seseorang bisa menyatakan "ya, barang di Surat Jalan itulah yang naik" —
 * dengan nama dan alasan yang tercatat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->timestamp('substitution_confirmed_at')->nullable()->after('received_by_name');
            $table->foreignId('substitution_confirmed_by')->nullable()
                ->after('substitution_confirmed_at')->constrained('users');
            $table->text('substitution_reason')->nullable()->after('substitution_confirmed_by');
        });

        // Konfirmasi tanpa alasan tidak berguna: yang membacanya belakangan
        // adalah orang yang sedang menelusuri kenapa stok dua SKU bergerak
        // berlawanan pada hari yang sama.
        DB::statement('
            ALTER TABLE delivery_notes ADD CONSTRAINT delivery_notes_substitusi_lengkap
            CHECK (
                (substitution_confirmed_at IS NULL AND substitution_confirmed_by IS NULL AND substitution_reason IS NULL)
                OR (substitution_confirmed_at IS NOT NULL AND substitution_confirmed_by IS NOT NULL AND substitution_reason IS NOT NULL)
            )
        ');

        Schema::table('sales_order_details', function (Blueprint $table) {
            // Kenapa baris ini ditutup padahal qty_shipped-nya 0. Tanpa ini,
            // pesanan yang selesai lewat substitusi terbaca sebagai pesanan
            // yang outstanding-nya dinolkan tanpa sebab.
            $table->text('substitution_note')->nullable()->after('outstanding_qty');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_details', function (Blueprint $table) {
            $table->dropColumn('substitution_note');
        });

        DB::statement('ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS delivery_notes_substitusi_lengkap');

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitution_confirmed_by');
            $table->dropColumn(['substitution_confirmed_at', 'substitution_reason']);
        });
    }
};
