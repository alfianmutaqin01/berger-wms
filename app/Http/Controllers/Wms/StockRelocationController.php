<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\StockMovement;
use App\Support\Activity;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pindah rak di dalam satu gudang, dari layar Data Stok.
 *
 * Bukan transfer antar gudang — yang itu punya dokumen dan picking sendiri
 * di StockTransferController.
 */
class StockRelocationController extends Controller
{
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

        /*
         * DIKUNCI DAN DIBACA ULANG DI DALAM TRANSAKSI (temuan SQA).
         *
         * Sebelumnya qty asal dibaca saat permintaan masuk lalu ditulis sebagai
         * "angka lama dikurangi qty". Klik ganda pada tombol Pindahkan
         * menjalankan dua permintaan yang sama-sama membaca 100: keduanya lolos
         * pemeriksaan, asal ditulis 40 dua kali, tetapi rak tujuan bertambah 60
         * dua kali — 60 unit tercipta dari udara. Picking atau alokasi yang
         * mengubah baris yang sama di antaranya juga tertimpa diam-diam.
         */
        try {
            DB::transaction(function () use ($stock, $tujuan, $qty, $validated, $request) {
                $stock = InventoryStock::query()->lockForUpdate()->findOrFail($stock->id);

                if ($qty > $stock->qty_available) {
                    throw new RuntimeException(sprintf(
                        'Qty pindah (%d) melebihi stok tersedia (%d). Stoknya baru saja berubah — muat ulang halaman.',
                        $qty,
                        $stock->qty_available
                    ));
                }

                $asalSebelum = $stock->qty_available;
                $stock->qty_available = $asalSebelum - $qty;
                $stock->save();

                // Batch yang sama di rak tujuan digabung, bukan dibuat baris baru
                // kembar yang harus dijumlahkan manual setiap kali dilihat. Ikut
                // dikunci: dua pemindahan ke rak yang sama tidak boleh saling
                // menimpa jumlahnya.
                $kunciTujuan = [
                    'product_id' => $stock->product_id,
                    'location_id' => $tujuan->id,
                    'batch_no' => $stock->batch_no,
                    'production_date' => $stock->production_date->toDateString(),
                ];
                $tujuanStok = InventoryStock::query()->where($kunciTujuan)->lockForUpdate()->first()
                    ?? new InventoryStock($kunciTujuan);

                $tujuanSebelum = $tujuanStok->exists ? $tujuanStok->qty_available : 0;

                if (! $tujuanStok->exists) {
                    $tujuanStok->fill([
                        'warehouse_id' => $stock->warehouse_id,
                        'qty_allocated' => 0,
                        // Kedaluwarsa & status IKUT dari asalnya, tidak dihitung ulang.
                        'expiry_date' => $stock->expiry_date->toDateString(),
                        'status' => $stock->status,
                        'ddp_reason' => $stock->ddp_reason,
                        'inbound_detail_id' => $stock->inbound_detail_id,
                        'verified_by' => $stock->verified_by,
                        'verified_at' => $stock->verified_at,

                        // SELURUH PENANDA BATCH IKUT PINDAH. Ketiganya melekat pada
                        // batch — pada apa yang terjadi saat produksi/pengujian —
                        // bukan pada rak tempat barangnya kebetulan duduk. Baris
                        // baru tanpa penanda akan membuat separuh batch dikarantina
                        // dan separuhnya bebas dijual, padahal barangnya sama.
                        //
                        // Metadata karantina WAJIB ikut, bukan cuma statusnya:
                        // CHECK inventory_stocks_karantina_lengkap menolak baris
                        // berstatus 'quarantine' yang tanggal & pemasangnya kosong,
                        // jadi tanpa ini memindahkan batch terkarantina gagal
                        // dengan galat constraint mentah.
                        'quarantine_days' => $stock->quarantine_days,
                        'quarantine_until' => $stock->quarantine_until?->toDateString(),
                        'quarantined_at' => $stock->quarantined_at,
                        'quarantined_by' => $stock->quarantined_by,
                        'quarantine_note' => $stock->quarantine_note,
                        'quarantine_released_at' => $stock->quarantine_released_at,

                        'has_quality_issue' => $stock->has_quality_issue,

                        'prioritize_out' => $stock->prioritize_out,
                        'prioritize_reason' => $stock->prioritize_reason,
                        'prioritized_at' => $stock->prioritized_at,
                        'prioritized_by' => $stock->prioritized_by,
                        'prioritize_released_at' => $stock->prioritize_released_at,
                    ]);
                }

                $tujuanStok->qty_available = $tujuanSebelum + $qty;
                $tujuanStok->save();

                $jejak = [
                    'product_id' => $stock->product_id,
                    'warehouse_id' => $stock->warehouse_id,
                    'reference_type' => StockMovement::REF_STOCK_TRANSFER,
                    'reference_id' => $stock->id,
                    'batch_no' => $stock->batch_no,
                    'notes' => $validated['reason'],
                    'user_id' => $request->user()?->id,
                ];

                StockMovement::create($jejak + [
                    'location_id' => $stock->location_id,
                    'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
                    'qty_change' => -$qty,
                    'qty_before' => $asalSebelum,
                    'qty_after' => $stock->qty_available,
                ]);

                StockMovement::create($jejak + [
                    'location_id' => $tujuan->id,
                    'movement_type' => StockMovement::TYPE_TRANSFER_IN,
                    'qty_change' => $qty,
                    'qty_before' => $tujuanSebelum,
                    'qty_after' => $tujuanStok->qty_available,
                ]);
            });
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
