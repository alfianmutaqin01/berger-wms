<?php

namespace App\Support\Outbound;

use App\Models\InventoryStock;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderAllocation;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengerjaan daftar picking di lapangan — pekerjaan OPERATOR.
 *
 * Pasangannya PickingListBuilder, yang memegang pekerjaan Logistik.
 *
 * TIGA MUTASI, BUKAN SATU — bagian ini yang paling mudah "disederhanakan"
 * lalu diam-diam merusak buku besar. Aturannya: SELURUH ledger harus
 * berjumlah sama dengan qty_available (lihat FifoAllocator). Saat alokasi
 * dibuat, barangnya SUDAH dikurangi dari qty_available dan dipindahkan ke
 * qty_allocated. Jadi ketika barangnya benar-benar turun dari rak,
 * qty_available TIDAK berubah lagi — menuliskan satu baris OUT bernilai
 * negatif akan mengurangi barang yang sama untuk KEDUA KALINYA di ledger.
 *
 * Yang benar, dan inilah yang dilakukan complete():
 *
 *   DEALLOCATED  +qty_to_pick  cadangannya berakhir, angkanya kembali dulu
 *   OUT          -qty_diambil  yang benar-benar keluar menuju customer
 *   ADJUSTMENT   -qty_kurang   yang ternyata TIDAK ADA di rak (bila ada)
 *
 * Jumlah ketiganya nol terhadap qty_available — dan itu memang benar, karena
 * yang berkurang adalah qty_allocated. Masing-masing baris tetap bercerita
 * apa adanya: cadangan berakhir, barang keluar, dan sisanya memang tidak
 * pernah ada.
 *
 * JANGAN GABUNGKAN KETIGANYA. Selisih picking bukan "barang keluar": ia
 * tidak pernah sampai ke customer, dan menghitungnya sebagai OUT membuat
 * laporan pengiriman lebih besar daripada yang benar-benar dikirim.
 */
class PickingRun
{
    /**
     * Operator mengambil tugas. Daftar terkunci atas namanya.
     *
     * @throws RuntimeException
     */
    public function claim(PickingList $list, User $operator): PickingList
    {
        return DB::transaction(function () use ($list, $operator) {
            $terkunci = PickingList::query()->lockForUpdate()->findOrFail($list->id);

            // Diperiksa ULANG di dalam kunci: dua operator yang membuka
            // antrean pada saat yang sama sama-sama melihat tombol "Ambil
            // Tugas" aktif pada daftar yang sama.
            if ($terkunci->status !== PickingList::STATUS_OPEN) {
                throw new RuntimeException($this->alasanTidakBisaDiambil($terkunci));
            }

            $terkunci->fill([
                'status' => PickingList::STATUS_PICKING,
                'claimed_by' => $operator->id,
                'claimed_at' => now(),
            ])->save();

            // Pesanannya ikut berpindah status supaya layar Logistik dan
            // Sales tahu barangnya sedang diambil, bukan masih mengantre.
            $terkunci->orders()->lockForUpdate()->get()->each(
                fn (SalesOrder $order) => $order->forceFill(['status' => SalesOrder::STATUS_PICKING])->save()
            );

            return $terkunci;
        });
    }

    /**
     * Menandai satu baris terambil PENUH — jalur cepat, satu ketuk.
     *
     * @throws RuntimeException
     */
    public function pick(PickingListItem $item, User $operator): void
    {
        $this->tandai($item, $operator, (int) $item->qty_to_pick, null);
    }

    /**
     * Menandai satu baris KURANG dari daftar, dengan alasan wajib.
     *
     * Pintu terpisah, bukan isian di setiap baris (keputusan pemilik produk).
     * Kalau tiap baris meminta "berapa yang benar-benar diambil", operator
     * mengetik angka yang sama dengan yang tertulis ratusan kali sehari — dan
     * ketikan yang selalu sama persis berhenti dibaca, justru pada hari
     * angkanya berbeda.
     *
     * @throws RuntimeException
     */
    public function reportShort(PickingListItem $item, User $operator, int $qty, string $reason): void
    {
        if ($qty >= $item->qty_to_pick) {
            throw new RuntimeException(
                'Qty selisih harus lebih kecil daripada yang tertulis di daftar. '.
                'Kalau barangnya lengkap, pakai tombol Ambil.'
            );
        }

        $this->tandai($item, $operator, $qty, $reason);
    }

