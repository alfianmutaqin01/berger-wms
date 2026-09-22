<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRF — Material Requisition Form: Produksi meminta barang dari Logistik.
 *
 * MASALAH YANG DIPECAHKAN, dan ia bukan masalah pencatatan melainkan masalah
 * barang yang hilang dari ingatan. Selama ini permintaan ditulis di formulir
 * kertas: Produksi meminta, Logistik menyetujui, Operator mengambilkan.
 * Sesudah itu tidak ada satu pun catatan yang hidup. Akibatnya dua, dan
 * dua-duanya berulang:
 *
 *   1. Barang menumpuk di rak transit berbulan-bulan karena Produksi lupa
 *      pernah memintanya, dan tidak ada layar yang bisa ditanyai.
 *   2. Dari 300 pcs DDP yang akan direproses, baru 150 yang sempat dikerjakan.
 *      Sisa 150 tidak tercatat di mana pun — ia cuma diingat, dan ingatan
 *      itulah yang habis lebih dulu.
 *
 * ENAM TABEL, DAN PEMBAGIANNYA MENGIKUTI SIAPA YANG MENULISNYA
 * ------------------------------------------------------------
 *   material_requisitions             kepala dokumen; seluruh perjalanannya
 *   material_requisition_items        APA yang diminta Produksi (SKU + qty)
 *   material_requisition_allocations  BATCH MANA yang dipilih Logistik
 *   production_material_holdings      barang yang SUDAH di tangan Produksi
 *   production_material_consumptions  riwayat pemakaiannya, sekali demi sekali
 *   mrf_approver_contacts             nomor WA approver yang disimpan Produksi
 *
 * Dua tabel pertama sengaja dipisah dari yang ketiga. Produksi meminta "200
 * pcs SKU X" tanpa tahu — dan tanpa perlu tahu — barang itu tersebar di batch
 * mana saja; Logistik yang menerjemahkannya jadi batch sungguhan. Kalau
 * keduanya dilebur, permintaan Produksi ikut berubah setiap kali Logistik
 * mengubah pilihan batch, dan tidak ada lagi yang bisa menjawab "sebenarnya
 * Produksi minta berapa".
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->buatKepala();
        $this->buatBarisPermintaan();
        $this->buatAlokasiBatch();
        $this->buatBukuProduksi();
        $this->buatKontakApprover();
        $this->sambungkanKePicking();
    }

    private function buatKepala(): void
    {
        Schema::create('material_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('mrf_number', 20)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            /*
             | PEMINTA. user_id untuk menelusuri, department_name DISALIN
             | sebagai teks. Departemen bisa berganti nama atau dihapus, dan
             | dokumen yang dibuka dua tahun lagi harus tetap bisa menjawab
             | "waktu itu ia dari bagian mana" — bukan bagian tempat ia
             | bekerja sekarang.
             */
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('department_name', 100)->nullable();

            $table->string('request_type', 30);

            // WAJIB ISI, dan sengaja tanpa nilai bawaan. Inilah satu-satunya
            // kolom yang menjelaskan KENAPA barang keluar dari gudang; pilihan
            // jenis permintaan cuma menggolongkannya.
            $table->text('purpose');

            $table->string('status', 30)->default('pending_approval');

            /*
             | APPROVER LEWAT WHATSAPP. Namanya dan nomornya DISALIN ke sini,
             | bukan ditunjuk ke mrf_approver_contacts. Kontak boleh dihapus
             | atau nomornya diperbarui; dokumen yang sudah disetujui harus
             | tetap menyebut nomor yang BENAR-BENAR menerima tautannya.
             */
            $table->string('approver_name', 100);
            $table->string('approver_phone', 25);
            $table->string('approval_token', 64)->nullable()->unique();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamp('approver_rejected_at')->nullable();
            $table->text('approver_rejection_reason')->nullable();

            // Status pesan WA — bentuknya sama persis dengan delivery_notes,
            // dan memang harus sama: keduanya memakai WhatsAppSender yang
            // sama, termasuk mode manual yang menunggu satu ketukan manusia.
            $table->string('notify_status', 20)->default('pending');
            $table->text('notify_error')->nullable();
            $table->unsignedSmallInteger('notify_attempts')->default(0);
            $table->timestamp('notified_at')->nullable();

            // Persetujuan LOGISTIK, tahap kedua. Baru bisa ditekan setelah
            // approver WA menyetujui — urutannya ditegakkan di kode, bukan
            // di sini, karena alasan penolakannya perlu ikut tersimpan.
            $table->timestamp('logistics_approved_at')->nullable();
            $table->foreignId('logistics_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('logistics_rejected_at')->nullable();
            $table->foreignId('logistics_rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('logistics_rejection_reason')->nullable();

            $table->foreignId('picking_list_id')->nullable()->constrained()->nullOnDelete();

            /*
             | RAK SERAH TERIMA — diisi Operator saat menekan Siap Loading.
             |
             | Inti keluhan pemilik produk ada di kolom ini. Tanpa ia, barang
             | yang sudah turun dari rak berdiri entah di mana dan Produksi
             | harus menelepon Logistik untuk bertanya. Dengan ia, layar MRF
             | menyebutkan raknya dan Produksi berjalan langsung ke sana.
             */
            $table->foreignId('handover_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('handover_note')->nullable();
            $table->timestamp('picked_at')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
            $table->index(['requested_by', 'created_at']);
        });

        DB::statement("
            ALTER TABLE material_requisitions
            ADD CONSTRAINT material_requisitions_status_dikenal
            CHECK (status IN (
                'pending_approval', 'rejected_approval',
                'pending_logistics', 'rejected_logistics',
                'pending_picking', 'ready_for_pickup',
                'received', 'cancelled'
            ))
        ");

        DB::statement("
            ALTER TABLE material_requisitions
            ADD CONSTRAINT material_requisitions_jenis_dikenal
            CHECK (request_type IN (
                'reproses_tinting', 'testing_investigation',
                'replacement', 'sample_material'
            ))
        ");
    }

    private function buatBarisPermintaan(): void
    {
        Schema::create('material_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty_requested');

            // Usulan Produksi soal batch tertentu — mis. "batch DDP Juli yang
            // kemarin ditolak customer". Cuma keterangan, bukan pengikat:
            // yang menentukan batch sungguhannya tetap Logistik, yang berdiri
            // di depan raknya.
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['material_requisition_id', 'product_id']);
        });
    }

    private function buatAlokasiBatch(): void
    {
        Schema::create('material_requisition_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_requisition_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Baris stok asalnya boleh habis lalu hilang; keterangan batch di
            // bawah ini DISALIN supaya dokumennya tetap terbaca sesudah itu.
            $table->foreignId('source_stock_id')->nullable()->constrained('inventory_stocks')->nullOnDelete();
            $table->string('batch_no', 50)->nullable();
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->nullable();
            $table->string('ddp_reason', 50)->nullable();

            /*
             | TIGA ANGKA, TIGA ORANG YANG BERBEDA — jangan dilebur.
             |   qty_allocated  Logistik  : yang dijanjikan dari batch ini
             |   qty_picked     Operator  : yang benar-benar ada di rak
             |   qty_received   Produksi  : yang benar-benar diterima
             | Selisih di antara ketiganya adalah pertanyaan yang pantas
             | ditanyakan; satu angka tunggal menghapus pertanyaannya.
             */
            $table->unsignedInteger('qty_allocated');
            $table->unsignedInteger('qty_picked')->nullable();
            $table->unsignedInteger('qty_received')->nullable();
            $table->text('discrepancy_reason')->nullable();

            $table->timestamps();

            $table->index('material_requisition_id');
        });
    }

    private function buatBukuProduksi(): void
    {
        /*
         | BUKU BESAR MILIK PRODUKSI, terpisah dari inventory_stocks.
         |
         | Keputusan pemilik produk: begitu Produksi menerima barangnya,
         | barang itu HILANG dari inventory Logistik. Memisahkannya jadi tabel
         | sendiri membuat kalimat itu benar secara harfiah — bukan sekadar
         | baris stok yang disaring dari layar. Satu penyaringan yang terlupa
         | pada baris yang masih hidup di inventory_stocks berarti material
         | Produksi ikut terjual ke pelanggan.
         */
        Schema::create('production_material_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_requisition_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_requisition_allocation_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->string('batch_no', 50)->nullable();
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();

            /*
             | LOKASI SESUDAH DITERIMA — teks, bukan foreign key ke locations.
             |
             | Rak tujuan di sini BUKAN rak gudang. Begitu Produksi menerima,
             | barangnya pindah ke area Produksi sendiri, yang penomorannya
             | tidak dikelola Master Rak dan tidak boleh ikut terbaca sebagai
             | tempat penyimpanan stok gudang. Ditulis apa adanya oleh orang
             | yang menaruhnya: "Transit Produksi", "I-01-01", "Lt. 2 dekat
             | mixer" — ketiganya sama-sama sah dan sama-sama menolong.
             */
            $table->string('production_area', 60)->default('Transit Produksi');

            $table->unsignedInteger('qty_received');
            $table->unsignedInteger('qty_consumed')->default(0);

            $table->timestamp('received_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            // Kapan baris ini habis. NULL berarti masih ada sisanya — dan
            // inilah kolom yang membuat "sisa 150 yang terlupakan" bisa
            // dicari: ia yang menua tanpa pernah terisi.
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['warehouse_id', 'finished_at']);
            $table->index('product_id');
        });

        // Tidak boleh terpakai lebih banyak daripada yang diterima. Ditegakkan
        // basis data, bukan cuma kode: angka ini yang menjawab "sisa berapa",
        // dan sekali ia negatif, seluruh buku ini berhenti bisa dipercaya.
        DB::statement('
            ALTER TABLE production_material_holdings
            ADD CONSTRAINT production_material_holdings_pakai_tidak_lebih
            CHECK (qty_consumed <= qty_received)
        ');

        Schema::create('production_material_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_material_holding_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('qty');
            $table->text('note')->nullable();

            $table->timestamp('consumed_at');
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['production_material_holding_id', 'consumed_at']);
        });

        DB::statement('
            ALTER TABLE production_material_consumptions
            ADD CONSTRAINT production_material_consumptions_qty_positif
            CHECK (qty > 0)
        ');
    }

    private function buatKontakApprover(): void
    {
        /*
         | NOMOR WA APPROVER YANG DISIMPAN PRODUKSI SENDIRI.
         |
         | Bukan master data yang diurus Super Admin lewat Pengaturan Sistem.
         | Permintaan pemilik produk, dan alasannya masuk akal: yang tahu
         | kepada siapa permintaan hari ini harus dikirim adalah orang yang
         | membuatnya, bukan administrator yang tidak ikut di lantai produksi.
         |
         | DIPAKAI BERSAMA satu gudang, bukan milik satu akun. Pak Gandhi
         | menyetujui permintaan siapa pun di Karawang; menyimpannya per akun
         | berarti tiap orang mengetik nomor yang sama berulang kali — dan
         | nomor yang diketik ulang adalah nomor yang cepat atau lambat salah
         | satu digitnya.
         */
        Schema::create('mrf_approver_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('phone', 25);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /*
         | Satu nomor cukup sekali per gudang. DUA INDEKS PARSIAL, bukan satu
         | unique biasa: di Postgres NULL tidak pernah sama dengan NULL, jadi
         | kontak lintas gudang (warehouse_id NULL) akan bisa disimpan
         | berulang kali tanpa indeks kedua. Pola yang sama dipakai
         | pallet_capacity_rules.
         */
        DB::statement('
            CREATE UNIQUE INDEX mrf_approver_contacts_nomor_per_gudang
            ON mrf_approver_contacts (warehouse_id, phone)
            WHERE warehouse_id IS NOT NULL
        ');

        DB::statement('
            CREATE UNIQUE INDEX mrf_approver_contacts_nomor_lintas_gudang
            ON mrf_approver_contacts (phone)
            WHERE warehouse_id IS NULL
        ');
    }

    private function sambungkanKePicking(): void
    {
        Schema::table('picking_list_items', function (Blueprint $table) {
            $table->foreignId('material_requisition_allocation_id')
                ->nullable()
                ->after('stock_transfer_detail_id')
                ->constrained('material_requisition_allocations')
                ->nullOnDelete();
        });

        /*
         | SATU BARIS PICKING = SATU JENIS PEKERJAAN, sekarang bertiga.
         |
         | Pagar yang sama seperti saat transfer masuk ke picking, diperluas.
         | Baris yang menunjuk dua dokumen sekaligus akan mengurangi rak dua
         | kali — dan kekeliruan semacam itu tidak pernah terlihat di layar,
         | hanya di selisih stok berbulan kemudian.
         */
        DB::statement('ALTER TABLE picking_list_items DROP CONSTRAINT IF EXISTS picking_list_items_satu_jenis_pekerjaan');

        DB::statement('
            ALTER TABLE picking_list_items
            ADD CONSTRAINT picking_list_items_satu_jenis_pekerjaan
            CHECK (
                (sales_order_id IS NOT NULL AND sales_order_detail_id IS NOT NULL
                    AND stock_transfer_detail_id IS NULL AND material_requisition_allocation_id IS NULL)
                OR (sales_order_id IS NULL AND sales_order_detail_id IS NULL
                    AND stock_transfer_detail_id IS NOT NULL AND material_requisition_allocation_id IS NULL)
                OR (sales_order_id IS NULL AND sales_order_detail_id IS NULL
                    AND stock_transfer_detail_id IS NULL AND material_requisition_allocation_id IS NOT NULL)
            )
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE picking_list_items DROP CONSTRAINT IF EXISTS picking_list_items_satu_jenis_pekerjaan');

        Schema::table('picking_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_requisition_allocation_id');
        });

        DB::statement('
            ALTER TABLE picking_list_items
            ADD CONSTRAINT picking_list_items_satu_jenis_pekerjaan
            CHECK (
                (sales_order_id IS NOT NULL AND sales_order_detail_id IS NOT NULL AND stock_transfer_detail_id IS NULL)
                OR (sales_order_id IS NULL AND sales_order_detail_id IS NULL AND stock_transfer_detail_id IS NOT NULL)
            )
        ');

        Schema::dropIfExists('production_material_consumptions');
        Schema::dropIfExists('production_material_holdings');
        Schema::dropIfExists('mrf_approver_contacts');
        Schema::dropIfExists('material_requisition_allocations');
        Schema::dropIfExists('material_requisition_items');
        Schema::dropIfExists('material_requisitions');
    }
};
