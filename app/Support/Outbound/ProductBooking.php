<?php

namespace App\Support\Outbound;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\SalesOrderAllocation;
use App\Models\SalesOrderDetail;
use App\Models\StockBooking;
use App\Models\StockBookingAllocation;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Booking produk: menahan jatah untuk satu customer sebelum pesanannya masuk.
 *
 * MENAHANNYA MEMAKAI JALAN YANG SUDAH ADA. Booking memindahkan qty dari
 * `qty_available` ke `qty_allocated`, sama persis seperti alokasi pesanan.
 * FifoAllocator hanya melihat `qty_available`, begitu pula availableFor()
 * yang memberi angka "stok yang bisa dijanjikan" di layar penerimaan. Jadi
 * begitu 5 dari 10 unit dibooking, yang bisa dipesan tinggal 5 dengan
 * sendirinya — tanpa satu pun query alokasi perlu diubah, dan tanpa ada
 * tempat kedua yang bisa lupa menyaring.
 *
 * SATU JANJI, SATU PEMILIK — bagian yang paling mudah dirusak
 * -----------------------------------------------------------
 * Begitu pesanan sungguhan dari customer itu diterima, jatahnya HARUS
 * berpindah dari booking ke pesanan (consume). Kalau tidak, keduanya
 * sama-sama memegang 5 unit yang sama dan gudang terlihat menjanjikan 10 dari
 * barang yang cuma ada 5. Perpindahan itu mencakup dua hal yang berbeda:
 *
 *   1. Jatah yang SUDAH tercadang — alokasinya berpindah pemilik, tanpa satu
 *      unit pun bergerak di rak. Stok tetap teralokasi; yang berubah cuma
 *      atas nama siapa.
 *   2. Jatah yang MASIH MENUNGGU stok — tidak ada yang bisa dipindahkan,
 *      tetapi janjinya harus ditutup, karena mulai sekarang pesanan itulah
 *      yang memikulnya. Melewatkan langkah ini membuat satu unit yang sama
 *      antre dua kali saat barang baru masuk.
 *
 * BOOKING TIDAK PERNAH DILEPAS OTOMATIS, sekalipun tanggal butuhnya lewat.
 * Melepas jatah customer diam-diam adalah masalah yang lebih besar daripada
 * booking yang menua; yang lewat tenggat disorot di layar, dan pelepasannya
 * selalu keputusan orang.
 */
class ProductBooking
{
    /**
     * Membuat booking dan langsung menahan sebanyak yang stoknya ada.
     *
     * @throws RuntimeException
     */
    public function create(
        Warehouse $gudang,
        Customer $customer,
        Product $produk,
        int $qty,
        ?string $dibutuhkan,
        ?string $catatan,
        ?int $userId,
    ): StockBooking {
        if ($qty < 1) {
            throw new RuntimeException('Qty booking harus lebih dari nol.');
        }

        return DB::transaction(function () use ($gudang, $customer, $produk, $qty, $dibutuhkan, $catatan, $userId) {
            $booking = StockBooking::create([
                'reference' => $this->nomorBaru(),
                'warehouse_id' => $gudang->id,
                'customer_id' => $customer->id,
                'product_id' => $produk->id,
                'qty_booked' => $qty,
                'qty_used' => 0,
                'needed_by' => $dibutuhkan,
                'note' => $catatan,
                'status' => StockBooking::STATUS_OPEN,
                'created_by' => $userId,
            ]);

            // Stok yang SUDAH ada langsung ditahan. Menundanya sampai barang
            // berikutnya masuk berarti booking yang dibuat saat gudang penuh
            // tidak melindungi apa pun.
            $this->reserve($booking, $userId);

            return $booking->refresh();
        });
    }

