<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mengganti nama sales_order_details.lost_qty menjadi outstanding_qty.
 *
 * ISTILAH, BUKAN PERILAKU. Pemilik produk menetapkan bahwa yang selama ini
 * kami sebut "Lost Sales" di Berger disebut OUTSTANDING. Angkanya tetap sama:
 * qty_ordered - qty_approved, yaitu bagian pesanan yang tidak jadi dikirim.
 *
 * Kolomnya ikut diganti, bukan hanya labelnya di layar. Kolom bernama
 * lost_qty dengan label "Outstanding" berarti setiap orang yang membaca kode
 * ini kelak harus menerjemahkan dua istilah untuk satu hal — dan itulah cara
 * paling andal melahirkan salah paham.
 *
 * Dikerjakan SEKARANG karena sales_order_details baru berisi segelintir baris.
 * Enam bulan lagi penggantian nama kolom yang sama menyentuh data sungguhan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_order_details RENAME COLUMN lost_qty TO outstanding_qty');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_order_details RENAME COLUMN outstanding_qty TO lost_qty');
    }
};
