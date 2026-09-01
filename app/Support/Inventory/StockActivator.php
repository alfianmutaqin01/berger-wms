<?php

namespace App\Support\Inventory;

use App\Models\InboundDetail;
use App\Models\InventoryStock;
use App\Models\StockMovement;

/**
 * Mengaktifkan stok dari palet inbound yang diverifikasi Logistik.
 *
 * PRD §6.3 F-INB-03 langkah 9-10 dan §7.2.1 EXPIRY_CALCULATION: begitu palet
 * diverifikasi, stoknya RESMI ADA — satu baris di `inventory_stocks` dan satu
 * entri `IN` di `stock_movements`.
 *
 * Dipisah dari controller karena jalur masuknya stok akan bertambah (retur
 * pada Fase 7 memakai aturan expiry yang sama), dan aturan itu tidak boleh
 * disalin ke tempat kedua.
 */
class StockActivator
{
    /**
     * WAJIB dipanggil di dalam DB::transaction() bersama perubahan status
     * paletnya — stok aktif tanpa palet terverifikasi (atau sebaliknya) adalah
     * keadaan yang tidak bisa dibetulkan sendiri oleh sistem.
     */
    public function activate(InboundDetail $detail, ?int $userId): InventoryStock
    {
        $header = $detail->header;
        $productionDate = $header->production_date;
        $qty = $detail->effective_qty;

        // Batch yang sama, di rak yang sama, dari tanggal produksi yang sama
        // adalah stok yang sama — digabung agar tidak muncul dua baris
        // kembar yang harus dijumlahkan manual setiap kali dilihat.
        $stock = InventoryStock::firstOrNew([
            'product_id' => $detail->product_id,
            'location_id' => $detail->location_id,
            'batch_no' => $detail->batch_no,
            'production_date' => $productionDate->toDateString(),
        ]);

        $qtyBefore = $stock->exists ? $stock->qty_available : 0;

        if (! $stock->exists) {
            $stock->fill([
                'warehouse_id' => $header->warehouse_id,
                'qty_allocated' => 0,
                'expiry_date' => InventoryStock::calculateExpiry(
                    $productionDate,
                    $detail->product?->shelf_life_months
                )->toDateString(),
                'status' => InventoryStock::STATUS_ACTIVE,
                'inbound_detail_id' => $detail->id,
            ]);
        }

        $stock->qty_available = $qtyBefore + $qty;
        $stock->verified_by = $userId;
        $stock->verified_at = now();
        $stock->save();

        StockMovement::create([
            'product_id' => $detail->product_id,
            'location_id' => $detail->location_id,
            'warehouse_id' => $header->warehouse_id,
            'movement_type' => StockMovement::TYPE_IN,
            'qty_change' => $qty,
            'qty_before' => $qtyBefore,
            'qty_after' => $stock->qty_available,
            'reference_type' => StockMovement::REF_INBOUND,
            'reference_id' => $header->id,
            'batch_no' => $detail->batch_no,
            'notes' => sprintf(
                'Verifikasi %s palet #%d (%s).',
                $header->document_number,
                $detail->pallet_no,
                $detail->production_order_no ?? '—'
            ),
            'user_id' => $userId,
        ]);

        return $stock;
    }
}
