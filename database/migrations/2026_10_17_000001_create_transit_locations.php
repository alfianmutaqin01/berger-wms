<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dua rak transit di tiap gudang: In-Transit Produksi dan In-Transit Logistik.
 *
 * Material MRF yang sudah diambil operator tidak naik kendaraan dan tidak
 * kembali ke rak — ia berdiri di satu titik serah terima sampai Produksi
 * membawanya. Sebelumnya titik itu dipilih dari daftar rak biasa, sehingga
 * barang yang sudah bukan milik gudang tercatat seolah masih tersimpan di rak
 * penyimpanan, dan rak itu tetap ditawarkan untuk put-away barang baru.
 *
 * DIBUAT LEWAT MIGRASI, bukan disuruh diisi manual: alur serah terima MRF
 * tidak bisa dijalankan sama sekali sebelum raknya ada, dan fitur yang
 * menunggu pengisian master data akan dicoba pada hari pertama lalu ditinggal.
 *
 * Rack/level/cell diisi supaya baris ini sah menurut aturan kolomnya dan tetap
 * terbaca di layar yang mengurutkan rak; yang membedakannya adalah zona.
 */
return new class extends Migration
{
    /**
     * kode rak => [deret, zona].
     *
     * Deretnya sengaja pendek: kolom `rack` hanya menampung 5 karakter, sama
     * seperti deret sungguhan ("B-01"). Keduanya diberi deret yang berbeda
     * supaya tidak berkumpul menjadi satu deret di layar yang mengelompokkan
     * rak.
     *
     * @var array<string, array{string, string}>
     */
    private const RAK = [
        'TRANSIT-PROD' => ['TR-PR', Location::ZONE_TRANSIT_PRODUKSI],
        'TRANSIT-LOG' => ['TR-LG', Location::ZONE_TRANSIT_LOGISTIK],
    ];

    public function up(): void
    {
        $sekarang = now();

        foreach (DB::table('warehouses')->pluck('id') as $gudangId) {
            foreach (self::RAK as $kode => [$deret, $zona]) {
                // insertOrIgnore: migrasi ini harus aman dijalankan ulang, dan
                // pada basis data yang sudah punya raknya tidak ada yang perlu
                // dikerjakan.
                DB::table('locations')->insertOrIgnore([
                    'warehouse_id' => $gudangId,
                    'code' => $kode,
                    'rack' => $deret,
                    'level' => 1,
                    'cell' => 1,
                    'zone' => $zona,
                    'is_active' => true,
                    'created_at' => $sekarang,
                    'updated_at' => $sekarang,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Hanya rak yang masih kosong yang dihapus. Rak transit yang sudah
        // pernah dipakai sebagai tempat serah terima masih ditunjuk oleh
        // material_requisitions.handover_location_id, dan menghapusnya
        // membuang alamat dari MRF yang sudah selesai.
        DB::table('locations')
            ->whereIn('code', array_keys(self::RAK))
            ->whereNotIn('id', fn ($q) => $q->select('handover_location_id')
                ->from('material_requisitions')->whereNotNull('handover_location_id'))
            ->whereNotIn('id', fn ($q) => $q->select('location_id')->from('inventory_stocks'))
            ->delete();
    }
};
