<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel master gudang / dispatch code.
 *
 * Dibuat sekarang karena `users.warehouse_id` merujuk ke sini. Kolom yang ada
 * mengikuti docs/2_database_design.md §3.2 sehingga modul Inventory nanti tidak
 * perlu mengubah struktur tabel ini lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Dispatch code, contoh: "WH-01"');
            $table->string('name', 100);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
