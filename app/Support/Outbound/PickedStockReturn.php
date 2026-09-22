<?php

namespace App\Support\Outbound;

use App\Models\InventoryStock;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Mengembalikan barang yang sudah turun dari rak ke stok: seluruhnya saat
 * pesanan yang sudah dipicking dibatalkan (OrderCanceller), atau sebagian
 * saat Surat Jalan berangkat dengan qty lebih kecil dari hasil picking
 * (Shipment).
 *
 * Kebalikan dari PickingRun::complete(). Barangnya nyata dan masih di gudang;
 * tanpa jalur ini stok tercatat berkurang untuk barang yang tidak pernah
 * pergi.
 */
class PickedStockReturn
{
    /**
     * Mengembalikan barang yang SUDAH dipicking ke raknya semula.
     *
     * Dipakai OrderCanceller ketika pesanan dibatalkan setelah daftarnya
     * selesai — barangnya sudah turun ke loading dock tetapi belum berangkat.
     * Rak, batch, dan tanggal produksinya diketahui pasti karena dibekukan di
     * baris picking, jadi tidak ada yang perlu ditebak.
     *
     * WAJIB dipanggil di dalam DB::transaction milik pemanggilnya.
     *
     * @return int qty yang dikembalikan
     */
    public function kembalikanHasilPicking(SalesOrder $order, ?int $userId): int
    {
        $baris = PickingListItem::query()
            ->where('sales_order_id', $order->id)
            // Putaran yang berjalan SAJA. Tanpa ini, pesanan yang dibatalkan
            // untuk KEDUA kalinya akan mengembalikan barang putaran pertama
            // sekali lagi — barang yang sudah lama ada di rak dihitung masuk
            // untuk kedua kalinya, dan stok bertambah dari ketiadaan.
            ->forOrderRound($order)
            ->where('status', '<>', PickingListItem::STATUS_PENDING)
            ->whereHas('pickingList', fn ($q) => $q->where('status', PickingList::STATUS_COMPLETED))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($baris as $item) {
            $qty = (int) $item->qty_picked;

            if ($qty < 1) {
                continue;
            }

            $this->masukkanKembali(
                $item,
                $order,
                $qty,
                sprintf('Pembatalan %s: barang yang sudah dipicking dikembalikan ke rak', $order->order_number),
                $userId,
            );

            $total += $qty;
        }

        return $total;
    }

    /**
     * Mengembalikan SEBAGIAN hasil picking satu produk ke raknya semula.
     *
     * Dipakai saat Surat Jalan BC menyebut qty LEBIH KECIL daripada yang
     * sudah diturunkan operator dari rak. Selisihnya nyata: barangnya ada di
     * loading dock, tetapi dokumen resmi tidak menyertakannya, jadi ia tidak
     * ikut berangkat. Tanpa langkah ini, barang itu hilang dari catatan stok
     * padahal wujudnya masih di gudang.
     *
     * Diambil dari baris picking produk ini, satu per satu, sampai jumlahnya
     * terpenuhi — sehingga tiap unit kembali ke rak dan batch tempat ia
     * benar-benar diambil, bukan ditumpuk ke satu rak yang paling mudah.
     *
     * WAJIB dipanggil di dalam DB::transaction milik pemanggilnya.
     *
     * @return int qty yang benar-benar dikembalikan
     */
    public function kembalikanSebagian(SalesOrder $order, int $productId, int $qty, string $catatan, ?int $userId): int
    {
        if ($qty < 1) {
            return 0;
        }

        $baris = PickingListItem::query()
            ->where('sales_order_id', $order->id)
            ->where('product_id', $productId)
            // Putaran yang berjalan SAJA — kelebihan hari ini harus kembali
            // ke batch dan rak yang tadi diturunkan, bukan ke baris putaran
            // lama yang barangnya sudah lama berdiri di rak.
            ->forOrderRound($order)
            ->where('status', '<>', PickingListItem::STATUS_PENDING)
            ->whereHas('pickingList', fn ($q) => $q->where('status', PickingList::STATUS_COMPLETED))
            ->orderBy('id')
            ->get();

        $sisa = $qty;
        $total = 0;

        foreach ($baris as $item) {
            if ($sisa < 1) {
                break;
            }

            $ambil = min($sisa, (int) $item->qty_picked);

            if ($ambil < 1) {
                continue;
            }

            $this->masukkanKembali($item, $order, $ambil, $catatan, $userId);

            $sisa -= $ambil;
            $total += $ambil;
        }

        return $total;
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Menambahkan kembali qty ke baris stok asal satu baris picking.
     *
     * Satu-satunya tempat yang menulis mutasi pengembalian, dipakai baik oleh
     * pembatalan pesanan maupun penyesuaian ke Surat Jalan BC. Dua penulis
     * mutasi untuk kejadian yang sama cepat atau lambat berbeda aturannya.
     */
    private function masukkanKembali(
        PickingListItem $item,
        SalesOrder $order,
        int $qty,
        string $catatan,
        ?int $userId,
    ): void {
        $stok = $this->stokUntukDikembalikan($item, $order);
        $sebelum = $stok->qty_available;

        $stok->qty_available = $sebelum + $qty;
        $stok->save();

        StockMovement::create([
            'product_id' => $item->product_id,
            'location_id' => $stok->location_id,
            'warehouse_id' => $stok->warehouse_id,
            'movement_type' => StockMovement::TYPE_IN,
            'qty_change' => $qty,
            'qty_before' => $sebelum,
            'qty_after' => $stok->qty_available,
            'reference_type' => StockMovement::REF_SALES_ORDER,
            'reference_id' => $order->id,
            'batch_no' => $item->batch_no,
            'notes' => sprintf(
                '%s %s (batch %s).',
                $catatan,
                $stok->location?->code ?? '—',
                $item->batch_no ?? '—'
            ),
            'user_id' => $userId,
        ]);
    }

    /**
     * Baris stok tujuan pengembalian.
     *
     * Barisnya bisa saja sudah dihapus setelah kosong. Dibuat ulang di rak,
     * batch, dan tanggal produksi yang SAMA — ketiganya dibekukan di baris
     * picking, jadi tidak ada yang ditebak. Membuatnya di rak lain berarti
     * menaruh barang di tempat yang tidak akan dicari orang.
     */
    private function stokUntukDikembalikan(PickingListItem $item, SalesOrder $order): InventoryStock
    {
        if ($item->inventory_stock_id !== null) {
            $stok = InventoryStock::query()->lockForUpdate()->find($item->inventory_stock_id);

            if ($stok !== null) {
                return $stok;
            }
        }

        return InventoryStock::create([
            'product_id' => $item->product_id,
            'location_id' => $item->location_id,
            'warehouse_id' => $order->warehouse_id,
            'batch_no' => $item->batch_no,
            'qty_available' => 0,
            'qty_allocated' => 0,
            'production_date' => $item->production_date,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }
}
