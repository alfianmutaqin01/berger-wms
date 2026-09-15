<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kode gudang diganti ke kode resmi perusahaan — permintaan pemilik produk.
 *
 *   Karawang           WH-01 -> ID11_1001
 *   Pekanbaru          WH-02 -> ID1I_1001
 *   Sidoarjo/Surabaya  WH-03 -> ID1B_1001
 *
 * AMAN DIJALANKAN KARENA KODE GUDANG TIDAK PERNAH MASUK NOMOR DOKUMEN.
 * Sudah diperiksa: penomoran PO/SJ/inbound memakai deret tanggal, bukan kode
 * gudang. Kalau tidak demikian, mengganti kode berarti dokumen lama dan baru
 * memakai awalan berbeda untuk gudang yang sama — dan itu tidak bisa dibatalkan
 * dengan mengembalikan kodenya.
 *
 * DICOCOKKAN BERDASARKAN KODE LAMA, bukan id. Id gudang berbeda-beda antar
 * pemasangan (pengembangan, pengujian, produksi); menuliskan id di sini berarti
 * migrasi yang sama menandai gudang yang berbeda di tiap tempat.
 *
 * Dibungkus pemeriksaan "kode barunya belum dipakai" supaya migrasi ini tidak
 * gagal di basis data yang kadung sudah memakai kode barunya.
 */
return new class extends Migration
{
    /** kode lama => kode baru */
    private const PETA = [
        'WH-01' => 'ID11_1001',
        'WH-02' => 'ID1I_1001',
        'WH-03' => 'ID1B_1001',
    ];

    public function up(): void
    {
        foreach (self::PETA as $lama => $baru) {
            $this->ganti($lama, $baru);
        }

        // Namanya ikut diperjelas: gudangnya melayani Sidoarjo maupun Surabaya,
        // dan orang gudang menyebutnya dengan dua-duanya.
        DB::table('warehouses')->where('code', 'ID1B_1001')->where('name', 'Surabaya')
            ->update(['name' => 'Sidoarjo/Surabaya']);
    }

    public function down(): void
    {
        foreach (self::PETA as $lama => $baru) {
            $this->ganti($baru, $lama);
        }
    }

    private function ganti(string $dari, string $ke): void
    {
        if (DB::table('warehouses')->where('code', $ke)->exists()) {
            return;
        }

        DB::table('warehouses')->where('code', $dari)->update(['code' => $ke]);
    }
};
