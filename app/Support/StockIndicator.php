<?php

namespace App\Support;

use App\Models\InventoryStock;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Indikator stok Semi-Blind untuk Portal Sales — PRD §6.4 F-INV-03.
 *
 * Sales melihat ✅ / ⚠️ / ❌, TIDAK PERNAH angkanya. Itu keputusan bisnis:
 * angka stok yang terbuka membuat Sales menyetel sendiri jumlah pesanan agar
 * "pas", sehingga permintaan yang sebenarnya tidak pernah terekam dan angka
 * Outstanding kehilangan artinya.
 *
 * Dua aturan yang gampang keliru:
 *   1. HANYA Good Stock yang dihitung. Stok DDP dan yang kedaluwarsa tidak
 *      pernah boleh terbaca sebagai ketersediaan (§7.2.1).
 *   2. Yang dihitung adalah qty_available, BUKAN qty_on_hand. Barang yang
 *      sudah dijanjikan ke pesanan lain bukan barang yang tersedia.
 */
class StockIndicator
{
    public const AVAILABLE = 'available';

    public const LIMITED = 'limited';

    public const OUT = 'out';

    public const LABELS = [
        self::AVAILABLE => '✅ Tersedia',
        self::LIMITED => '⚠️ Terbatas',
        self::OUT => '❌ Habis',
    ];

    /** Kelas badge Bootstrap per indikator (docs/4 §6). */
    public const BADGES = [
        self::AVAILABLE => 'bg-success',
        self::LIMITED => 'bg-warning text-dark',
        self::OUT => 'bg-danger',
    ];

    /**
     * Indikator satu produk di satu gudang.
     *
     * Ambangnya per produk (products.stock_threshold_low), bukan satu angka
     * untuk semua: 50 pail cat tembok dan 50 kaleng thinner bukan tingkat
     * kelangkaan yang sama.
     */
    public static function for(Product $product, ?int $qtyAvailable): string
    {
        $qty = (int) $qtyAvailable;

        if ($qty <= 0) {
            return self::OUT;
        }

        return $qty <= (int) $product->stock_threshold_low
            ? self::LIMITED
            : self::AVAILABLE;
    }

    /**
     * Ketersediaan Good Stock per produk di satu gudang.
     *
     * Satu query untuk seluruh produk, bukan satu per produk: form Buat
     * Pesanan menampilkan ratusan SKU di dropdown-nya.
     *
     * @return Collection<int, int> product_id => qty tersedia
     */
    public static function availabilityByWarehouse(int $warehouseId): Collection
    {
        return InventoryStock::query()
            ->sellable()
            ->where('warehouse_id', $warehouseId)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_available) AS tersedia')
            ->pluck('tersedia', 'product_id')
            ->map(fn ($qty) => (int) $qty);
    }

    public static function label(string $indicator): string
    {
        return self::LABELS[$indicator] ?? self::LABELS[self::OUT];
    }

    public static function badge(string $indicator): string
    {
        return self::BADGES[$indicator] ?? self::BADGES[self::OUT];
    }
}
