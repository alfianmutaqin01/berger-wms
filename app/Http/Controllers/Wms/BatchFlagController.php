<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Support\Activity;
use App\Support\Inventory\StockQuarantine;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Penanda yang melekat pada satu BATCH, dari layar Data Stok: karantina,
 * Dahulukan Keluar, dan Quality Issue.
 *
 * Aturannya sendiri ada di App\Support\Inventory\StockQuarantine; controller
 * ini hanya menjaga gudang, mencatat log, dan menyusun kalimat untuk layar.
 */
class BatchFlagController extends Controller
{
    public function __construct(private readonly StockQuarantine $karantina) {}

    /**
     * Menahan satu batch sementara — permintaan pemilik produk (bukan PRD).
     *
     * SELURUH BARIS product+gudang+batch yang sama ikut ditahan, bukan cuma
     * baris yang dipilih di layar: karantina melekat pada hasil pemeriksaan
     * QC atas batch itu, bukan pada rak tempat sebagian isinya kebetulan
     * sekarang duduk. Lihat App\Support\Inventory\StockQuarantine::place().
     */
    public function quarantine(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'days' => 'lama karantina',
            'note' => 'catatan',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);

        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->place(
                $stock,
                (int) $validated['days'],
                $validated['note'] ?? null,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::QUARANTINE_PLACE,
            sprintf(
                'Mengarantina batch %s (%s) selama %d hari.',
                $stock->batch_no ?? '—',
                $stock->product?->sku ?? '—',
                $validated['days'],
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'hari' => (int) $validated['days'],
                'sampai' => $stock->fresh()->quarantine_until?->toDateString(),
                'baris' => $jumlah,
                'catatan' => $validated['note'] ?? null,
            ],
        );

        return back()->with('warning', sprintf(
            '%d baris stok batch %s (%s) dikarantina %d hari. Otomatis kembali jadi Good Stock setelah %s '.
            '— tidak perlu tindakan manual.',
            $jumlah,
            $stock->batch_no,
            $stock->product?->sku ?? '—',
            $validated['days'],
            $stock->fresh()->quarantine_until->translatedFormat('d M Y'),
        ));
    }

    /** Melepas karantina lebih awal, mis. QC selesai sebelum jangka waktunya. */
    public function releaseQuarantine(Request $request, InventoryStock $stock): RedirectResponse
    {
        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->release($stock, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::QUARANTINE_RELEASE,
            sprintf('Melepas karantina batch %s lebih awal.', $stock->batch_no ?? '—'),
            $stock,
            $stock->warehouse_id,
            ['batch' => $stock->batch_no, 'baris' => $jumlah],
        );

        return back()->with('success', sprintf(
            'Karantina dibatalkan untuk %d baris stok batch %s. Kembali jadi Good Stock.',
            $jumlah,
            $stock->batch_no,
        ));
    }

    /**
     * Menandai satu batch supaya KELUAR DULUAN, mendahului yang lebih tua.
     *
     * Kebalikan karantina, dan satu-satunya penanda yang benar-benar mengubah
     * urutan alokasi. Alasannya WAJIB — lihat StockQuarantine::prioritize().
     */
    public function prioritize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], [
            'reason' => 'alasan',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);

        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->prioritize($stock, $validated['reason'], $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::PRIORITIZE,
            sprintf(
                'Menandai batch %s (%s) DAHULUKAN KELUAR — mendahului batch yang lebih tua. Alasan: %s',
                $stock->batch_no ?? '—',
                $stock->product?->sku ?? '—',
                $validated['reason'],
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'baris' => $jumlah,
                'alasan' => $validated['reason'],
            ],
        );

        return back()->with('warning', sprintf(
            '%d baris stok batch %s (%s) ditandai DAHULUKAN KELUAR — batch ini akan dialokasikan lebih dulu '.
            'walau ada batch yang lebih tua. Lepas penandanya begitu tidak diperlukan lagi supaya FIFO kembali normal.',
            $jumlah,
            $stock->batch_no,
            $stock->product?->sku ?? '—',
        ));
    }

    /** Melepas penanda Dahulukan Keluar — batch kembali mengantre menurut umurnya. */
    public function releasePriority(Request $request, InventoryStock $stock): RedirectResponse
    {
        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->releasePriority($stock, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::PRIORITIZE_RELEASE,
            sprintf('Melepas penanda Dahulukan Keluar dari batch %s — urutan kembali FIFO.', $stock->batch_no ?? '—'),
            $stock,
            $stock->warehouse_id,
            ['batch' => $stock->batch_no, 'baris' => $jumlah],
        );

        return back()->with('success', sprintf(
            'Penanda Dahulukan Keluar dilepas dari %d baris stok batch %s. Urutan kembali FIFO.',
            $jumlah,
            $stock->batch_no,
        ));
    }

    /**
     * Menyalakan/mematikan penanda Quality Issue untuk satu batch.
     *
     * MURNI INFORMASI — tidak menyentuh status maupun kelayakan jual. Lihat
     * App\Support\Inventory\StockQuarantine::toggleQualityIssue().
     */
    public function toggleQualityIssue(Request $request, InventoryStock $stock): RedirectResponse
    {
        WarehouseScope::assert($stock->warehouse_id, $request->user());

        $hasil = $this->karantina->toggleQualityIssue($stock);

        Activity::record(
            ActivityLog::QUALITY_ISSUE,
            sprintf(
                '%s penanda Quality Issue pada batch %s (%s).',
                $hasil['nilai'] ? 'Memasang' : 'Melepas',
                $stock->batch_no ?? '—',
                $stock->product?->sku ?? '—',
            ),
            $stock,
            $stock->warehouse_id,
            ['batch' => $stock->batch_no, 'menyala' => $hasil['nilai'], 'baris' => $hasil['jumlah']],
        );

        // Kalimatnya sengaja menyebut ulang bahwa stoknya TIDAK ditahan.
        // Penanda bernama "Quality Issue" mudah dikira sudah mengunci
        // batch dari penjualan; kalau dikira begitu, batchnya justru tetap
        // terjual tanpa ada yang sadar.
        return back()->with('success', $hasil['nilai']
            ? sprintf(
                '%d baris stok batch %s ditandai "Quality Issue". Penanda ini tidak menahan stok — '.
                'pakai Karantina atau DDP kalau batch ini tidak boleh keluar gudang.',
                $hasil['jumlah'],
                $stock->batch_no,
            )
            : sprintf(
                'Penanda "Quality Issue" dilepas dari %d baris stok batch %s.',
                $hasil['jumlah'],
                $stock->batch_no,
            ));
    }
}
