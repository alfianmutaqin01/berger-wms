<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kapasitas palet jadi SETELAN, bukan angka yang tertanam di kode.
 *
 * Permintaan pemilik produk: "masih ada beberapa product yang belum memiliki
 * batasan setiap pallet, buatkan pengaturan tersebut melalui setelan
 * operasional karena ini sangat menyangkut pembacaan setiap rak — misal 20 L
 * uom pail max 36 per pallet, dimana angka maksimalnya bisa diseting sehingga
 * sewaktu-waktu ada perubahan juga mudah, apalagi jika ada produk baru dengan
 * ukuran yang sedikit berbeda."
 *
 * APA YANG SALAH SEBELUM INI
 * --------------------------
 * Aturannya tinggal di App\Support\PalletCapacity sebagai array PHP. Ukuran
 * baru berarti menunggu rilis kode, dan 316 produk yang ukurannya tidak
 * tercakup berdiri tanpa kapasitas palet sama sekali — yang berarti aturan
 * pemecahan palet (PRD §7.1) tidak jalan untuk mereka.
 *
 * WADAH IKUT JADI KUNCI, TAPI BOLEH KOSONG
 * ----------------------------------------
 * Di data yang ada, `20 L` muncul sebagai PAIL (204 produk) dan juga sebagai
 * TIN (1 produk) — dua wadah yang tidak menumpuk sama di atas palet. Karena
 * itu satu aturan boleh menyebut wadahnya, dan aturan yang menyebut wadah
 * MENANG atas aturan yang tidak. Yang tidak menyebut wadah berlaku untuk
 * semua, supaya tidak perlu mengetik ulang angka yang sama untuk tiap wadah.
 *
 * NILAI PER PRODUK BERUBAH ARTI: DARI SALINAN JADI PENGECUALIAN
 * ------------------------------------------------------------
 * `products.max_qty_per_pallet` dulu diisi SAAT SIMPAN dari aturan yang sama —
 * sebuah salinan. Akibatnya mengubah aturan tidak mengubah apa pun: seribu
 * produk tetap memegang angka lamanya, dan justru itu yang membuat setelan ini
 * tidak ada gunanya kalau dibiarkan.
 *
 * Jadi salinan yang PERSIS SAMA dengan aturannya dikosongkan di sini. Tidak
 * ada satu angka pun yang berubah artinya hari ini — yang berubah cuma dari
 * mana angka itu dibaca. Yang nilainya BERBEDA dari aturan dibiarkan: itu
 * keputusan seseorang, bukan salinan, dan ia tetap menang atas aturannya.
 */
return new class extends Migration
{
    /**
     * Aturan yang selama ini tertanam di App\Support\PalletCapacity::RULES.
     *
     * Disalin apa adanya supaya hari pertama setelah migrasi ini tidak ada
     * satu pun perhitungan yang berubah.
     *
     * @var list<array{0:string, 1:string, 2:int}>
     */
    private const BAWAAN = [
        ['L', '0.900', 720],
        ['L', '2.500', 180],
        ['L', '3.600', 180],
        ['L', '5.000', 180],
        ['L', '15.000', 40],
        ['L', '18.000', 27],
        ['L', '20.000', 27],
        ['KG', '0.900', 720],
        ['KG', '1.000', 720],
        ['KG', '4.000', 180],
        ['KG', '5.000', 180],
        ['KG', '18.000', 36],
        ['KG', '20.000', 36],
        ['KG', '25.000', 36],
    ];

    public function up(): void
    {
        Schema::create('pallet_capacity_rules', function (Blueprint $table) {
            $table->id();

            // L atau KG. Satuan ikut menentukan hasilnya, bukan cuma angkanya:
            // 20 Liter memuat 27 pcs sementara 20 Kg memuat 36 pcs.
            $table->string('pack_unit', 2);
            $table->decimal('pack_size', 10, 3);

            // NULL = berlaku untuk SEMUA wadah pada ukuran itu. Yang menyebut
            // wadah menang atas yang tidak — lihat PalletCapacity::resolve().
            $table->string('uom', 20)->nullable();

            $table->integer('max_qty_per_pallet');

            // Kenapa angkanya segitu. Tidak wajib, tetapi angka kapasitas yang
            // berubah tanpa keterangan adalah angka yang tidak berani diubah
            // orang berikutnya.
            $table->string('note', 200)->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        // Satu kombinasi satu aturan. Dua baris untuk kombinasi yang sama
        // berarti kapasitasnya bergantung pada baris mana yang kebetulan
        // terbaca lebih dulu.
        //
        // Postgres menganggap NULL tidak sama dengan NULL, jadi unique biasa
        // TIDAK menutup aturan-tanpa-wadah yang kembar. Dipakai dua indeks:
        // satu untuk yang menyebut wadah, satu untuk yang tidak.
        DB::statement('CREATE UNIQUE INDEX pallet_capacity_rules_unik_wadah
            ON pallet_capacity_rules (pack_unit, pack_size, uom) WHERE uom IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX pallet_capacity_rules_unik_umum
            ON pallet_capacity_rules (pack_unit, pack_size) WHERE uom IS NULL');

        DB::statement("ALTER TABLE pallet_capacity_rules ADD CONSTRAINT pallet_capacity_rules_satuan_valid
            CHECK (pack_unit IN ('L', 'KG'))");
        DB::statement('ALTER TABLE pallet_capacity_rules ADD CONSTRAINT pallet_capacity_rules_ukuran_positif
            CHECK (pack_size > 0)');
        DB::statement('ALTER TABLE pallet_capacity_rules ADD CONSTRAINT pallet_capacity_rules_kapasitas_positif
            CHECK (max_qty_per_pallet > 0)');

        $sekarang = now();

        foreach (self::BAWAAN as [$unit, $size, $kapasitas]) {
            DB::table('pallet_capacity_rules')->insert([
                'pack_unit' => $unit,
                'pack_size' => $size,
                'uom' => null,
                'max_qty_per_pallet' => $kapasitas,
                'note' => 'Aturan gudang PT Berger Paints (PRD §7.1).',
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);
        }

        // Salinan yang persis sama dengan aturannya dikosongkan: mulai
        // sekarang angka itu dibaca dari aturan, dan membiarkan salinannya
        // membuat perubahan aturan tidak pernah sampai ke produknya.
        DB::statement('
            UPDATE products p
            SET max_qty_per_pallet = NULL
            FROM pallet_capacity_rules r
            WHERE r.uom IS NULL
              AND UPPER(TRIM(p.pack_unit)) = r.pack_unit
              AND p.pack_size = r.pack_size
              AND p.max_qty_per_pallet = r.max_qty_per_pallet
        ');
    }

    public function down(): void
    {
        // Salinannya dikembalikan supaya kode lama — yang membaca kolom ini
        // langsung — tetap menemukan angkanya.
        DB::statement('
            UPDATE products p
            SET max_qty_per_pallet = r.max_qty_per_pallet
            FROM pallet_capacity_rules r
            WHERE r.uom IS NULL
              AND UPPER(TRIM(p.pack_unit)) = r.pack_unit
              AND p.pack_size = r.pack_size
              AND p.max_qty_per_pallet IS NULL
        ');

        Schema::dropIfExists('pallet_capacity_rules');
    }
};
