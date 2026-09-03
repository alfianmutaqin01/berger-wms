<?php

namespace App\Support\Inventory;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use App\Support\DocumentNumber;
use App\Support\Outbound\PendingAllocationFiller;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Perpindahan stok antar gudang — PRD F-INV-05.
 *
 * SELURUH aturan buku besarnya ada di sini, bukan tersebar di controller.
 * Stok adalah angka yang dipercaya keuangan; kalau jalur tulisnya lebih dari
 * satu, cepat atau lambat salah satunya lupa menulis mutasi.
 *
 * TIGA PERPINDAHAN, BUKAN SATU
 * ----------------------------
 *   ship()    TRANSFER_OUT di gudang asal. Barang keluar dari stok, tetapi
 *             BELUM masuk ke mana pun — ia sedang di jalan.
 *   receive() TRANSFER_IN di gudang tujuan, sebanyak yang benar-benar sampai.
 *   cancel()  TRANSFER_IN di gudang ASAL, mengembalikan yang belum berangkat.
 *
 * KEHILANGAN DI PERJALANAN TIDAK PUNYA MUTASI SENDIRI. Barangnya sudah
 * dikurangi saat ship() dan memang tidak pernah ditambahkan saat receive();
 * menuliskan mutasi ketiga akan menghitungnya dua kali. Yang wajib ada adalah
 * alasannya di `discrepancy_reason`, supaya angka yang hilang tidak pernah
 * hilang tanpa keterangan.
 *
 * YANG IKUT PINDAH DAN YANG TIDAK
 * -------------------------------
 *   IKUT  : batch_no, production_date, expiry_date, status, ddp_reason
 *   RESET : lokasi rak — penomoran rak tiap gudang berbeda
 *
 * Umur barang tidak boleh lahir kembali karena berpindah gudang. Kalau
 * production_date dihitung ulang, FIFO di gudang tujuan menganggap barang
 * lama sebagai barang baru, dan penarikan stok yang mendekati kedaluwarsa
 * kembali ke Karawang jadi mustahil — umurnya sudah hilang.
 */
class WarehouseTransfer
{
    public function __construct(private readonly PendingAllocationFiller $pengisi) {}

