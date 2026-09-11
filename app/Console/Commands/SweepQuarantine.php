<?php

namespace App\Console\Commands;

use App\Models\InventoryStock;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Melepas batch yang jangka waktu karantinanya sudah lewat.
 *
 * Permintaan pemilik produk (bukan PRD): karantina berbasis hari, dan begitu
 * jangka waktunya lewat, batch itu OTOMATIS masuk lagi ke rekomendasi picking
 * — tidak menunggu tindakan manual, berbeda dari DDP.
 *
 * Dijalankan harian 00:10 WIB, sengaja diselisihkan 5 menit dari
 * stock:sweep-expired (00:05) supaya keduanya tidak berebut baris yang sama
 * pada detik yang persis sama.
 */
class SweepQuarantine extends Command
{
    protected $signature = 'stock:sweep-quarantine {--dry-run : Tampilkan yang akan dilepas tanpa menyimpan}';

    protected $description = 'Melepas otomatis batch yang jangka waktu karantinanya sudah lewat';

    public function handle(): int
    {
        $selesai = InventoryStock::with('product:id,sku')
            ->where('status', InventoryStock::STATUS_QUARANTINE)
            ->whereDate('quarantine_until', '<=', now()->toDateString())
            ->get();

        if ($selesai->isEmpty()) {
            $this->info('Tidak ada batch yang karantinanya berakhir hari ini.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn($selesai->count().' baris stok AKAN dilepas dari karantina:');

            foreach ($selesai as $stock) {
                $this->line(sprintf(
                    '  %s batch %s — %d unit, karantina sampai %s',
                    $stock->product?->sku ?? '—',
                    $stock->batch_no,
                    $stock->qty_available,
                    $stock->quarantine_until->toDateString()
                ));
            }

            return self::SUCCESS;
        }

        $jumlah = 0;

        foreach ($selesai as $stock) {
            DB::transaction(function () use ($stock, &$jumlah) {
                $stock->update([
                    'status' => InventoryStock::STATUS_ACTIVE,
                    'quarantine_released_at' => now(),
                ]);

                // Qty TIDAK berubah — barangnya tidak pernah pindah selama
                // ditahan, hanya kelayakan jualnya yang kembali. Tetap dicatat
                // demi jejak audit (pola yang sama dengan sweep kedaluwarsa).
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
                        'KARANTINA BERAKHIR: jangka waktu %d hari terlewati pada %s, dilepas otomatis oleh sweep harian.',
                        $stock->quarantine_days,
                        $stock->quarantine_until->toDateString(),
                    ),
                    // Tidak ada user: ini tindakan sistem, bukan orang.
                    'user_id' => null,
                ]);

                $jumlah++;
            });
        }

        $this->info($jumlah.' baris stok dilepas dari karantina, kembali jadi Good Stock.');

        return self::SUCCESS;
    }
}
