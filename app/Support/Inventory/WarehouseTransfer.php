<?php

namespace App\Support\Inventory;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use App\Models\Warehouse;
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
 * TRANSFER MENEMPUH PICKING, PERSIS SEPERTI PESANAN PELANGGAN
 * -----------------------------------------------------------
 * Permintaan pemilik produk. Dulu tombol Kirim mengurangi stok saat itu juga
 * dan langsung menyatakan barangnya dalam perjalanan — padahal belum ada
 * seorang pun yang berjalan ke rak dan mengangkatnya. Angka di sistem
 * berangkat lebih dulu daripada barangnya, dan kalau di rak ternyata kurang,
 * tidak ada satu langkah pun dalam alur itu yang bisa mengatakannya.
 *
 *   request()  Admin menyusun transfer. Barangnya DICADANGKAN di gudang asal
 *              (ALLOCATED) dan daftar picking-nya masuk antrean operator.
 *              Belum ada yang berangkat.
 *   loading()  Operator selesai picking dan menekan Loading. Barang benar-
 *              benar turun dari rak; TRANSFER_OUT ditulis oleh PickingRun,
 *              bukan di sini — lihat alasannya di kelas itu.
 *   receive()  TRANSFER_IN di gudang tujuan, sebanyak yang benar-benar sampai.
 *   cancel()   Melepas cadangan (masih pending) atau mengembalikan barang ke
 *              gudang asal (sudah dalam perjalanan).
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
     * Menyusun transfer: mencadangkan batch yang dipilih dan mengantrekannya
     * ke daftar picking.
     *
     * TIDAK ADA YANG BERANGKAT DI SINI. Yang terjadi cuma satu: barang yang
     * dipilih berhenti bisa dijual siapa pun, dan seorang operator mendapat
     * tugas mengambilnya dari rak. Keberangkatannya menyusul di loading().
     *
     * @param  list<array{stock_id:int, qty:int}>  $baris
     *
     * @throws RuntimeException bila stok tidak cukup atau tidak layak kirim
     */
    public function request(int $fromWarehouseId, int $toWarehouseId, array $baris, ?string $catatan, ?int $userId): StockTransfer
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new RuntimeException('Gudang asal dan tujuan tidak boleh sama. Untuk memindahkan antar rak, gunakan tombol Pindah di Data Stok.');
        }

        if ($baris === []) {
            throw new RuntimeException('Tidak ada batch yang dipilih untuk dikirim.');
        }

        $this->pastikanTujuanPunyaRak($toWarehouseId);

        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $baris, $catatan, $userId) {
            $daftar = PickingList::create([
                'list_number' => DocumentNumber::forPickingList(),
                // Daftarnya milik gudang ASAL — di sanalah orangnya berjalan
                // mengambil barang. Gudang tujuan baru muncul saat penerimaan.
                'warehouse_id' => $fromWarehouseId,
                'status' => PickingList::STATUS_OPEN,
                'created_by' => $userId,
                'notes' => $catatan,
            ]);

            $transfer = StockTransfer::create([
                'transfer_number' => DocumentNumber::forStockTransfer(),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'status' => StockTransfer::STATUS_PENDING,
                'picking_list_id' => $daftar->id,
                'notes' => $catatan,
                'requested_at' => now(),
                'requested_by' => $userId,
            ]);

            foreach ($baris as $item) {
                $this->cadangkanSatuBatch($transfer, $daftar, (int) $item['stock_id'], (int) $item['qty'], $userId);
            }

            return $transfer;
        });
    }

    /**
     * Operator menekan Loading: transfernya benar-benar berangkat.
     *
     * WAJIB dipanggil di dalam transaksi milik PickingRun::complete(), dan
     * SESUDAH baris-barisnya dikeluarkan dari rak — angka qty_picked yang
     * dibaca di sini adalah hasil pekerjaan operator, bukan angka yang diminta
     * Admin. Keduanya sengaja disimpan terpisah: selisihnya adalah barang yang
     * ternyata tidak ada di rak, dan itu pertanyaan yang akan ditanyakan
     * gudang tujuan begitu kirimannya kurang.
     *
     * @return array{berangkat:int, kurang:int}
     */
    public function loading(StockTransfer $transfer, PickingList $daftar, ?int $userId): array
    {
        $terkunci = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

        if (! $terkunci->isPending()) {
            throw new RuntimeException(sprintf(
                'Transfer %s berstatus "%s", bukan menunggu picking. Daftar ini tidak bisa diberangkatkan lagi.',
                $terkunci->transfer_number,
                $terkunci->status_label,
            ));
        }

        $berangkat = 0;
        $kurang = 0;

        foreach ($terkunci->details as $detail) {
            $item = $daftar->items()->where('stock_transfer_detail_id', $detail->id)->first();

            // Baris yang tidak punya pasangan di daftar picking berarti
            // daftarnya disusun ulang di luar alur ini. Diperlakukan sebagai
            // tidak terambil — bukan diam-diam dianggap berangkat penuh.
            $diambil = $item === null ? 0 : (int) $item->qty_picked;

            $detail->forceFill(['qty_shipped' => $diambil])->save();

            $berangkat += $diambil;
            $kurang += max(0, (int) $detail->qty_requested - $diambil);
        }

        $terkunci->fill([
            'status' => StockTransfer::STATUS_IN_TRANSIT,
            'shipped_at' => now(),
            'shipped_by' => $userId,
        ])->save();

        return ['berangkat' => $berangkat, 'kurang' => $kurang];
    }

    /**
     * Menolak kiriman ke gudang yang belum punya satu rak pun.
     *
     * KENAPA DITAHAN DI KEBERANGKATAN, BUKAN DI PENERIMAAN
     * ----------------------------------------------------
     * Stok WAJIB tinggal di sebuah rak — inventory_stocks.location_id NOT
     * NULL — jadi gudang tanpa master rak tidak punya tempat menaruh
     * barangnya. Sebelum pemeriksaan ini, kiriman tetap berangkat: stoknya
     * keluar dari gudang asal, tercatat DALAM PERJALANAN, lalu tersangkut di
     * sana selamanya karena layar penerimaannya hanya menyodorkan daftar rak
     * yang kosong tanpa menjelaskan apa-apa.
     *
     * Itulah yang terjadi pada TF260910003 ke Sidoarjo/Surabaya. Barangnya
     * bukan hilang, tetapi tidak ada di gudang mana pun — dan tidak ada satu
     * layar pun yang mengatakan kenapa.
     *
     * Ditahan di keberangkatan, barangnya masih di rak asalnya dan yang perlu
     * dikerjakan jelas: isi dulu master rak gudang tujuan.
     *
     * @throws RuntimeException
     */
    private function pastikanTujuanPunyaRak(int $toWarehouseId): void
    {
        if (Location::where('warehouse_id', $toWarehouseId)->active()->exists()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Gudang %s belum punya satu rak aktif pun, sehingga tidak ada tempat menaruh barangnya saat tiba. '
                .'Isi dulu Master Rak gudang itu lewat menu Master Data → Rak, baru kirimannya bisa berangkat. '
                .'Kalau tetap dikirim, stoknya keluar dari gudang Anda dan tersangkut dalam perjalanan tanpa bisa diterima siapa pun.',
            Warehouse::find($toWarehouseId)?->name ?? 'tujuan',
        ));
    }

    /**
     * Mencadangkan satu batch dan menuliskan barisnya di daftar picking.
     *
     * DICADANGKAN, BUKAN DIKURANGI. qty_available turun dan qty_allocated naik
     * sebesar yang sama — barangnya masih di rak, masih milik gudang ini,
     * tetapi tidak bisa dijanjikan ke pelanggan mana pun. Pola yang sama
     * persis dengan FifoAllocator, dan memang harus sama: kalau transfer
     * memakai aturan buku besar sendiri, cepat atau lambat salah satunya lupa
     * menulis mutasi.
     */
    private function cadangkanSatuBatch(StockTransfer $transfer, PickingList $daftar, int $stockId, int $qty, ?int $userId): void
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
        $stok->qty_allocated = $stok->qty_allocated + $qty;
        $stok->save();

        $detail = $transfer->details()->create([
            'product_id' => $stok->product_id,
            'source_stock_id' => $stok->id,
            'batch_no' => $stok->batch_no,
            'production_date' => $stok->production_date->toDateString(),
            'expiry_date' => $stok->expiry_date->toDateString(),
            'status' => $stok->status,
            'ddp_reason' => $stok->ddp_reason,
            'qty_requested' => $qty,
            // NULL sampai operator selesai. Bukan nol: nol berarti "sudah
            // dicari di rak dan tidak ada satu pun".
            'qty_shipped' => null,
        ]);

        StockMovement::create([
            'product_id' => $stok->product_id,
            'location_id' => $stok->location_id,
            'warehouse_id' => $stok->warehouse_id,
            'movement_type' => StockMovement::TYPE_ALLOCATED,
            // Cadangan MENGURANGI yang tersedia; qty_change negatif supaya
            // penjumlahan ledger tetap setara dengan qty_available.
            'qty_change' => -$qty,
            'qty_before' => $sebelum,
            'qty_after' => $stok->qty_available,
            'reference_type' => StockMovement::REF_STOCK_TRANSFER,
            'reference_id' => $transfer->id,
            'batch_no' => $stok->batch_no,
            'notes' => sprintf(
                'Dicadangkan untuk %s ke gudang %s, menunggu picking (baris #%d).',
                $transfer->transfer_number,
                $transfer->toWarehouse?->name ?? 'tujuan',
                $detail->id
            ),
            'user_id' => $userId,
        ]);

        // Barisnya di kertas yang dibawa operator. Rak, batch, dan tanggal
        // produksinya DISALIN — kalau baris stoknya berubah setelah daftar
        // dicetak, yang tercetak harus tetap terbaca apa adanya.
        $daftar->items()->create([
            'stock_transfer_detail_id' => $detail->id,
            'product_id' => $stok->product_id,
            'inventory_stock_id' => $stok->id,
            'location_id' => $stok->location_id,
            'batch_no' => $stok->batch_no,
            'production_date' => $stok->production_date->toDateString(),
            'qty_to_pick' => $qty,
            'status' => PickingListItem::STATUS_PENDING,
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

            if (! $terkunci->isPending() && ! $terkunci->isInTransit()) {
                throw new RuntimeException(sprintf(
                    'Transfer %s sudah %s dan tidak bisa dibatalkan lagi.',
                    $terkunci->transfer_number,
                    strtolower($terkunci->status_label)
                ));
            }

            /*
             * DUA KEADAAN, DUA PERLAKUAN YANG BERBEDA SEKALI.
             *
             * Yang masih MENUNGGU PICKING: barangnya tidak pernah turun dari
             * rak. Yang perlu dilepas cuma cadangannya — menuliskan
             * TRANSFER_IN di sini akan MENAMBAH barang yang tidak pernah
             * berkurang, dan stok bertambah dari ketiadaan.
             *
             * Yang sudah DALAM PERJALANAN: barangnya benar-benar keluar, jadi
             * ia memang harus dikembalikan.
             */
            if ($terkunci->isPending()) {
                $this->batalkanSebelumBerangkat($terkunci, $alasan, $userId);
            } else {
                foreach ($terkunci->details as $detail) {
                    $this->kembalikanKeAsal($terkunci, $detail, $alasan, $userId);
                }
            }

            $terkunci->fill([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $alasan,
            ])->save();
        });
    }

    /**
     * Membatalkan transfer yang barangnya masih di rak: cadangannya dilepas.
     *
     * Daftar picking-nya ikut dibubarkan. Dibiarkan hidup, ia menggantung di
     * antrean operator sebagai tugas yang tidak menuju ke mana-mana — dan
     * operator yang mengerjakannya akan menurunkan barang dari rak untuk
     * transfer yang sudah tidak ada.
     *
     * @throws RuntimeException bila operator sudah telanjur menurunkan barang
     */
    private function batalkanSebelumBerangkat(StockTransfer $transfer, string $alasan, ?int $userId): void
    {
        $daftar = $transfer->picking_list_id === null
            ? null
            : PickingList::query()->lockForUpdate()->find($transfer->picking_list_id);

        if ($daftar !== null && $daftar->status === PickingList::STATUS_COMPLETED) {
            throw new RuntimeException(sprintf(
                'Daftar picking %s untuk transfer ini sudah diselesaikan operator, jadi barangnya sudah turun dari rak. '.
                'Transfer yang barangnya sudah di dermaga dibatalkan lewat jalur Dalam Perjalanan, bukan dari sini.',
                $daftar->list_number,
            ));
        }

        foreach ($transfer->details as $detail) {
            $stok = $detail->source_stock_id === null
                ? null
                : InventoryStock::query()->lockForUpdate()->find($detail->source_stock_id);

            if ($stok === null) {
                throw new RuntimeException(sprintf(
                    'Baris stok asal batch %s sudah tidak ada, sehingga cadangannya tidak bisa dilepas ke rak yang benar. '.
                    'Perbaiki dulu lewat Penyesuaian Stok di gudang asal.',
                    $detail->batch_no,
                ));
            }

            $qty = (int) $detail->qty_requested;

            $sebelum = $stok->qty_available;
            $stok->qty_available = $sebelum + $qty;
            $stok->qty_allocated = max(0, $stok->qty_allocated - $qty);
            $stok->save();

            StockMovement::create([
                'product_id' => $detail->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $transfer->from_warehouse_id,
                'movement_type' => StockMovement::TYPE_DEALLOCATED,
                'qty_change' => $qty,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_STOCK_TRANSFER,
                'reference_id' => $transfer->id,
                'batch_no' => $detail->batch_no,
                'notes' => sprintf(
                    'Pembatalan %s sebelum berangkat, cadangan dilepas kembali ke rak: %s',
                    $transfer->transfer_number,
                    $alasan,
                ),
                'user_id' => $userId,
            ]);
        }

        if ($daftar !== null && $daftar->status !== PickingList::STATUS_CANCELLED) {
            $daftar->fill([
                'status' => PickingList::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => sprintf('Transfer %s dibatalkan: %s', $transfer->transfer_number, $alasan),
            ])->save();
        }
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