    /**
     * Mengirim beberapa batch dari satu gudang ke gudang lain.
     *
     * @param  list<array{stock_id:int, qty:int}>  $baris
     *
     * @throws RuntimeException bila stok tidak cukup atau tidak layak kirim
     */
    public function ship(int $fromWarehouseId, int $toWarehouseId, array $baris, ?string $catatan, ?int $userId): StockTransfer
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new RuntimeException('Gudang asal dan tujuan tidak boleh sama. Untuk memindahkan antar rak, gunakan tombol Pindah di Data Stok.');
        }

        if ($baris === []) {
            throw new RuntimeException('Tidak ada batch yang dipilih untuk dikirim.');
        }

        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $baris, $catatan, $userId) {
            $transfer = StockTransfer::create([
                'transfer_number' => DocumentNumber::forStockTransfer(),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'notes' => $catatan,
                'shipped_at' => now(),
                'shipped_by' => $userId,
            ]);

            foreach ($baris as $item) {
                $this->kirimSatuBatch($transfer, (int) $item['stock_id'], (int) $item['qty'], $userId);
            }

            return $transfer;
        });
    }

    private function kirimSatuBatch(StockTransfer $transfer, int $stockId, int $qty, ?int $userId): void
    {
        // Dikunci: angka yang dilihat pengirim di layar BISA SUDAH BASI saat
        // tombol ditekan — alokasi pesanan atau transfer lain mungkin sudah
        // mengambil batch yang sama di sela itu.
        $stok = InventoryStock::query()->lockForUpdate()->find($stockId);

        if ($stok === null) {
            throw new RuntimeException('Salah satu batch yang dipilih sudah tidak ada. Muat ulang halaman lalu pilih lagi.');
        }

        if ($stok->warehouse_id !== $transfer->from_warehouse_id) {
            throw new RuntimeException("Batch {$stok->batch_no} bukan milik gudang asal transfer ini.");
        }

        if ($qty < 1) {
            throw new RuntimeException("Qty kirim untuk batch {$stok->batch_no} harus minimal 1.");
        }

        if ($qty > $stok->qty_available) {
            throw new RuntimeException(sprintf(
                'Qty kirim untuk batch %s (%d) melebihi stok tersedia (%d). Sisanya mungkin sudah dialokasikan ke pesanan.',
                $stok->batch_no,
                $qty,
                $stok->qty_available
            ));
        }

        $sebelum = $stok->qty_available;
        $stok->qty_available = $sebelum - $qty;
        $stok->save();

        $detail = $transfer->details()->create([
            'product_id' => $stok->product_id,
            'source_stock_id' => $stok->id,
            'batch_no' => $stok->batch_no,
            'production_date' => $stok->production_date->toDateString(),
            'expiry_date' => $stok->expiry_date->toDateString(),
            'status' => $stok->status,
            'ddp_reason' => $stok->ddp_reason,
            'qty_shipped' => $qty,
        ]);

        StockMovement::create([
            'product_id' => $stok->product_id,
            'location_id' => $stok->location_id,
            'warehouse_id' => $stok->warehouse_id,
            'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
            'qty_change' => -$qty,
            'qty_before' => $sebelum,
            'qty_after' => $stok->qty_available,
            'reference_type' => StockMovement::REF_STOCK_TRANSFER,
            'reference_id' => $transfer->id,
            'batch_no' => $stok->batch_no,
            'notes' => sprintf(
                'Kirim %s ke gudang %s (%s baris #%d).',
                $transfer->transfer_number,
                $transfer->toWarehouse?->name ?? 'tujuan',
                $transfer->transfer_number,
                $detail->id
            ),
            'user_id' => $userId,
        ]);
    }

    /**
     * Menerima kiriman di gudang tujuan.
     *
     * @param  array<int, array{qty:int, location_code:string, reason:?string}>  $isian
     *                                                                                   dikunci nomor id baris detail
     * @return array{diterima:int, hilang:int, susulan:list<string>}
     *
     * @throws RuntimeException
     */
    public function receive(StockTransfer $transfer, array $isian, ?int $userId): array
    {
        return DB::transaction(function () use ($transfer, $isian, $userId) {
            $terkunci = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            // Diperiksa ULANG di dalam kunci: dua orang yang membuka layar
            // penerimaan yang sama sama-sama lolos pemeriksaan di layar.
            if (! $terkunci->isInTransit()) {
                throw new RuntimeException(sprintf(
                    'Transfer %s sudah %s dan tidak bisa diterima lagi.',
                    $terkunci->transfer_number,
                    strtolower($terkunci->status_label)
                ));
            }

            $diterima = 0;
            $hilang = 0;
            $susulan = [];

            foreach ($terkunci->details()->with('product:id,sku,name')->get() as $detail) {
                $baris = $isian[$detail->id] ?? null;

                if ($baris === null) {
                    throw new RuntimeException("Baris batch {$detail->batch_no} belum diisi qty diterimanya.");
                }

                $hasil = $this->terimaSatuBatch($terkunci, $detail, $baris, $userId);

                $diterima += $hasil['diterima'];
                $hilang += $hasil['hilang'];

                if ($hasil['susulan'] !== null) {
                    $susulan[] = $hasil['susulan'];
                }
            }

            $terkunci->fill([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $userId,
            ])->save();

            return ['diterima' => $diterima, 'hilang' => $hilang, 'susulan' => $susulan];
        });
    }

    /**
     * @param  array{qty:int, location_code:string, reason:?string}  $baris
     * @return array{diterima:int, hilang:int, susulan:?string}
     */
    private function terimaSatuBatch(StockTransfer $transfer, StockTransferDetail $detail, array $baris, ?int $userId): array
    {
        $qty = (int) $baris['qty'];
        $kurang = $detail->qty_shipped - $qty;

        if ($qty < 0 || $qty > $detail->qty_shipped) {
            throw new RuntimeException(sprintf(
                'Qty diterima batch %s harus antara 0 dan %d (yang dikirim). Kelebihan berarti hitungan di gudang asal yang keliru — perbaiki lewat Penyesuaian Stok.',
                $detail->batch_no,
                $detail->qty_shipped
            ));
        }

        if ($kurang > 0 && blank($baris['reason'] ?? null)) {
            throw new RuntimeException(sprintf(
                'Batch %s kurang %d unit dari yang dikirim. Alasannya wajib diisi — angka yang hilang tidak boleh hilang tanpa keterangan.',
                $detail->batch_no,
                $kurang
            ));
        }

        $rak = $this->rakTujuan($transfer, $detail, (string) ($baris['location_code'] ?? ''), $qty);

        $detail->fill([
            'qty_received' => $qty,
            'to_location_id' => $rak?->id,
            'discrepancy_reason' => $kurang > 0 ? $baris['reason'] : null,
        ])->save();

        if ($qty === 0) {
            // Tidak ada yang sampai. Tidak ada stok yang dibuat, tidak ada
            // mutasi masuk — yang tersisa hanya catatan bahwa ia hilang.
            return ['diterima' => 0, 'hilang' => $kurang, 'susulan' => null];
        }

        $stok = $this->stokTujuan($transfer, $detail, $rak, $qty, $userId);

        StockMovement::create([
            'product_id' => $detail->product_id,
            'location_id' => $rak->id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'movement_type' => StockMovement::TYPE_TRANSFER_IN,
            'qty_change' => $qty,
            'qty_before' => $stok['sebelum'],
            'qty_after' => $stok['sesudah'],
            'reference_type' => StockMovement::REF_STOCK_TRANSFER,
            'reference_id' => $transfer->id,
            'batch_no' => $detail->batch_no,
            'notes' => $kurang > 0
                ? sprintf('Terima %s dari gudang %s. Kurang %d unit: %s',
                    $transfer->transfer_number,
                    $transfer->fromWarehouse?->name ?? 'asal',
                    $kurang,
                    $baris['reason'])
                : sprintf('Terima %s dari gudang %s, lengkap.',
                    $transfer->transfer_number,
                    $transfer->fromWarehouse?->name ?? 'asal'),
            'user_id' => $userId,
        ]);

        // Stok baru di gudang tujuan langsung dipakai menutup pesanan yang
        // sudah disetujui tetapi menunggu stok — aturan yang sama dengan
        // Penyesuaian Stok dan Impor Stok Awal (Fase 6 tahap 2). Kalau
        // dilewatkan di sini, kiriman antar gudang jadi satu-satunya pintu
        // masuk stok yang TIDAK menyusul pesanan tertunda.
        $ringkas = null;

        if ($detail->status === InventoryStock::STATUS_ACTIVE) {
            $ringkas = $this->pengisi->ringkasan(
                $this->pengisi->fill($detail->product_id, $transfer->to_warehouse_id, $userId)
            );
        }

        return ['diterima' => $qty, 'hilang' => max(0, $kurang), 'susulan' => $ringkas];
    }

    /** Rak tujuan; NULL hanya sah bila tidak ada satu unit pun yang sampai. */
    private function rakTujuan(StockTransfer $transfer, StockTransferDetail $detail, string $kode, int $qty): ?Location
    {
        $kode = strtoupper(trim($kode));

        if ($qty === 0) {
            return null;
        }

        if ($kode === '') {
            throw new RuntimeException("Batch {$detail->batch_no} belum diisi kode raknya di gudang tujuan.");
        }

        $rak = Location::query()
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->active()
            ->whereRaw('UPPER(code) = ?', [$kode])
            ->first();

        if ($rak === null) {
            throw new RuntimeException(sprintf(
                'Rak "%s" tidak ada atau tidak aktif di gudang %s. Penomoran rak tiap gudang berbeda — pakai kode rak setempat, bukan kode dari gudang asal.',
                $kode,
                $transfer->toWarehouse?->name ?? 'tujuan'
            ));
        }

        return $rak;
    }

    /**
     * Membuat atau menambah baris stok di gudang tujuan.
     *
     * Batch yang sama, di rak yang sama, dari tanggal produksi yang sama
     * DIGABUNG — aturan yang sama dengan StockActivator dan Tambah Stok,
     * supaya tidak muncul dua baris kembar yang harus dijumlahkan manual
     * setiap kali dilihat.
     *
     * @return array{sebelum:int, sesudah:int}
     */
    private function stokTujuan(StockTransfer $transfer, StockTransferDetail $detail, Location $rak, int $qty, ?int $userId): array
    {
        $stok = InventoryStock::query()
            ->where('product_id', $detail->product_id)
            ->where('location_id', $rak->id)
            ->where('batch_no', $detail->batch_no)
            ->whereDate('production_date', $detail->production_date->toDateString())
            ->lockForUpdate()
            ->first();

        $sebelum = $stok?->qty_available ?? 0;

        if ($stok === null) {
            $stok = new InventoryStock([
                'product_id' => $detail->product_id,
                'location_id' => $rak->id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'batch_no' => $detail->batch_no,
                'qty_allocated' => 0,
                // Tanggal produksi dan kedaluwarsa DISALIN, tidak dihitung
                // ulang: umur barang tidak lahir kembali karena berpindah.
                'production_date' => $detail->production_date->toDateString(),
                'expiry_date' => $detail->expiry_date->toDateString(),
                'status' => $detail->status,
                'ddp_reason' => $detail->ddp_reason,
            ]);
        }

        $stok->qty_available = $sebelum + $qty;
        $stok->verified_by = $userId;
        $stok->verified_at = now();
        $stok->save();

        return ['sebelum' => $sebelum, 'sesudah' => $stok->qty_available];
    }

    /**
     * Membatalkan kiriman yang ternyata tidak jadi berangkat.
     *
     * Stoknya DIKEMBALIKAN ke rak asal. Tanpa pintu ini, transfer yang
     * telanjur dibuat salah akan menahan barangnya selamanya di keadaan
     * "dalam perjalanan" yang tidak dimiliki gudang mana pun.
     *
     * @throws RuntimeException
     */
    public function cancel(StockTransfer $transfer, string $alasan, ?int $userId): void
    {
        DB::transaction(function () use ($transfer, $alasan, $userId) {
            $terkunci = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (! $terkunci->isInTransit()) {
                throw new RuntimeException(sprintf(
                    'Transfer %s sudah %s dan tidak bisa dibatalkan lagi.',
                    $terkunci->transfer_number,
                    strtolower($terkunci->status_label)
                ));
            }

            foreach ($terkunci->details as $detail) {
                $this->kembalikanKeAsal($terkunci, $detail, $alasan, $userId);
            }

            $terkunci->fill([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $alasan,
            ])->save();
        });
    }

    private function kembalikanKeAsal(StockTransfer $transfer, StockTransferDetail $detail, string $alasan, ?int $userId): void
    {
        $stok = $detail->source_stock_id
            ? InventoryStock::query()->lockForUpdate()->find($detail->source_stock_id)
            : null;

        // Baris stok asal HARUS masih ada. Tanpanya kita tahu batch dan
        // tanggalnya, tetapi TIDAK tahu ia berasal dari rak yang mana — dan
        // menebak rak berarti menaruh barang di tempat yang nanti dicari
        // orang dan tidak ketemu. Lebih jujur ditolak dengan jalan keluar
        // yang jelas daripada dikembalikan ke rak karangan.
        if ($stok === null) {
            throw new RuntimeException(sprintf(
                'Baris stok asal batch %s sudah tidak ada, sehingga raknya tidak diketahui. '.
                'Transfer ini tidak bisa dibatalkan otomatis — kembalikan stoknya lewat Tambah Stok di gudang asal, lalu terima transfer ini dengan qty 0.',
                $detail->batch_no
            ));
        }

        $sebelum = $stok->qty_available;
        $stok->qty_available = $sebelum + $detail->qty_shipped;
        $stok->save();

        StockMovement::create([
            'product_id' => $detail->product_id,
            'location_id' => $stok->location_id,
            'warehouse_id' => $transfer->from_warehouse_id,
            'movement_type' => StockMovement::TYPE_TRANSFER_IN,
            'qty_change' => $detail->qty_shipped,
            'qty_before' => $sebelum,
            'qty_after' => $stok->qty_available,
            'reference_type' => StockMovement::REF_STOCK_TRANSFER,
            'reference_id' => $transfer->id,
            'batch_no' => $detail->batch_no,
            'notes' => "Pembatalan {$transfer->transfer_number}, stok dikembalikan ke gudang asal: {$alasan}",
            'user_id' => $userId,
        ]);
    }
}
