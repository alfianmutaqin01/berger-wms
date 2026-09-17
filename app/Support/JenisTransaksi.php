<?php

namespace App\Support;

use App\Models\DeliveryNote;
use App\Models\InboundHeader;
use App\Models\MaterialRequisition;
use App\Models\PickingList;
use App\Models\ProductionMaterialHolding;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnDetail;
use App\Models\StockBooking;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis dokumen transaksi — satu daftar untuk seluruh layar audit.
 *
 * DITENTUKAN DARI MODEL SUBJEKNYA, BUKAN DARI HURUF DEPAN NOMORNYA. Menebak
 * jenis dari teks nomornya terlihat rapi sampai data sungguhannya dibuka:
 * nomor Surat Jalan datang dari BC dan berbunyi "206223" — tanpa huruf sama
 * sekali — sementara PO (pesanan) dan PL (daftar picking) sama-sama berawalan
 * "P". Jenisnya sudah diketahui pasti di titik pencatatan; membuangnya lalu
 * menebaknya kembali dari string hanya menambah cara untuk salah.
 *
 * KODE PENDEK TETAP DITAMPILKAN karena itu yang tertulis di dokumen fisik dan
 * itu yang diucapkan orang gudang. Yang berbeda hanya: kode dipakai untuk
 * MEMBACA, subject_type dipakai untuk MENYARING.
 */
class JenisTransaksi
{
    /**
     * Model dokumen => kode, label, dan kolom nomornya.
     *
     * @var array<class-string, array{kode:string, label:string, kolom:?string}>
     */
    public const DAFTAR = [
        MaterialRequisition::class => ['kode' => 'MRF', 'label' => 'Permintaan Material', 'kolom' => 'mrf_number'],
        SalesOrder::class => ['kode' => 'PO', 'label' => 'Pesanan Penjualan', 'kolom' => 'order_number'],
        DeliveryNote::class => ['kode' => 'SJ', 'label' => 'Surat Jalan', 'kolom' => 'document_no'],
        PickingList::class => ['kode' => 'PL', 'label' => 'Daftar Picking', 'kolom' => 'list_number'],
        StockTransfer::class => ['kode' => 'TF', 'label' => 'Transfer Gudang', 'kolom' => 'transfer_number'],
        SalesReturn::class => ['kode' => 'RJ', 'label' => 'Retur Penjualan', 'kolom' => 'reference'],
        SalesReturnDetail::class => ['kode' => 'RJ', 'label' => 'Retur Penjualan', 'kolom' => null],
        StockTake::class => ['kode' => 'ST', 'label' => 'Stock Opname', 'kolom' => 'reference'],
        InboundHeader::class => ['kode' => 'IN', 'label' => 'Barang Masuk', 'kolom' => 'document_number'],
        StockBooking::class => ['kode' => 'BK', 'label' => 'Booking Stok', 'kolom' => 'reference'],
        // Material di tangan divisi: nomornya ada di MRF induknya, bukan pada
        // barisnya sendiri. Yang ditelusuri orang memang nomor MRF-nya.
        ProductionMaterialHolding::class => ['kode' => 'MRF', 'label' => 'Permintaan Material', 'kolom' => null],
    ];

    /**
     * reference_type di buku besar stok => model dokumennya.
     *
     * Ledger menyimpan jenis acuannya sebagai teks pendek, bukan nama kelas.
     * Peta ini yang menyambungkan keduanya supaya satu baris mutasi bisa
     * menyebut nomor dokumen yang menyebabkannya.
     *
     * @var array<string, ?class-string>
     */
    public const ACUAN_LEDGER = [
        StockMovement::REF_INBOUND => InboundHeader::class,
        StockMovement::REF_SALES_ORDER => SalesOrder::class,
        StockMovement::REF_STOCK_TRANSFER => StockTransfer::class,
        StockMovement::REF_SALES_RETURN => SalesReturn::class,
        StockMovement::REF_BOOKING => StockBooking::class,
        StockMovement::REF_MATERIAL_REQUISITION => MaterialRequisition::class,
        // Koreksi stok tidak punya dokumen induk: yang menjadi alasannya
        // adalah catatan operatornya sendiri, dan itu sudah ada di kolom notes.
        StockMovement::REF_ADJUSTMENT => null,
    ];

    /** Dokumen yang tidak dikenal tetap punya tempat, bukan kolom kosong. */
    public const LAINNYA = ['kode' => '—', 'label' => 'Lainnya', 'kolom' => null];

    /** @return array{kode:string, label:string, kolom:?string} */
    public static function untuk(?string $kelas): array
    {
        return self::DAFTAR[$kelas] ?? self::LAINNYA;
    }

    public static function kode(?string $kelas): string
    {
        return self::untuk($kelas)['kode'];
    }

    public static function label(?string $kelas): string
    {
        return self::untuk($kelas)['label'];
    }

    /**
     * Nomor dokumen sebuah subjek, atau null kalau ia memang tidak bernomor.
     *
     * Dipanggil SEKALI saat mencatat, lalu hasilnya disalin ke kolom log.
     * Membacanya ulang lewat relasi setiap kali halaman dibuka berarti satu
     * query per baris — dan nomornya akan ikut hilang begitu dokumennya
     * dihapus, justru pada saat jejaknya paling dibutuhkan.
     */
    public static function nomor(?Model $subjek): ?string
    {
        if ($subjek === null) {
            return null;
        }

        /*
         | DIBACA LEWAT KUNCI ASINGNYA, bukan lewat relasi. Lazy loading
         | dimatikan di aplikasi ini, dan subjek yang dioper ke pencatat log
         | hampir tidak pernah membawa relasinya — membacanya lewat relasi
         | berarti melempar tepat di dalam try/catch pencatat, sehingga barisnya
         | gagal ditulis diam-diam. Satu lookup berindeks jauh lebih murah
         | daripada jejak yang hilang.
         */
        if ($subjek instanceof ProductionMaterialHolding) {
            return MaterialRequisition::whereKey($subjek->material_requisition_id)->value('mrf_number');
        }

        if ($subjek instanceof SalesReturnDetail) {
            return SalesReturn::whereKey($subjek->sales_return_id)->value('reference');
        }

        $kolom = self::untuk($subjek::class)['kolom'];

        if ($kolom === null) {
            return null;
        }

        $nomor = $subjek->getAttribute($kolom);

        return is_string($nomor) && trim($nomor) !== '' ? trim($nomor) : null;
    }

    /**
     * Pilihan penyaring: nama kelas => label berkode.
     *
     * Hanya jenis yang benar-benar pernah muncul yang pantas ada di dropdown,
     * tetapi menyaring daftar ini dengan query membuat pilihannya berubah-ubah
     * mengikuti isi tabel. Controller yang memutuskan; di sini daftar penuhnya.
     *
     * @return array<class-string, string>
     */
    public static function pilihan(): array
    {
        $pilihan = [];

        foreach (self::DAFTAR as $kelas => $jenis) {
            $pilihan[$kelas] = $jenis['kode'].' — '.$jenis['label'];
        }

        return $pilihan;
    }
}
