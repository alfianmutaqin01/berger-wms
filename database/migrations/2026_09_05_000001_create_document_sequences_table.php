<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penomoran dokumen otomatis (PO, Surat Jalan).
 *
 * DIBUAT LEBIH AWAL DARI RENCANA. Rencana pembangunan menjadwalkannya di
 * Fase 10, tapi
 * Fase 5 sudah butuh nomor PO dan Fase 6 butuh nomor Surat Jalan. Menunda
 * berarti menulis logika penomoran dua kali lalu membuangnya. Fase 10 kini
 * tinggal menambahkan layar pengaturannya untuk Super Admin.
 *
 * BEDA DARI docs/2 §3.9 — disepakati pemilik produk:
 *
 *   1. Ada PERIODE BULAN, bukan hanya tahun. Nomor PO memakai format
 *      PO{YYYYMMDD}{urut} dengan urut yang reset tiap ganti bulan, jadi
 *      kuncinya harus sampai bulan. Dokumen lain yang cukup reset per tahun
 *      mengisi period_month dengan NULL.
 *   2. warehouse_id BOLEH NULL. Nomor PO tidak memuat kode gudang sama
 *      sekali (PO20260901001), sedangkan nomor Surat Jalan memuatnya
 *      (SJ-KRW-2026-00089). Satu tabel melayani keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();

            // 'sales_order', 'delivery_note', dst.
            $table->string('document_type', 30);

            // NULL = penomoran berlaku lintas gudang (kasus nomor PO).
            $table->foreignId('warehouse_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('period_year');

            // NULL = urutan hanya reset tiap tahun, bukan tiap bulan.
            $table->unsignedTinyInteger('period_month')->nullable();

            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();
        });

        /*
         * NULLS NOT DISTINCT (PostgreSQL 15+, kita di 16).
         *
         * Secara baku Postgres menganggap dua NULL BERBEDA, sehingga unique
         * index biasa TIDAK akan mencegah dua baris ('sales_order', NULL,
         * 2026, 9) hidup berdampingan — dan dua baris seperti itu berarti dua
         * pesanan bisa memperoleh nomor yang sama persis. Klausa ini yang
         * membuat NULL diperlakukan sebagai satu nilai.
         */
        DB::statement('CREATE UNIQUE INDEX document_sequences_key
            ON document_sequences (document_type, warehouse_id, period_year, period_month)
            NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