    /**
     * Membatalkan penandaan satu baris — operator salah ketuk.
     *
     * Hanya selama daftarnya BELUM diselesaikan. Sesudah Siap Loading
     * ditekan, stok sudah berkurang dan barangnya sudah di dock; yang bisa
     * membetulkannya adalah koreksi stok, bukan menghapus tanda di sini.
     *
     * @throws RuntimeException
     */
    public function resetItem(PickingListItem $item, User $operator): void
    {
        DB::transaction(function () use ($item, $operator) {
            $daftar = PickingList::query()->lockForUpdate()->findOrFail($item->picking_list_id);

            $this->pastikanSedangDikerjakan($daftar, $operator);

            $item->forceFill([
                'qty_picked' => null,
                'status' => PickingListItem::STATUS_PENDING,
                'discrepancy_reason' => null,
                'picked_at' => null,
                'picked_by' => null,
            ])->save();
        });
    }

    /**
     * "Siap Loading" — seluruh baris sudah ditandai, stok dikurangi.
     *
     * @return array{diambil:int, kurang:int}
     *
     * @throws RuntimeException
     */
    public function complete(PickingList $list, User $operator): array
    {
        return DB::transaction(function () use ($list, $operator) {
            $daftar = PickingList::query()->lockForUpdate()->findOrFail($list->id);

            $this->pastikanSedangDikerjakan($daftar, $operator);

            $belum = $daftar->items()->where('status', PickingListItem::STATUS_PENDING)->count();

            if ($belum > 0) {
                throw new RuntimeException(sprintf(
                    'Masih ada %d baris yang belum ditandai. Tandai seluruh baris lebih dulu — baris yang terlewat '.
                    'berarti barang yang tidak ikut naik ke kendaraan tanpa ada yang tahu.',
                    $belum
                ));
            }

            $diambil = 0;
            $kurang = 0;

            // Diurutkan menurut id baris stok. Dua daftar yang kebetulan
            // menyentuh batch yang sama akan mengunci barisnya dalam urutan
            // yang sama pula, sehingga keduanya tidak saling menunggu.
            $baris = $daftar->items()->orderBy('inventory_stock_id')->orderBy('id')->get();

            foreach ($baris as $item) {
                $hasil = $this->keluarkanDariRak($item, $daftar, $operator->id);
                $diambil += $hasil['diambil'];
                $kurang += $hasil['kurang'];
            }

            $this->selesaikanPesanan($daftar);

            $daftar->fill([
                'status' => PickingList::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by' => $operator->id,
            ])->save();

            return ['diambil' => $diambil, 'kurang' => $kurang];
        });
    }

