<?php

namespace App\Support\Outbound;

use App\Models\InventoryStock;
use App\Models\SalesOrderAllocation;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;

/**
 * Mencadangkan stok untuk satu baris pesanan, batch tertua lebih dulu
 * (PRD §6.5 F-OUT-02 langkah 4).
 *
 * KENAPA ALOKASI DIJALANKAN SAAT TERIMA, BUKAN SAAT PICKING
 * ---------------------------------------------------------
 * Kalau ditunda, dua Logistik yang menerima dua pesanan pada menit yang
 * sama sama-sama melihat "stok 10" dan sama-sama menjanjikannya. Yang
 * kedua baru ketahuan di rak, saat operator mencari barang yang sudah
 * diambil orang lain. Mencadangkan di titik keputusan menutup celah itu.
 *
 * YANG DIALOKASIKAN HANYA SEBANYAK YANG ADA
 * -----------------------------------------
 * Logistik boleh menyetujui LEBIH dari stok yang tercatat — kasus nyata di
 * Berger: barang sudah sampai gudang tapi belum di-putaway. Kekurangannya
 * TIDAK dipaksakan menjadi alokasi. `inventory_stocks` punya
 * CHECK (qty_available >= 0), jadi memaksakannya bukan menghasilkan angka
 * minus melainkan MEMBATALKAN seluruh transaksi dengan galat constraint
 * mentah. Yang benar: alokasikan sebisanya, laporkan sisanya sebagai
 * kekurangan yang menunggu stok, dan biarkan itu terlihat.
 *
 * WAJIB dipanggil di dalam DB::transaction(). Baris stok dikunci
 * lockForUpdate() supaya dua permintaan tidak membaca sisa yang sama.
 */
class FifoAllocator
{
    /**
     * @return int qty yang BERHASIL dicadangkan — bisa kurang dari $qty
     *             bila stok tercatat tidak mencukupi
     */
    public function allocate(SalesOrderDetail $detail, int $qty, ?int $userId): int
    {
        if ($qty < 1) {
            return 0;
        }

        $order = $detail->salesOrder;
        $sisa = $qty;

        // Hanya stok ACTIVE di gudang pesanan. DDP dan kedaluwarsa sengaja
        // tidak ikut: keduanya memang tidak boleh dijual (PRD §6.4 F-INV-04).
        $batch = InventoryStock::query()
            ->where('product_id', $detail->product_id)
            ->where('warehouse_id', $order->warehouse_id)
            ->where('status', InventoryStock::STATUS_ACTIVE)
            ->where('qty_available', '>', 0)
            // FIFO: tanggal produksi tertua dulu. id sebagai pemecah seri
            // supaya urutannya pasti — dua batch bertanggal sama tanpa
            // pengurut kedua bisa datang dalam urutan berbeda tiap query.
            ->orderBy('production_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batch as $stok) {
            if ($sisa < 1) {
                break;
            }

            $ambil = min($sisa, $stok->qty_available);
            $sebelum = $stok->qty_available;

            $stok->qty_available = $sebelum - $ambil;
            $stok->qty_allocated = $stok->qty_allocated + $ambil;
            $stok->save();

            // updateOrCreate, bukan create: unique(detail, stock) akan
            // menolak baris kedua bila satu baris pesanan dialokasikan dua
            // kali dari batch yang sama — terjadi saat kekurangan dilengkapi
            // belakangan setelah stoknya masuk.
            $alokasi = SalesOrderAllocation::firstOrNew([
                'sales_order_detail_id' => $detail->id,
                'inventory_stock_id' => $stok->id,
            ]);
            $alokasi->qty_allocated = ($alokasi->qty_allocated ?? 0) + $ambil;
            $alokasi->created_at = $alokasi->created_at ?? now();
            $alokasi->save();

            StockMovement::create([
                'product_id' => $detail->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $stok->warehouse_id,
                'movement_type' => StockMovement::TYPE_ALLOCATED,
                // Alokasi MENGURANGI yang tersedia; qty_change negatif agar
                // penjumlahan ledger tetap setara dengan qty_available.
                'qty_change' => -$ambil,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_SALES_ORDER,
                'reference_id' => $order->id,
                'batch_no' => $stok->batch_no,
                'notes' => sprintf('Alokasi %s (batch %s).', $order->order_number, $stok->batch_no ?? '—'),
                'user_id' => $userId,
            ]);

            $sisa -= $ambil;
        }

        return $qty - $sisa;
    }

    /**
     * Stok tercatat yang siap dijanjikan untuk satu produk di satu gudang.
     *
     * Dipakai layar penerimaan untuk mengusulkan qty dan menandai
     * kekurangan. Angka ini BISA BASI begitu ditampilkan — karena itu
     * alokasi sungguhannya tetap menghitung ulang di dalam transaksi.
     *
     * @return array<int, int> qty tersedia per product_id
     */
    public function availableFor(array $productIds, int $warehouseId): array
    {
        if ($productIds === []) {
            return [];
        }

        return InventoryStock::query()
            ->whereIn('product_id', $productIds)
            ->where('warehouse_id', $warehouseId)
            ->where('status', InventoryStock::STATUS_ACTIVE)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_available) AS tersedia')
            ->pluck('tersedia', 'product_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
