<?php

namespace App\Support\Outbound;

use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockBooking;
use Illuminate\Support\Collection;

/**
 * Melengkapi alokasi pesanan yang tertahan menunggu stok.
 *
 * LATAR BELAKANG
 * --------------
 * Saat menerima pesanan, Logistik boleh menyetujui melebihi stok yang
 * tercatat sistem — barang sering sudah sampai gudang tetapi belum
 * di-putaway. Kelebihannya dicatat sebagai kekurangan: dijanjikan ke
 * customer, tetapi belum punya batch maupun lokasi rak, jadi belum bisa
 * dipicking (lihat FifoAllocator).
 *
 * Kelas ini adalah pasangannya: begitu stok SKU itu benar-benar ada, porsi
 * yang tertahan dicadangkan tanpa perlu ada yang mengingatnya.
 *
 * URUT PESANAN TERLAMA LEBIH DULU. Bukan sekadar adil: pesanan yang paling
 * lama menunggu adalah yang paling dekat melanggar SLA (§7.6). Diurutkan
 * dari submitted_at, bukan dari kapan pesanan diterima Logistik, karena
 * itulah titik awal SLA.
 *
 * WAJIB dipanggil di dalam DB::transaction() bersama perubahan stok yang
 * memicunya. Stok yang bertambah tanpa alokasi menyusul (atau sebaliknya)
 * adalah keadaan yang tidak bisa dibetulkan sendiri oleh sistem.
 */
class PendingAllocationFiller
{
    public function __construct(
        private readonly FifoAllocator $allocator,
        private readonly ProductBooking $booking,
    ) {}

    /**
     * SATU ANTREAN UNTUK DUA BENTUK JANJI.
     *
     * Ada dua cara sebuah unit sudah punya pemilik sebelum barangnya ada:
     * pesanan yang disetujui melebihi stok, dan BOOKING yang dibuat sebelum
     * pesanannya masuk. Keduanya sama-sama "sudah dijanjikan, belum ada
     * barangnya", jadi keduanya mengantre di deret yang sama dan diurutkan
     * menurut KAPAN JANJINYA DIBUAT.
     *
     * Memberi salah satunya prioritas mutlak akan salah dengan sendirinya:
     * booking yang dibuat kemarin akan menyalip pesanan yang sudah menunggu
     * tiga minggu, atau sebaliknya. Yang adil dan bisa dijelaskan ke customer
     * hanya satu — siapa yang dijanjikan lebih dulu, dia yang dilayani dulu.
     *
     * @return array{terisi:int, pesanan:list<array{nomor:string, qty:int}>, booking:list<array{nomor:string, qty:int}>}
     */
    public function fill(int $productId, int $warehouseId, ?int $userId): array
    {
        $terisi = 0;
        $pesanan = [];
        $booking = [];

        foreach ($this->antreanJanji($productId, $warehouseId) as $janji) {
            if ($janji['jenis'] === 'pesanan') {
                $detail = $janji['objek'];
                $kurang = $detail->qty_pending_stock;

                if ($kurang < 1) {
                    continue;
                }

                $dapat = $this->allocator->allocate($detail, $kurang, $userId);

                if ($dapat < 1) {
                    // Stok habis sebelum giliran janji ini. Yang berikutnya
                    // pasti juga tidak kebagian, jadi tidak perlu diteruskan.
                    break;
                }

                $terisi += $dapat;
                $pesanan[] = ['nomor' => $detail->salesOrder->order_number, 'qty' => $dapat];

                continue;
            }

            $dapat = $this->booking->reserve($janji['objek'], $userId);

            if ($dapat < 1) {
                break;
            }

            $terisi += $dapat;
            $booking[] = ['nomor' => $janji['objek']->reference, 'qty' => $dapat];
        }

        return ['terisi' => $terisi, 'pesanan' => $pesanan, 'booking' => $booking];
    }

