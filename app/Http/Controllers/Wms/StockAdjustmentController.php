<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreInventoryStockRequest;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\StockMovement;
use App\Support\Activity;
use App\Support\Outbound\PendingAllocationFiller;
use App\Support\WarehouseScope;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Koreksi angka stok dari layar Data Stok — PRD §6.4 F-INV-02.
 *
 * Dua pintu yang sama-sama menulis gerakan ADJUSTMENT: mengoreksi baris
 * stok yang sudah ada, dan menambahkan baris yang belum pernah tercatat.
 * Keduanya membagikan stok yang bertambah ke pesanan yang tertahan lewat
 * PendingAllocationFiller.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(private readonly PendingAllocationFiller $pengisi) {}

    /**
     * F-INV-02: Stok Adjustment — Manager & Super Admin saja.
     *
     * Aturan yang membentuk method ini:
     *
     * 1. ALASAN WAJIB. Keputusan pemilik produk (docs/2 §3.4): tiap koreksi
     *    dicatat sebagai ADJUSTMENT dengan notes wajib + user pencatat.
     *    Angka stok yang berubah tanpa alasan tidak bisa diaudit.
     *
     * 2. YANG DIKOREKSI HANYA qty_available — stok BEBAS. Unit yang sudah
     *    dialokasikan ada di qty_allocated dan tidak tersentuh, jadi koreksi
     *    tidak bisa menghilangkan barang yang sudah dijanjikan ke pelanggan.
     *
     *    Temuan SQA: sebelumnya qty baru dilarang di bawah qty_allocated.
     *    Dua angka yang tidak berkaitan: batch dengan 2 unit bebas dan 10
     *    teralokasi tidak bisa dikoreksi ke 0/1/2 sama sekali — satu-satunya
     *    yang diterima formulir adalah >= 10, yang menciptakan 8 unit fiktif.
     *
     * 3. DDP DITOLAK BILA ADA ALOKASI. Status DDP berlaku untuk seluruh baris,
     *    sedangkan daftar picking dibangun dari alokasi tanpa melihat status
     *    batch — unit rusak yang teralokasi tetap diambil untuk pelanggan.
     *    Unit yang rusak dipindah dulu ke rak lain, lalu ditandai di sana.
     *
     * 4. DIKUNCI DAN DIBACA ULANG DI DALAM TRANSAKSI. Angka sebelum koreksi
     *    dan status alokasi yang dipakai adalah angka saat ditulis, bukan saat
     *    halaman dibuka — alokasi dan picking bisa berjalan di antaranya.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'qty_new' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'ddp_reason' => ['nullable', 'in:'.implode(',', array_keys(InventoryStock::DDP_REASON_LABELS))],
        ], [], [
            'qty_new' => 'qty baru',
            'reason' => 'alasan koreksi',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);

        // `stock_id` datang dari formulir dan bisa diganti nomor apa pun.
        // exists: hanya memastikan barisnya ADA, bukan bahwa barisnya boleh
        // disentuh user ini.
        WarehouseScope::assert($stock->warehouse_id, $request->user());

        $qtyBaru = (int) $validated['qty_new'];
        $ddp = $validated['ddp_reason'] ?? null;

        try {
            [$qtyLama, $susulan] = DB::transaction(function () use ($stock, $qtyBaru, $ddp, $validated, $request) {
                $terkunci = InventoryStock::query()->lockForUpdate()->findOrFail($stock->id);
                $qtyLama = (int) $terkunci->qty_available;

                if ($qtyBaru === $qtyLama && blank($ddp)) {
                    throw new RuntimeException('Tidak ada perubahan untuk disimpan.');
                }

                if ($ddp && $terkunci->qty_allocated > 0) {
                    throw new RuntimeException(sprintf(
                        'Batch ini punya %d unit yang sudah dialokasikan untuk pesanan. Kalau seluruh baris ditandai DDP, '.
                        'unit itu tetap diambil untuk pelanggan. Pindahkan dulu unit yang rusak ke rak lain, lalu tandai DDP di rak itu.',
                        $terkunci->qty_allocated,
                    ));
                }

                $terkunci->qty_available = $qtyBaru;

                // Menandai DDP adalah perubahan STATUS, bukan perubahan qty —
                // barangnya masih ada di rak, hanya tidak boleh dijual.
                if ($ddp) {
                    $terkunci->status = InventoryStock::STATUS_DDP;
                    $terkunci->ddp_reason = $ddp;
                }

                $terkunci->save();

                StockMovement::create([
                    'product_id' => $terkunci->product_id,
                    'location_id' => $terkunci->location_id,
                    'warehouse_id' => $terkunci->warehouse_id,
                    'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                    'qty_change' => $qtyBaru - $qtyLama,
                    'qty_before' => $qtyLama,
                    'qty_after' => $qtyBaru,
                    'reference_type' => StockMovement::REF_ADJUSTMENT,
                    'reference_id' => $terkunci->id,
                    'batch_no' => $terkunci->batch_no,
                    'notes' => $validated['reason'],
                    'user_id' => $request->user()?->id,
                ]);

                // Stok BERTAMBAH berarti pesanan yang tertahan mungkin sudah bisa
                // dipenuhi. Hanya saat bertambah — koreksi yang mengurangi tidak
                // punya apa pun untuk dibagikan. Stok yang baru saja ditandai DDP
                // juga dilewati: barangnya ada, tapi tidak boleh dijual.
                $bertambah = $qtyBaru > $qtyLama && $terkunci->status === InventoryStock::STATUS_ACTIVE;

                return [$qtyLama, $bertambah
                    ? $this->pengisi->fill($terkunci->product_id, $terkunci->warehouse_id, $request->user()?->id)
                    : ['terisi' => 0, 'pesanan' => [], 'booking' => []]];
            });
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::STOCK_ADJUST,
            sprintf(
                'Mengoreksi %s batch %s dari %d menjadi %d%s.',
                $stock->product?->sku ?? '—',
                $stock->batch_no ?? '—',
                $qtyLama,
                $qtyBaru,
                ($validated['ddp_reason'] ?? null) ? ' dan menandainya DDP' : '',
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'qty_sebelum' => $qtyLama,
                'qty_sesudah' => $qtyBaru,
                'selisih' => $qtyBaru - $qtyLama,
                'ddp' => $validated['ddp_reason'] ?? null,
                'alasan' => $validated['reason'],
            ],
        );

        $pesan = sprintf(
            'Koreksi tersimpan: %s batch %s dari %d menjadi %d.',
            $stock->product?->sku ?? '—',
            $stock->batch_no,
            $qtyLama,
            $qtyBaru
        );

        if ($ringkas = $this->pengisi->ringkasan($susulan)) {
            return back()->with('warning', $pesan.' '.$ringkas);
        }

        return back()->with('success', $pesan);
    }

    /**
     * F-INV-02 diperluas: menambahkan baris stok yang BELUM PERNAH tercatat.
     *
     * adjust() hanya bisa mengoreksi baris yang sudah ada. Sistem ini dipasang
     * di gudang yang sudah berjalan, jadi banyak barang fisiknya di rak tetapi
     * belum punya baris untuk dikoreksi. Tanpa pintu ini satu-satunya jalan
     * adalah memalsukan dokumen inbound.
     *
     * Batch yang sama, di rak yang sama, dari tanggal produksi yang sama
     * DIGABUNG ke baris yang ada — aturan yang sama dengan StockActivator,
     * supaya tidak muncul dua baris kembar yang harus dijumlahkan manual
     * setiap kali dilihat.
     */
    public function store(StoreInventoryStockRequest $request): RedirectResponse
    {
        $produk = $request->produk;
        $lokasi = $request->lokasi;

        // Gudangnya ditentukan RAK yang dipilih, bukan isian tersendiri —
        // karena itu penjagaannya dipasang pada rak itu.
        WarehouseScope::assert($lokasi->warehouse_id, $request->user());

        $qty = (int) $request->validated('qty');
        $tanggal = Carbon::parse($request->validated('production_date'));

        $hasil = DB::transaction(function () use ($request, $produk, $lokasi, $qty, $tanggal) {
            $stock = InventoryStock::query()
                ->where('product_id', $produk->id)
                ->where('location_id', $lokasi->id)
                ->where('batch_no', $request->validated('batch_no'))
                ->whereDate('production_date', $tanggal->toDateString())
                ->lockForUpdate()
                ->first();

            $sebelum = $stock?->qty_available ?? 0;

            if ($stock === null) {
                $stock = new InventoryStock([
                    'product_id' => $produk->id,
                    'location_id' => $lokasi->id,
                    'warehouse_id' => $lokasi->warehouse_id,
                    'batch_no' => $request->validated('batch_no'),
                    'qty_allocated' => 0,
                    'production_date' => $tanggal->toDateString(),
                    // Aturan kedaluwarsa yang SAMA dengan jalur inbound
                    // (§7.2.1). Kalau dihitung berbeda di sini, dua batch
                    // identik bisa punya tanggal kedaluwarsa berbeda
                    // tergantung lewat pintu mana ia masuk.
                    'expiry_date' => InventoryStock::calculateExpiry(
                        $tanggal,
                        $produk->shelf_life_months
                    )->toDateString(),
                    'status' => InventoryStock::STATUS_ACTIVE,
                ]);
            }

            $stock->qty_available = $sebelum + $qty;
            $stock->verified_by = $request->user()?->id;
            $stock->verified_at = now();
            $stock->save();

            StockMovement::create([
                'product_id' => $stock->product_id,
                'location_id' => $stock->location_id,
                'warehouse_id' => $stock->warehouse_id,
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'qty_change' => $qty,
                'qty_before' => $sebelum,
                'qty_after' => $stock->qty_available,
                'reference_type' => StockMovement::REF_ADJUSTMENT,
                'reference_id' => $stock->id,
                'batch_no' => $stock->batch_no,
                'notes' => $request->validated('reason'),
                'user_id' => $request->user()?->id,
            ]);

            return [
                'baru' => $sebelum === 0,
                'total' => $stock->qty_available,
                'susulan' => $this->pengisi->fill($stock->product_id, $stock->warehouse_id, $request->user()?->id),
            ];
        });

        Activity::record(
            ActivityLog::STOCK_ADD,
            sprintf(
                'Menambahkan %d unit %s batch %s ke rak %s (gudang %s). Total batch di rak itu jadi %d.',
                $qty,
                $produk->sku,
                $request->validated('batch_no'),
                $lokasi->code,
                $lokasi->warehouse?->code ?? '—',
                $hasil['total'],
            ),
            $lokasi,
            $lokasi->warehouse_id,
            [
                'sku' => $produk->sku,
                'batch' => $request->validated('batch_no'),
                'qty' => $qty,
                'rak' => $lokasi->code,
                'baris_baru' => $hasil['baru'],
                'alasan' => $request->validated('reason'),
            ],
        );

        $pesan = sprintf(
            '%s batch %s di %s: %s %d unit (total sekarang %d).',
            $produk->sku,
            $request->validated('batch_no'),
            $lokasi->code,
            $hasil['baru'] ? 'ditambahkan' : 'ditambah',
            $qty,
            $hasil['total']
        );

        // Alokasi otomatis WAJIB dilaporkan. Tanpa kalimat ini Manager
        // mengira menambah 50, yang bebas ternyata 35, dan tidak ada apa pun
        // di layar yang menjelaskan ke mana 15 sisanya pergi.
        if ($ringkas = $this->pengisi->ringkasan($hasil['susulan'])) {
            return back()->with('warning', $pesan.' '.$ringkas);
        }

        return back()->with('success', $pesan);
    }
}
