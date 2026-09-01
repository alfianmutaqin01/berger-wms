<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan batch kedaluwarsa ke DDP — PRD §7.2.1 EXPIRY_SWEEP.
 *
 * Dijalankan harian 00:05 WIB. Ini adalah JARING PENGAMAN, bukan satu-satunya
 * pertahanan: query FIFO tetap wajib menyaring `expiry_date > CURRENT_DATE`
 * sendiri (lihat InventoryStock::scopeSellable). Kalau sweep ini gagal jalan
 * semalam, barang kedaluwarsa tetap tidak boleh ikut teralokasi.
 */
class SweepExpiredStock extends Command
{
    protected $signature = 'stock:sweep-expired {--dry-run : Tampilkan yang akan diubah tanpa menyimpan}';

    protected $description = 'Memindahkan stok yang lewat masa simpan ke status kedaluwarsa (PRD §7.2.1)';

    public function handle(): int
    {
        $kedaluwarsa = InventoryStock::with('product:id,sku')
            ->where('status', InventoryStock::STATUS_ACTIVE)
            ->whereDate('expiry_date', '<=', now()->toDateString())
            ->get();

        if ($kedaluwarsa->isEmpty()) {
            $this->info('Tidak ada stok yang kedaluwarsa hari ini.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn($kedaluwarsa->count().' baris stok AKAN ditandai kedaluwarsa:');

            foreach ($kedaluwarsa as $stock) {
                $this->line(sprintf(
                    '  %s batch %s — %d unit, kedaluwarsa %s',
                    $stock->product?->sku ?? '—',
                    $stock->batch_no,
                    $stock->qty_available,
                    $stock->expiry_date->toDateString()
                ));
            }

            return self::SUCCESS;
        }

        $jumlah = 0;

        foreach ($kedaluwarsa as $stock) {
            DB::transaction(function () use ($stock, &$jumlah) {
                $stock->update([
                    'status' => InventoryStock::STATUS_EXPIRED,
                    'ddp_reason' => InventoryStock::DDP_EXPIRED,
                ]);

                // Qty TIDAK berubah — barangnya masih ada di rak, hanya tidak
                // boleh dijual. Tetap dicatat di ledger demi jejak audit
                // (docs/2 §3.4: ADJUSTMENT reason EXPIRED).
                StockMovement::create([
                    'product_id' => $stock->product_id,
                    'location_id' => $stock->location_id,
                    'warehouse_id' => $stock->warehouse_id,
                    'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                    'qty_change' => 0,
                    'qty_before' => $stock->qty_available,
                    'qty_after' => $stock->qty_available,
                    'reference_type' => StockMovement::REF_ADJUSTMENT,
                    'reference_id' => $stock->id,
                    'batch_no' => $stock->batch_no,
                    'notes' => sprintf(
                        'EXPIRED: batch melewati masa simpan pada %s, dipindahkan ke stok kedaluwarsa oleh sweep harian.',
                        $stock->expiry_date->toDateString()
                    ),
                    // Tidak ada user: ini tindakan sistem, bukan orang.
                    'user_id' => null,
                ]);

                $jumlah++;
            });
        }

        $this->info($jumlah.' baris stok ditandai kedaluwarsa dan dikeluarkan dari Good Stock.');

        return self::SUCCESS;
    }
}
