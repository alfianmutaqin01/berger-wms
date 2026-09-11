<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking produk — menahan jatah untuk satu customer SEBELUM pesanannya
 * resmi masuk (keputusan pemilik produk).
 *
 * MASALAH YANG DIPECAHKAN
 * -----------------------
 * Customer meminta jatah jauh sebelum barangnya diproduksi: "nanti kalau
 * batch berikutnya jadi, 5 untuk saya". Barangnya belum ada, jadi tidak ada
 * apa pun di sistem yang memegang janji itu. Begitu produksi selesai dan
 * stoknya naik rak, barang itu mendarat dalam keadaan bebas — dan pesanan
 * lain yang kebetulan diproses lebih dulu menyambarnya lewat FIFO. Yang
 * dijanjikan berminggu-minggu lalu hilang tanpa ada yang sadar, dan
 * pengiriman ke customer itu baru dua sampai tiga minggu sekali.
 *
 * CARA MENAHANNYA — MEMAKAI JALAN YANG SUDAH ADA
 * ----------------------------------------------
 * Booking TIDAK membuat kolom stok baru. Ia memindahkan qty dari
 * `qty_available` ke `qty_allocated`, persis seperti alokasi pesanan.
 * Alasannya penting: FifoAllocator hanya melihat `qty_available`, dan
 * availableFor() — angka "stok yang bisa dijanjikan" di layar penerimaan —
 * menjumlahkan kolom yang sama. Jadi begitu 5 dari 10 unit dibooking, yang
 * bisa dipesan tinggal 5 DENGAN SENDIRINYA, tanpa satu pun query alokasi
 * atau layar ketersediaan perlu diubah.
 *
 * `stock_booking_allocations` adalah kembaran `sales_order_allocations`: ia
 * mencatat batch mana persisnya yang ditahan, supaya pembatalan booking bisa
 * mengembalikan qty ke baris stok yang benar — bukan ke batch sembarang yang
 * kebetulan punya sisa.
 *
 * SATU JANJI, SATU PEMILIK. Begitu pesanan sungguhan dari customer itu
 * diterima, jatahnya BERPINDAH dari booking ke pesanan (lihat
 * App\Support\Outbound\ProductBooking::consume). Tanpa perpindahan itu,
 * booking dan pesanan akan sama-sama memegang 5 unit yang sama dan gudang
 * terlihat menjanjikan 10 dari barang yang cuma ada 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_bookings', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 30)->unique();

            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('product_id')->constrained();

            $table->unsignedInteger('qty_booked');

            // Sudah dipakai pesanan sungguhan. Mencakup dua hal sekaligus:
            // jatah yang sudah tercadang lalu berpindah ke pesanan, DAN porsi
            // yang masih menunggu stok tetapi janjinya kini dipikul pesanan.
            // Keduanya harus dihitung supaya satu unit tidak dijanjikan dua kali.
            $table->unsignedInteger('qty_used')->default(0);

            // Kapan barangnya dibutuhkan customer. Bukan tenggat yang memaksa
            // apa pun — booking TIDAK pernah dilepas otomatis, karena melepas
            // jatah customer diam-diam justru masalah yang lebih besar
            // daripada booking yang menua. Dipakai untuk menyorot yang lewat.
            $table->date('needed_by')->nullable();

            $table->text('note')->nullable();

            // 'open' | 'closed' | 'cancelled'
            $table->string('status', 20)->default('open');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        DB::statement("ALTER TABLE stock_bookings ADD CONSTRAINT stock_bookings_status_valid
            CHECK (status IN ('open', 'closed', 'cancelled'))");

        DB::statement('ALTER TABLE stock_bookings ADD CONSTRAINT stock_bookings_qty_positif
            CHECK (qty_booked > 0)');

        // Tidak mungkin terpakai melebihi yang dibooking.
        DB::statement('ALTER TABLE stock_bookings ADD CONSTRAINT stock_bookings_terpakai_wajar
            CHECK (qty_used <= qty_booked)');

        DB::statement("ALTER TABLE stock_bookings ADD CONSTRAINT stock_bookings_pembatalan_lengkap
            CHECK (
                status <> 'cancelled'
                OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)
            )");

        Schema::create('stock_booking_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_stock_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('qty');

            $table->timestamps();

            // Satu booking hanya boleh punya SATU baris per batch. Jatah yang
            // dilengkapi menyusul menambah baris yang sama, bukan membuat
            // baris kembar yang harus dijumlahkan sendiri saat dilepas.
            $table->unique(['stock_booking_id', 'inventory_stock_id']);
            $table->index('inventory_stock_id');
        });

        DB::statement('ALTER TABLE stock_booking_allocations ADD CONSTRAINT stock_booking_allocations_qty_positif
            CHECK (qty > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_booking_allocations');
        Schema::dropIfExists('stock_bookings');
    }
};
