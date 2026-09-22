<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerBilling;
use App\Models\Product;
use App\Support\StockIndicator;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencarian sambil mengetik untuk form Buat Pesanan di Portal Sales.
 *
 * Customer dan produk berjumlah ribuan, jadi keduanya tidak ikut dikirim
 * bersama halaman formulir (lihat SalesOrderController::formData()).
 */
class OrderLookupController extends Controller
{
    /** Panjang minimal kata kunci sebelum pencarian dijalankan. */
    private const MIN_CARI = 2;

    /** Batas saran yang ditampilkan; cukup untuk dibaca sekali lihat di HP. */
    private const MAKS_SARAN = 20;

    /**
     * Cari customer sambil mengetik.
     *
     * MINIMAL DUA HURUF. Tanpa batas ini, kolom kosong akan mengembalikan
     * seluruh pelanggan — persis daftar raksasa yang justru mau dihindari.
     */
    public function lookupCustomers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (mb_strlen($q) < self::MIN_CARI) {
            return response()->json([]);
        }

        $hasil = Customer::active()
            // Sales hanya menemukan pelanggan yang gudangnya memang melayani
            // wilayah itu. Penyaringan yang sama diulang saat menyimpan di
            // SalesOrderRequest — daftar ini kenyamanan, bukan pengamanan.
            ->servedBy($request->user()?->warehouse)
            ->search($q)
            ->orderBy('name')
            ->limit(self::MAKS_SARAN)
            ->get(['id', 'code', 'name']);

        // F-BILL-03: penanda di form Buat Pesanan. HANYA informasi — Sales
        // tetap bisa memilih customer ini dan mengajukan pesanannya; yang
        // memutuskan tetap Logistik saat approval.
        $piutang = CustomerBilling::penandaCustomer($hasil->pluck('id')->all());

        return response()->json($hasil->map(fn (Customer $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'menunggak' => $piutang[$c->id]['lewat_terlama'] ?? 0,
        ]));
    }

    /**
     * Cari produk sambil mengetik, lengkap dengan indikator ketersediaannya.
     *
     * Yang dikembalikan HANYA yang cocok dengan yang diketik — mengetik
     * "APKO" tidak boleh memunculkan satu pun produk non-APKO.
     *
     * Indikator ikut di sini, BUKAN angka stoknya (Semi-Blind, F-INV-03).
     * Karena ini titik satu-satunya tempat Sales melihat ketersediaan, aturan
     * itu ditegakkan di sini juga, bukan hanya di halaman formulirnya.
     */
    public function lookupProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        // Gudang TIDAK lagi dibaca dari URL. Indikator ketersediaan adalah
        // angka stok yang disamarkan; membiarkan gudangnya dipilih dari
        // permintaan berarti Sales bisa mengintip keadaan gudang lain satu
        // SKU demi satu SKU hanya dengan mengganti parameter.
        $warehouseId = (int) WarehouseScope::boundary($request->user());

        if (mb_strlen($q) < self::MIN_CARI) {
            return response()->json([]);
        }

        $produk = Product::query()
            ->where('is_active', true)
            ->search($q)
            ->orderBy('sku')
            ->limit(self::MAKS_SARAN)
            ->get(['id', 'sku', 'name', 'uom', 'stock_threshold_low']);

        // Ketersediaan diambil sekali untuk seluruh hasil, bukan per produk.
        $tersedia = $warehouseId > 0
            ? StockIndicator::availabilityByWarehouse($warehouseId)
            : collect();

        return response()->json($produk->map(function (Product $p) use ($tersedia, $warehouseId) {
            $kode = $warehouseId > 0
                ? StockIndicator::for($p, $tersedia->get($p->id, 0))
                : null;

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'uom' => $p->uom,
                'indicator' => $kode,
                'label' => $kode ? StockIndicator::label($kode) : null,
                'badge' => $kode ? StockIndicator::badge($kode) : null,
            ];
        }));
    }
}
