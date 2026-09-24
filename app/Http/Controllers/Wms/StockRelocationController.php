<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Support\Activity;
use App\Support\Inventory\PemindahanRak;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Pindah rak di dalam satu gudang, dari layar Data Stok.
 *
 * Bukan transfer antar gudang — yang itu punya dokumen dan picking sendiri
 * di StockTransferController.
 *
 * Perpindahannya sendiri dikerjakan PemindahanRak, yang dipakai bersama layar
 * pemindahan stok DDP ke rak DDP.
 */
class StockRelocationController extends Controller
{
    public function __construct(private readonly PemindahanRak $pemindahan) {}

    /**
     * F-INV-02 turunan: memindahkan stok antar lokasi rak.
     *
     * Direkam sebagai PASANGAN TRANSFER_OUT/TRANSFER_IN dalam satu transaksi
     * (docs/2 §3.4): transfer adalah mutasi murni tanpa siklus hidup dokumen,
     * jadi tidak butuh tabel header sendiri. Total qty_change kedua entri
     * harus nol — kalau tidak, stok tercipta atau lenyap dari ketiadaan.
     *
     * Batch, tanggal produksi, dan tanggal kedaluwarsa IKUT PINDAH apa adanya.
     * Membuat batch baru di lokasi tujuan akan merusak FIFO (barang lama
     * tampak baru) sekaligus perhitungan kedaluwarsa.
     */
    public function transfer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'to_location_code' => ['required', 'string', 'max:20'],
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], [
            'to_location_code' => 'lokasi tujuan',
            'reason' => 'alasan pemindahan',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);

        WarehouseScope::assert($stock->warehouse_id, $request->user());

        $qty = (int) $validated['qty'];

        if ($qty > $stock->qty_available) {
            return back()->with('error', sprintf(
                'Qty pindah (%d) melebihi stok tersedia (%d).',
                $qty,
                $stock->qty_available
            ));
        }

        $tujuan = Location::where('warehouse_id', $stock->warehouse_id)
            ->active()
            // Rak transit bukan tempat menyimpan stok; kodenya diperlakukan
            // seperti rak yang tidak ada.
            ->penyimpanan()
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($validated['to_location_code']))])
            ->first();

        if (! $tujuan) {
            return back()->with('error', sprintf(
                'Lokasi tujuan "%s" tidak ada atau tidak aktif di gudang ini.',
                strtoupper(trim($validated['to_location_code']))
            ));
        }

        if ($tujuan->id === $stock->location_id) {
            return back()->with('error', 'Lokasi tujuan sama dengan lokasi asal.');
        }

        // Dibaca SEBELUM transaksi: location_id baris asal tidak berubah, tapi
        // relasinya belum tentu masih termuat setelahnya.
        $asal = $stock->location?->code ?? '—';

        try {
            $this->pemindahan->pindahkan($stock, $tujuan, $qty, $validated['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::STOCK_TRANSFER,
            sprintf(
                'Memindahkan %d %s batch %s dari rak %s ke rak %s.',
                $qty,
                $stock->product?->sku ?? '—',
                $stock->batch_no ?? '—',
                $asal,
                $tujuan->code,
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'qty' => $qty,
                'dari_rak' => $asal,
                'ke_rak' => $tujuan->code,
                'alasan' => $validated['reason'],
            ],
        );

        return back()->with('success', sprintf(
            '%d %s batch %s dipindahkan ke %s.',
            $qty,
            $stock->product?->sku ?? '—',
            $stock->batch_no,
            $tujuan->code
        ));
    }
}