    /**
     * Mengembalikan barang yang SUDAH dipicking ke raknya semula.
     *
     * Dipakai OrderCanceller ketika pesanan dibatalkan setelah daftarnya
     * selesai — barangnya sudah turun ke loading dock tetapi belum berangkat.
     * Rak, batch, dan tanggal produksinya diketahui pasti karena dibekukan di
     * baris picking, jadi tidak ada yang perlu ditebak.
     *
     * WAJIB dipanggil di dalam DB::transaction milik pemanggilnya.
     *
     * @return int qty yang dikembalikan
     */
    public function kembalikanHasilPicking(SalesOrder $order, ?int $userId): int
    {
        $baris = PickingListItem::query()
            ->where('sales_order_id', $order->id)
            ->where('status', '<>', PickingListItem::STATUS_PENDING)
            ->whereHas('pickingList', fn ($q) => $q->where('status', PickingList::STATUS_COMPLETED))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($baris as $item) {
            $qty = (int) $item->qty_picked;

            if ($qty < 1) {
                continue;
            }

            $stok = $this->stokUntukDikembalikan($item, $order);
            $sebelum = $stok->qty_available;

            $stok->qty_available = $sebelum + $qty;
            $stok->save();

            StockMovement::create([
                'product_id' => $item->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $stok->warehouse_id,
                'movement_type' => StockMovement::TYPE_IN,
                'qty_change' => $qty,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_SALES_ORDER,
                'reference_id' => $order->id,
                'batch_no' => $item->batch_no,
                'notes' => sprintf(
                    'Pembatalan %s: barang yang sudah dipicking dikembalikan ke rak %s (batch %s).',
                    $order->order_number,
                    $stok->location?->code ?? '—',
                    $item->batch_no ?? '—'
                ),
                'user_id' => $userId,
            ]);

            $total += $qty;
        }

        return $total;
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Satu baris keluar dari rak. Seluruh aturan buku besarnya di sini.
     *
     * @return array{diambil:int, kurang:int}
     */
    private function keluarkanDariRak(PickingListItem $item, PickingList $daftar, ?int $userId): array
    {
        $diambil = (int) $item->qty_picked;
        $kurang = $item->qty_kurang;
        $dijanjikan = (int) $item->qty_to_pick;

        $stok = $item->inventory_stock_id === null
            ? null
            : InventoryStock::query()->lockForUpdate()->find($item->inventory_stock_id);

        if ($stok === null) {
            throw new RuntimeException(sprintf(
                'Baris stok untuk %s batch %s di rak %s sudah tidak ada, jadi tidak bisa dikurangi. '.
                'Daftar ini perlu disusun ulang oleh Logistik.',
                $item->product?->sku ?? 'produk ini',
                $item->batch_no ?? '—',
                $item->location?->code ?? '—'
            ));
        }

        // 1. Cadangannya berakhir. Angkanya kembali dulu ke qty_available
        //    supaya jumlah ledger tetap setara dengannya — lihat catatan
        //    kelas ini soal kenapa satu baris OUT saja salah.
        $sebelum = $stok->qty_available;
        $stok->qty_available = $sebelum + $dijanjikan;
        $stok->qty_allocated = max(0, $stok->qty_allocated - $dijanjikan);
        $stok->save();

        StockMovement::create([
            'product_id' => $item->product_id,
            'location_id' => $stok->location_id,
            'warehouse_id' => $stok->warehouse_id,
            'movement_type' => StockMovement::TYPE_DEALLOCATED,
            'qty_change' => $dijanjikan,
            'qty_before' => $sebelum,
            'qty_after' => $stok->qty_available,
            'reference_type' => StockMovement::REF_SALES_ORDER,
            'reference_id' => $item->sales_order_id,
            'batch_no' => $item->batch_no,
            'notes' => sprintf(
                'Picking %s: cadangan berakhir, barang diambil dari rak (batch %s).',
                $daftar->list_number,
                $item->batch_no ?? '—'
            ),
            'user_id' => $userId,
        ]);

        // 2. Yang benar-benar keluar menuju customer.
        if ($diambil > 0) {
            $sebelum = $stok->qty_available;
            $stok->qty_available = $sebelum - $diambil;
            $stok->save();

            StockMovement::create([
                'product_id' => $item->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $stok->warehouse_id,
                'movement_type' => StockMovement::TYPE_OUT,
                'qty_change' => -$diambil,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_SALES_ORDER,
                'reference_id' => $item->sales_order_id,
                'batch_no' => $item->batch_no,
                'notes' => sprintf(
                    'Picking %s: %d keluar dari rak %s menuju loading dock.',
                    $daftar->list_number,
                    $diambil,
                    $item->location?->code ?? '—'
                ),
                'user_id' => $userId,
            ]);
        }

        // 3. Yang ternyata TIDAK ADA di rak. Bukan barang keluar — ia tidak
        //    pernah sampai ke customer. Ini koreksi stok, dan karena itu
        //    alasannya wajib (StockMovement::REQUIRES_NOTES).
        if ($kurang > 0) {
            $sebelum = $stok->qty_available;
            $stok->qty_available = max(0, $sebelum - $kurang);
            $stok->save();

            StockMovement::create([
                'product_id' => $item->product_id,
                'location_id' => $stok->location_id,
                'warehouse_id' => $stok->warehouse_id,
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'qty_change' => -$kurang,
                'qty_before' => $sebelum,
                'qty_after' => $stok->qty_available,
                'reference_type' => StockMovement::REF_SALES_ORDER,
                'reference_id' => $item->sales_order_id,
                'batch_no' => $item->batch_no,
                'notes' => sprintf(
                    'Selisih picking %s di rak %s (batch %s): tercatat %d, ditemukan %d. Alasan: %s',
                    $daftar->list_number,
                    $item->location?->code ?? '—',
                    $item->batch_no ?? '—',
                    $dijanjikan,
                    $diambil,
                    $item->discrepancy_reason
                ),
                'user_id' => $userId,
            ]);
        }

        // Cadangannya sudah dipakai habis, jadi barisnya tidak boleh
        // tertinggal: SalesOrderDetail::qty_allocated menjumlahkannya, dan
        // baris yang tersisa membuat pesanan yang barangnya sudah di dock
        // terbaca seolah masih memegang cadangan di rak.
        SalesOrderAllocation::query()
            ->where('sales_order_detail_id', $item->sales_order_detail_id)
            ->where('inventory_stock_id', $stok->id)
            ->delete();

        return ['diambil' => $diambil, 'kurang' => $kurang];
    }

    private function tandai(PickingListItem $item, User $operator, int $qty, ?string $reason): void
    {
        DB::transaction(function () use ($item, $operator, $qty, $reason) {
            $daftar = PickingList::query()->lockForUpdate()->findOrFail($item->picking_list_id);

            $this->pastikanSedangDikerjakan($daftar, $operator);

            $item->forceFill([
                'qty_picked' => $qty,
                'status' => $reason === null
                    ? PickingListItem::STATUS_PICKED
                    : PickingListItem::STATUS_SHORT,
                'discrepancy_reason' => $reason,
                'picked_at' => now(),
                'picked_by' => $operator->id,
            ])->save();
        });
    }

    /**
     * Pesanan dalam daftar berpindah ke "Siap Kirim" (F-OUT-03 #6).
     */
    private function selesaikanPesanan(PickingList $daftar): void
    {
        foreach ($daftar->orders()->lockForUpdate()->get() as $order) {
            $order->forceFill([
                'status' => SalesOrder::STATUS_READY_TO_SHIP,
                'picking_completed_at' => now(),
            ])->save();
        }
    }

    /**
     * Baris stok tujuan pengembalian.
     *
     * Barisnya bisa saja sudah dihapus setelah kosong. Dibuat ulang di rak,
     * batch, dan tanggal produksi yang SAMA — ketiganya dibekukan di baris
     * picking, jadi tidak ada yang ditebak. Membuatnya di rak lain berarti
     * menaruh barang di tempat yang tidak akan dicari orang.
     */
    private function stokUntukDikembalikan(PickingListItem $item, SalesOrder $order): InventoryStock
    {
        if ($item->inventory_stock_id !== null) {
            $stok = InventoryStock::query()->lockForUpdate()->find($item->inventory_stock_id);

            if ($stok !== null) {
                return $stok;
            }
        }

        return InventoryStock::create([
            'product_id' => $item->product_id,
            'location_id' => $item->location_id,
            'warehouse_id' => $order->warehouse_id,
            'batch_no' => $item->batch_no,
            'qty_available' => 0,
            'qty_allocated' => 0,
            'production_date' => $item->production_date,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    /**
     * @throws RuntimeException
     */
    private function pastikanSedangDikerjakan(PickingList $daftar, User $operator): void
    {
        if ($daftar->status !== PickingList::STATUS_PICKING) {
            throw new RuntimeException(sprintf(
                'Daftar %s berstatus %s, jadi tidak sedang dikerjakan siapa pun.',
                $daftar->list_number,
                strtolower($daftar->status_label)
            ));
        }

        // Super Admin boleh menolong daftar yang tersangkut — misalnya
        // operator yang memegangnya pulang di tengah shift. Selain itu,
        // hanya pemegangnya: dua orang yang menandai baris di daftar yang
        // sama akan saling menghapus pekerjaan tanpa sadar.
        if ($daftar->claimed_by === $operator->id || $operator->role?->slug === Role::SUPER_ADMIN) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Daftar %s sedang dipegang %s. Minta ia menyelesaikannya, atau minta Super Admin memindahkan tugasnya.',
            $daftar->list_number,
            $daftar->claimedBy?->full_name ?? 'operator lain'
        ));
    }

    private function alasanTidakBisaDiambil(PickingList $daftar): string
    {
        return match ($daftar->status) {
            PickingList::STATUS_PICKING => sprintf(
                'Daftar %s sudah diambil %s lebih dulu.',
                $daftar->list_number,
                $daftar->claimedBy?->full_name ?? 'operator lain'
            ),
            PickingList::STATUS_COMPLETED => sprintf('Daftar %s sudah selesai dikerjakan.', $daftar->list_number),
            default => sprintf('Daftar %s sudah dibubarkan Logistik.', $daftar->list_number),
        };
    }
}