    /**
     * Menarik stok bebas ke dalam booking, batch tertua lebih dulu.
     *
     * Urutannya FIFO sama seperti alokasi pesanan: jatah yang ditahan pun
     * harus batch tertua, kalau tidak booking justru menyisakan barang tua
     * untuk orang lain dan memegang yang muda.
     *
     * WAJIB dipanggil di dalam DB::transaction().
     *
     * @return int qty yang berhasil ditahan
     */
    public function reserve(StockBooking $booking, ?int $userId): int
    {
        if (! $booking->masihBerlaku()) {
            return 0;
        }

        $sisa = $booking->qty_waiting;

        if ($sisa < 1) {
            return 0;
        }

        $batch = InventoryStock::query()
            ->where('product_id', $booking->product_id)
            ->where('warehouse_id', $booking->warehouse_id)
            ->where('status', InventoryStock::STATUS_ACTIVE)
            ->where('qty_available', '>', 0)
            // Urutan yang sama persis dengan alokasi pesanan — termasuk
            // penanda "Dahulukan Keluar". Lihat scopeUrutanKeluar().
            ->urutanKeluar()
            ->lockForUpdate()
            ->get();

        $ditahan = 0;

        foreach ($batch as $stok) {
            if ($sisa < 1) {
                break;
            }

            $ambil = min($sisa, (int) $stok->qty_available);
            $sebelum = (int) $stok->qty_available;

            $stok->qty_available = $sebelum - $ambil;
            $stok->qty_allocated = $stok->qty_allocated + $ambil;
            $stok->save();

            $alokasi = StockBookingAllocation::firstOrNew([
                'stock_booking_id' => $booking->id,
                'inventory_stock_id' => $stok->id,
            ]);
            $alokasi->qty = ($alokasi->qty ?? 0) + $ambil;
            $alokasi->save();

            StockMovement::create([
                'product_id' => $booking->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $stok->warehouse_id,
                'movement_type' => StockMovement::TYPE_ALLOCATED,
                // Menahan MENGURANGI yang tersedia; qty_change negatif supaya
                // penjumlahan ledger tetap setara dengan qty_available.
                'qty_change' => -$ambil,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_BOOKING,
                'reference_id' => $booking->id,
                'batch_no' => $stok->batch_no,
                'notes' => sprintf(
                    'Booking %s untuk %s (batch %s).',
                    $booking->reference,
                    $booking->customer?->name ?? '—',
                    $stok->batch_no ?? '—',
                ),
                'user_id' => $userId,
            ]);

            $ditahan += $ambil;
            $sisa -= $ambil;
        }

        return $ditahan;
    }

    /**
     * Memindahkan jatah booking ke pesanan sungguhan milik customer yang sama.
     *
     * Dipanggil saat Logistik menerima pesanan, SEBELUM alokasi FIFO biasa
     * jalan. Yang dikembalikan adalah qty yang sudah beres tanpa menyentuh
     * stok bebas sama sekali.
     *
     * WAJIB dipanggil di dalam DB::transaction().
     *
     * @return array{dipakai:int, dari_cadangan:int, booking:list<string>}
     */
    public function consume(SalesOrderDetail $detail, int $qty, ?int $userId): array
    {
        $hasil = ['dipakai' => 0, 'dari_cadangan' => 0, 'booking' => []];

        if ($qty < 1) {
            return $hasil;
        }

        $order = $detail->salesOrder;
        $sisa = $qty;

        $daftar = StockBooking::query()
            ->berlaku()
            ->where('customer_id', $order->customer_id)
            ->where('product_id', $detail->product_id)
            ->where('warehouse_id', $order->warehouse_id)
            // Booking terlama lebih dulu — janji yang lebih tua ditutup lebih
            // dulu, sama seperti antrean pesanan yang menunggu stok.
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($daftar as $booking) {
            if ($sisa < 1) {
                break;
            }

            $terpakai = $this->pindahkanSatuBooking($booking, $detail, $sisa, $userId);

            if ($terpakai['dipakai'] < 1) {
                continue;
            }

            $hasil['dipakai'] += $terpakai['dipakai'];
            $hasil['dari_cadangan'] += $terpakai['dari_cadangan'];
            $hasil['booking'][] = $booking->reference;
            $sisa -= $terpakai['dipakai'];
        }

        return $hasil;
    }