    /**
     * Seluruh janji yang menunggu stok, terurut dari yang paling lama.
     *
     * Waktu janji pesanan diambil dari `submitted_at` — titik awal SLA §7.6 —
     * dan janji booking dari kapan booking-nya dibuat. Keduanya adalah saat
     * customer mulai menunggu, dan itulah yang sebanding.
     *
     * @return list<array{jenis:string, waktu:mixed, objek:mixed}>
     */
    private function antreanJanji(int $productId, int $warehouseId): array
    {
        $antrean = [];

        foreach ($this->menunggu($productId, $warehouseId) as $detail) {
            $antrean[] = [
                'jenis' => 'pesanan',
                'waktu' => $detail->salesOrder?->submitted_at ?? $detail->created_at,
                'objek' => $detail,
            ];
        }

        $bookings = StockBooking::query()
            ->berlaku()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($bookings as $booking) {
            if ($booking->qty_waiting < 1) {
                continue;
            }

            $antrean[] = [
                'jenis' => 'booking',
                'waktu' => $booking->created_at,
                'objek' => $booking,
            ];
        }

        usort($antrean, fn (array $a, array $b) => ($a['waktu']?->timestamp ?? 0) <=> ($b['waktu']?->timestamp ?? 0));

        return $antrean;
    }

    /**
     * Baris pesanan yang masih menunggu stok untuk satu produk di satu gudang.
     *
     * Statusnya dibatasi pada pesanan yang SUDAH diterima tetapi BELUM
     * dipicking. Pesanan yang sudah lewat picking tidak boleh ditambahi
     * alokasi diam-diam: barangnya sudah diambil dari rak dan daftar
     * pickingnya sudah diterbitkan, jadi alokasi susulan tidak akan pernah
     * ikut terkirim.
     *
     * @return Collection<int, SalesOrderDetail>
     */
    private function menunggu(int $productId, int $warehouseId)
    {
        return SalesOrderDetail::query()
            ->where('sales_order_details.product_id', $productId)
            ->whereHas('salesOrder', fn ($q) => $q
                ->where('warehouse_id', $warehouseId)
                ->whereIn('status', [SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PICKING])
                // Pesanan yang sudah masuk daftar picking TIDAK ditambahi
                // alokasi lagi. Isi daftar dibekukan saat disusun; alokasi
                // susulan tidak akan pernah muncul di daftar yang dibawa operator, jadi barangnya tidak akan ikut
                // terambil — tetapi angkanya sudah terlanjur dicadangkan.
                ->whereNull('picking_list_id'))
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_details.sales_order_id')
            // Yang teralokasi belum menutup yang disetujui = masih menunggu.
            // Dihitung di SQL supaya baris yang sudah penuh tidak ikut ditarik
            // — pada gudang penuh, itu ribuan baris untuk satu SKU laris.
            ->whereRaw('sales_order_details.qty_approved > COALESCE((
                SELECT SUM(qty_allocated) FROM sales_order_allocations
                WHERE sales_order_detail_id = sales_order_details.id
            ), 0)')
            ->orderBy('sales_orders.submitted_at')
            ->orderBy('sales_order_details.id')
            ->select('sales_order_details.*')
            ->with('salesOrder:id,order_number,warehouse_id,submitted_at')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Ringkasan hasil pengisian untuk ditampilkan ke pengguna.
     *
     * Alokasi otomatis TANPA laporan berbahaya: Manager mengira menambah 50,
     * yang bebas ternyata 35, dan tidak ada apa pun di layar yang menjelaskan
     * ke mana 15 sisanya. Kalimat ini yang mencegah itu.
     */
    public function ringkasan(array $hasil): ?string
    {
        if ($hasil['terisi'] < 1) {
            return null;
        }

        $bagian = [];

        if ($hasil['pesanan'] !== []) {
            $bagian[] = sprintf(
                '%d pesanan yang menunggu (%s)',
                count($hasil['pesanan']),
                collect($hasil['pesanan'])->map(fn (array $p) => "{$p['nomor']} {$p['qty']}")->implode(', '),
            );
        }

        // Booking disebut TERPISAH, bukan dilebur jadi "pesanan". Yang membaca
        // perlu tahu bahwa sebagian barang baru ini sudah ada yang punya
        // walaupun pesanannya belum masuk — itu justru kabar yang paling
        // mudah terlewat.
        if (! empty($hasil['booking'])) {
            $bagian[] = sprintf(
                '%d booking (%s)',
                count($hasil['booking']),
                collect($hasil['booking'])->map(fn (array $p) => "{$p['nomor']} {$p['qty']}")->implode(', '),
            );
        }

        return sprintf(
            '%d unit langsung dialokasikan ke %s.',
            $hasil['terisi'],
            implode(' dan ', $bagian),
        );
    }
}
