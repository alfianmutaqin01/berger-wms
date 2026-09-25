<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serah terima Surat Jalan FISIK dari gudang ke Kantor Pusat.
 *
 * APA YANG DIURUS DI SINI. Setelah barang sampai dan fotonya diverifikasi,
 * lembar Surat Jalan bertanda tangan itu masih harus berpindah tempat: dari
 * gudang ke HO, dititipkan ke orang yang kebetulan mau ke pusat atau dikirim
 * lewat ekspedisi. Sampai hari ini perpindahan itu tidak tercatat di mana
 * pun, sehingga pertanyaan "SJ 206215 sudah dikirim belum?" hanya bisa
 * dijawab dengan menelepon orangnya.
 *
 * SATU AMPLOP = SATU BARIS di `delivery_note_handovers`. Isinya bisa satu
 * Surat Jalan, bisa tiga puluh; itulah kenapa ada tabel isi tersendiri dan
 * bukan sekadar kolom di `delivery_notes`.
 *
 * TIDAK ADA KOLOM BARU DI `delivery_notes`, dan itu disengaja. Pertanyaan
 * "SJ ini sudah dikirim ke HO belum?" dijawab dari ADA-TIDAKNYA barisnya di
 * tabel isi — prinsip yang sama dengan daftar kerja Pemindahan DDP. Kolom
 * status kembar di `delivery_notes` cepat atau lambat berbeda pendapat
 * dengan tabel ini, dan yang salah selalu yang dibaca layar.
 *
 * KENAPA `released_at` ADA. Sebuah Surat Jalan hanya boleh berada di satu
 * amplop yang hidup. Tetapi ia harus bisa KEMBALI ke daftar kalau amplopnya
 * dibatalkan, atau kalau CA membuka amplop dan lembarnya ternyata tidak ada
 * di dalamnya. Barisnya tidak dihapus — jejak "pernah dikirim lalu hilang di
 * jalan" justru yang paling perlu terbaca — melainkan ditandai dilepas, dan
 * indeks unik parsial di bawah hanya menghitung yang belum dilepas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_note_handovers', function (Blueprint $table) {
            $table->id();

            // PSJ2609001 — Paket Surat Jalan, tahun/bulan, urut. Lihat
            // DocumentNumber::forSjHandover().
            $table->string('code', 20)->unique();

            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // 'titipan' (dibawakan orang), 'ekspedisi' (JNE dsb), 'sendiri'.
            $table->string('carrier_type', 20);

            // Nama orangnya atau nama ekspedisinya — satu kolom, karena yang
            // ditanyakan saat mencari paket yang hilang selalu sama:
            // "dibawa siapa?".
            $table->string('carrier_name', 100);

            // Nomor resi; hanya terisi kalau lewat ekspedisi.
            $table->string('tracking_no', 50)->nullable();

            $table->string('status', 20)->default('sent');

            $table->timestamp('sent_at');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('received_notes')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            // Daftar utama CA: paket yang masih di jalan, terlama di atas.
            $table->index(['status', 'sent_at']);
            $table->index(['warehouse_id', 'status']);
        });

        /*
         * Status yang tidak membawa buktinya adalah status yang bohong.
         * "Diterima" tanpa waktu terima berarti layar riwayat menampilkan
         * tanggal kosong pada baris yang mengaku selesai, dan tidak ada yang
         * bisa membedakannya dari data yang memang belum diisi.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_note_handovers
            ADD CONSTRAINT delivery_note_handovers_status_konsisten
            CHECK (
                (status = 'sent')
                OR (status = 'received' AND received_at IS NOT NULL)
                OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL)
            )
        SQL);

        Schema::create('delivery_note_handover_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_handover_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();

            // 'ok' | 'issue' | 'missing'. NULL = CA belum memutuskan.
            $table->string('check_status', 20)->nullable();
            $table->text('check_note')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();

            // 'cancelled' (amplopnya dibatalkan) | 'missing' (tidak ada di
            // dalam amplop). Keduanya mengembalikan SJ ke daftar belum kirim.
            $table->timestamp('released_at')->nullable();
            $table->string('released_reason', 20)->nullable();

            $table->timestamps();

            // Satu Surat Jalan tidak boleh tercatat dua kali di amplop yang sama.
            $table->unique(['delivery_note_handover_id', 'delivery_note_id'], 'dnh_items_unik_per_paket');
        });

        /*
         * SATU SURAT JALAN, SATU AMPLOP HIDUP.
         *
         * Dijaga di basis data, bukan hanya di controller: dua orang Logistik
         * yang menekan "Proses Pengiriman" pada detik yang sama sama-sama
         * lolos pemeriksaan di PHP, dan yang lahir adalah dua amplop yang
         * masing-masing mengaku membawa lembar yang sama.
         *
         * Parsial (WHERE released_at IS NULL) supaya SJ yang amplopnya
         * dibatalkan atau yang hilang di jalan tetap bisa dikirim ulang.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dnh_items_satu_paket_hidup
            ON delivery_note_handover_items (delivery_note_id)
            WHERE released_at IS NULL
        SQL);

        /*
         * Alasan wajib untuk yang tidak beres. "Tidak sesuai" tanpa
         * keterangan tidak memberi tahu Logistik harus berbuat apa, dan
         * keterangan itu tidak akan pernah ditambahkan belakangan.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_note_handover_items
            ADD CONSTRAINT delivery_note_handover_items_alasan_wajib
            CHECK (
                check_status IS NULL
                OR check_status = 'ok'
                OR (check_status IN ('issue', 'missing') AND check_note IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE delivery_note_handover_items
            ADD CONSTRAINT delivery_note_handover_items_lepas_beralasan
            CHECK (
                (released_at IS NULL AND released_reason IS NULL)
                OR (released_at IS NOT NULL AND released_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_handover_items');
        Schema::dropIfExists('delivery_note_handovers');
    }
};
