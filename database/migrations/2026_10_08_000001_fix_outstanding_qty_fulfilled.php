<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Membetulkan baris riwayat outstanding yang tidak konsisten dengan dirinya
 * sendiri, lalu memasang pagar supaya tidak terulang.
 *
 * YANG TERLIHAT DI LAYAR
 * ----------------------
 * PO260901001 / SO0987002 tertulis: pesan 2, terpenuhi 2, kurang 1.
 * Dua ditambah satu bukan dua. Baris itu berbohong tentang dirinya sendiri,
 * dan yang membacanya menyimpulkan mesin outstanding-nya rusak.
 *
 * DARI MANA ASALNYA
 * -----------------
 * Bukan dari OutstandingRecorder — ia menghitung qty_fulfilled sebagai
 * (qty_ordered - sisa), jadi jumlahnya selalu pas. Asalnya dari penarikan data
 * lama pada migrasi 2026_09_21_000001, yang mengisi qty_fulfilled dengan
 * `qty_approved`.
 *
 * "Disetujui" dan "terpenuhi" bukan hal yang sama, dan bedanya justru muncul
 * pada kasus ini: pesanan 2 disetujui 2 (tidak ada yang dipotong saat
 * penerimaan), tetapi hanya 1 yang berangkat. Kekurangannya lahir di
 * PENGIRIMAN, bukan di penerimaan — sehingga baris itu bukan cuma salah angka,
 * sebabnya pun salah label.
 *
 * DUA PERBAIKAN, LALU SATU PAGAR
 * ------------------------------
 * Angkanya diluruskan dari qty_outstanding, bukan sebaliknya: qty_outstanding
 * disalin apa adanya dari sales_order_details.outstanding_qty dan itu memang
 * kekurangan yang benar. Yang keliru hanya kolom pendampingnya.
 *
 * Sebabnya dikoreksi hanya untuk baris tarikan lama yang qty_approved-nya
 * ternyata utuh — di situ tidak ada satu pun unit yang dipotong saat
 * penerimaan, jadi menyebutnya 'approval' menuduh tahap yang salah.
 *
 * CHECK-nya dipasang VALID (bukan NOT VALID) karena seluruh baris sudah
 * diluruskan lebih dulu di atasnya. Kalau suatu hari ada kode baru yang
 * menulis pasangan angka yang tidak menjumlah, ia akan ditolak di pintu basis
 * data — bukan diam-diam tampil di layar berbulan-bulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Sebabnya lebih dulu, selagi qty_fulfilled masih memuat
        //    qty_approved — begitu diluruskan, jejak "disetujui penuh" hilang.
        DB::statement(<<<'SQL'
            UPDATE sales_order_outstandings o
            SET cause = 'shipment'
            FROM sales_order_details d
            WHERE d.id = o.sales_order_detail_id
              AND o.cause = 'approval'
              AND d.qty_approved = d.qty_ordered
              AND o.note LIKE 'Ditarik dari data yang sudah ada%'
        SQL);

        // 2. Angkanya. Hanya baris yang memang tidak menjumlah yang disentuh.
        DB::statement(<<<'SQL'
            UPDATE sales_order_outstandings
            SET qty_fulfilled = qty_ordered - qty_outstanding,
                updated_at = NOW()
            WHERE qty_fulfilled + qty_outstanding <> qty_ordered
              AND qty_outstanding <= qty_ordered
        SQL);

        // 3. Pagar. Baris yang kekurangannya melebihi jumlah pesanan tidak
        //    mungkin benar, dan itu ikut tertutup oleh persamaan ini.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_order_outstandings
            ADD CONSTRAINT sales_order_outstandings_qty_konsisten
            CHECK (qty_fulfilled + qty_outstanding = qty_ordered)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_order_outstandings DROP CONSTRAINT IF EXISTS sales_order_outstandings_qty_konsisten');
    }
};