    /**
     * Membatalkan booking; jatah yang sempat ditahan kembali jadi stok bebas.
     *
     * @throws RuntimeException
     */
    public function cancel(StockBooking $booking, string $alasan, ?int $userId): int
    {
        return DB::transaction(function () use ($booking, $alasan, $userId) {
            $terkunci = StockBooking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $terkunci->masihBerlaku()) {
                throw new RuntimeException(sprintf(
                    'Booking %s sudah %s.',
                    $terkunci->reference,
                    strtolower($terkunci->status_label),
                ));
            }

            $dilepas = $this->lepaskanSeluruhCadangan($terkunci, $userId, sprintf(
                'Booking %s dibatalkan, jatahnya kembali jadi stok bebas.',
                $terkunci->reference,
            ));

            $terkunci->fill([
                'status' => StockBooking::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $alasan,
            ])->save();

            return $dilepas;
        });
    }

    /* ------------------------------------------------------------ Internal */

    /**
     * @return array{dipakai:int, dari_cadangan:int}
     */
    private function pindahkanSatuBooking(
        StockBooking $booking,
        SalesOrderDetail $detail,
        int $maks,
        ?int $userId,
    ): array {
        $dariCadangan = 0;
        $sisa = $maks;

        // TAHAP 1 — jatah yang sudah tercadang berpindah pemilik. Tidak ada
        // satu unit pun yang bergerak di rak: stok tetap teralokasi, yang
        // berubah hanya atas nama siapa. Karena itu tidak ada mutasi ledger
        // di sini — qty_available tidak berubah, dan menuliskan mutasi
        // bernilai nol hanya akan mengaburkan ledger.
        foreach ($booking->allocations()->orderBy('id')->lockForUpdate()->get() as $alokasi) {
            if ($sisa < 1) {
                break;
            }

            $ambil = min($sisa, (int) $alokasi->qty);

            $milikPesanan = SalesOrderAllocation::firstOrNew([
                'sales_order_detail_id' => $detail->id,
                'inventory_stock_id' => $alokasi->inventory_stock_id,
            ]);
            $milikPesanan->qty_allocated = ($milikPesanan->qty_allocated ?? 0) + $ambil;
            $milikPesanan->created_at = $milikPesanan->created_at ?? now();
            $milikPesanan->save();

            $alokasi->qty -= $ambil;
            $alokasi->qty < 1 ? $alokasi->delete() : $alokasi->save();

            $dariCadangan += $ambil;
            $sisa -= $ambil;
        }

        // TAHAP 2 — porsi yang MASIH MENUNGGU stok. Tidak ada yang bisa
        // dipindahkan, tetapi janjinya ditutup: mulai sekarang pesanan itulah
        // yang memikulnya. Tanpa langkah ini, satu unit yang sama akan antre
        // dua kali saat barang baru masuk — sekali atas nama booking, sekali
        // atas nama pesanan.
        $menunggu = min($sisa, $booking->fresh()->qty_waiting);

        $dipakai = $dariCadangan + max(0, $menunggu);

        if ($dipakai > 0) {
            $booking->qty_used += $dipakai;

            if ($booking->qty_used >= $booking->qty_booked) {
                $booking->status = StockBooking::STATUS_CLOSED;
                $booking->closed_at = now();
            }

            $booking->save();
        }

        return ['dipakai' => $dipakai, 'dari_cadangan' => $dariCadangan];
    }

    /** Mengembalikan seluruh cadangan booking ke stok bebas. */
    private function lepaskanSeluruhCadangan(StockBooking $booking, ?int $userId, string $catatan): int
    {
        $total = 0;

        foreach ($booking->allocations()->orderBy('id')->get() as $alokasi) {
            $stok = InventoryStock::query()->lockForUpdate()->find($alokasi->inventory_stock_id);

            if ($stok === null) {
                // Baris stoknya sudah tidak ada. Alokasinya tetap dibuang
                // supaya tidak menggantung, tetapi tidak ada tempat untuk
                // mengembalikan qty-nya — dan itu memang benar.
                $alokasi->delete();

                continue;
            }

            $qty = (int) $alokasi->qty;
            $sebelum = (int) $stok->qty_available;

            $stok->qty_available = $sebelum + $qty;
            $stok->qty_allocated = max(0, $stok->qty_allocated - $qty);
            $stok->save();

            StockMovement::create([
                'product_id' => $booking->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $stok->warehouse_id,
                'movement_type' => StockMovement::TYPE_DEALLOCATED,
                'qty_change' => $qty,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_BOOKING,
                'reference_id' => $booking->id,
                'batch_no' => $stok->batch_no,
                'notes' => $catatan,
                'user_id' => $userId,
            ]);

            $total += $qty;
            $alokasi->delete();
        }

        return $total;
    }

    /** Nomor booking: BK{YYMMDD}{urut 3 digit}. */
    private function nomorBaru(): string
    {
        $hariIni = now()->format('ymd');

        $urut = StockBooking::query()
            ->where('reference', 'like', 'BK'.$hariIni.'%')
            ->count() + 1;

        return 'BK'.$hariIni.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }
}
