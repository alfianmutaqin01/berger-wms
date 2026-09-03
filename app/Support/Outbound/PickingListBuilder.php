<?php

namespace App\Support\Outbound;

use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\SalesOrder;
use App\Support\DocumentNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyusun dan membubarkan daftar picking — pekerjaan LOGISTIK.
 *
 * Pasangannya adalah PickingRun, yang memegang pekerjaan OPERATOR. Keduanya
 * sengaja dipisah karena dijalankan orang berbeda dengan wewenang berbeda:
 * yang menentukan apa yang berangkat bersama bukan yang berjalan ke rak.
 *
 * KENAPA BARISNYA DIBEKUKAN
 * -------------------------
 * Isi daftar dihitung SEKALI, saat daftar dibuat, lalu disimpan sebagai baris
 * picking_list_items. Ia TIDAK dihitung ulang dari alokasi tiap kali layar
 * dibuka. Alasannya: daftar ini dicetak dan dibawa berjalan. Kalau isinya
 * bisa berubah di belakang layar — misalnya karena stok susulan masuk dan
 * alokasi bertambah — kertas di tangan operator dan layar di kantor
 * menunjukkan dua hal berbeda, dan yang dipercaya operator adalah kertasnya.
 *
 * PORSI YANG MENUNGGU STOK TIDAK IKUT. Baris pesanan yang disetujui melebihi
 * stok tercatat (lihat FifoAllocator) belum punya batch maupun rak; tidak ada
 * yang bisa ditulis di daftar, dan mengarang raknya berarti menyuruh operator
 * mencari barang di tempat yang tidak menyimpannya.
 */
class PickingListBuilder
{
    /**
     * Menyusun satu daftar dari beberapa pesanan yang berangkat bersama.
     *
     * @param  list<int>  $orderIds
     *
     * @throws RuntimeException
     */
    public function build(int $warehouseId, array $orderIds, ?string $catatan, ?int $userId): PickingList
    {
        if ($orderIds === []) {
            throw new RuntimeException('Pilih minimal satu pesanan untuk dibuatkan daftar picking.');
        }

        return DB::transaction(function () use ($warehouseId, $orderIds, $catatan, $userId) {
            // Dikunci dan diperiksa ULANG di dalam transaksi. Dua Logistik
            // yang membuka layar antrean yang sama sama-sama melihat pesanan
            // yang sama tersedia, dan tanpa kunci keduanya berhasil
            // memasukkannya ke dua daftar berbeda — barangnya lalu diambil
            // dua kali oleh dua operator.
            $pesanan = SalesOrder::query()
                ->whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get();

            $this->pastikanSemuaLayakMasuk($pesanan, $orderIds, $warehouseId);

            $daftar = PickingList::create([
                'list_number' => DocumentNumber::forPickingList(),
                'warehouse_id' => $warehouseId,
                'status' => PickingList::STATUS_OPEN,
                'created_by' => $userId,
                'notes' => $catatan,
            ]);

            $jumlahBaris = 0;

            foreach ($pesanan as $order) {
                $jumlahBaris += $this->salinAlokasiJadiBaris($daftar, $order);

                $order->forceFill(['picking_list_id' => $daftar->id])->save();
            }

            if ($jumlahBaris < 1) {
                // Bukan daftar kosong yang dibiarkan hidup: daftar tanpa baris
                // adalah tugas yang tidak bisa diselesaikan siapa pun, dan ia
                // akan menggantung di antrean operator selamanya.
                throw new RuntimeException(
                    'Tidak ada satu pun barang yang bisa diambil dari pesanan yang dipilih. '.
                    'Seluruhnya masih menunggu stok — belum punya batch maupun lokasi rak.'
                );
            }

            return $daftar->refresh();
        });
    }

    /**
     * Membubarkan daftar dan mengembalikan pesanannya ke antrean.
     *
     * @throws RuntimeException
     */
    public function cancel(PickingList $list, string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($list, $reason, $userId) {
            $terkunci = PickingList::query()->lockForUpdate()->findOrFail($list->id);

            if (! $terkunci->bolehDibubarkan()) {
                throw new RuntimeException($this->alasanTidakBolehDibubarkan($terkunci));
            }

            // Barisnya dihapus, bukan disimpan sebagai riwayat: tidak ada
            // kejadian fisik apa pun yang perlu ditelusuri — belum ada satu
            // barang pun yang turun dari rak. Yang tersisa hanyalah rencana
            // yang batal, dan rencana batal bukan jejak audit.
            $terkunci->items()->delete();

            $this->bebaskanPesanan($terkunci);

            $terkunci->fill([
                'status' => PickingList::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ])->save();
        });
    }

    /**
     * Mengeluarkan SATU pesanan dari daftar yang belum selesai.
     *
     * Dipakai OrderCanceller: pesanan yang dibatalkan melepas seluruh
     * alokasinya, sehingga baris pickingnya menunjuk cadangan yang sudah
     * tidak ada. Membiarkannya berarti operator disuruh mengambil barang
     * untuk pesanan yang sudah tidak berlaku.
     *
     * Daftar yang jadi kosong ikut dibubarkan — lihat build(): daftar tanpa
     * baris adalah tugas yang tidak bisa diselesaikan siapa pun.
     */
    public function keluarkanPesanan(SalesOrder $order, ?int $userId): void
    {
        if ($order->picking_list_id === null) {
            return;
        }

        $daftar = PickingList::query()->lockForUpdate()->find($order->picking_list_id);

        $order->forceFill(['picking_list_id' => null])->save();

        if ($daftar === null || $daftar->status === PickingList::STATUS_COMPLETED) {
            // Daftar yang SUDAH selesai tidak disentuh. Barisnya bukan lagi
            // rencana melainkan catatan barang yang benar-benar turun dari
            // rak, dan pengembaliannya diurus OrderCanceller lewat PickingRun.
            return;
        }

        $daftar->items()->where('sales_order_id', $order->id)->delete();

        if ($daftar->items()->exists()) {
            return;
        }

        $daftar->fill([
            'status' => PickingList::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $userId,
            'cancellation_reason' => sprintf(
                'Bubar sendiri: pesanan terakhir di dalamnya (%s) dibatalkan.',
                $order->order_number
            ),
        ])->save();
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Menyalin alokasi satu pesanan menjadi baris-baris pengambilan.
     *
     * @return int jumlah baris yang tersalin
     */
    private function salinAlokasiJadiBaris(PickingList $daftar, SalesOrder $order): int
    {
        $order->load('details.allocations.stock');

        $jumlah = 0;

        foreach ($order->details as $detail) {
            foreach ($detail->allocations as $alokasi) {
                $stok = $alokasi->stock;

                if ($stok === null || $alokasi->qty_allocated < 1) {
                    // Baris stoknya sudah tidak ada. Tidak bisa dijadikan
                    // baris picking — tidak ada rak yang bisa ditulis, dan
                    // menebaknya berarti mengirim operator ke tempat kosong.
                    continue;
                }

                PickingListItem::create([
                    'picking_list_id' => $daftar->id,
                    'sales_order_id' => $order->id,
                    'sales_order_detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'inventory_stock_id' => $stok->id,
                    'location_id' => $stok->location_id,
                    'batch_no' => $stok->batch_no,
                    'production_date' => $stok->production_date,
                    'qty_to_pick' => (int) $alokasi->qty_allocated,
                    'status' => PickingListItem::STATUS_PENDING,
                ]);

                $jumlah++;
            }
        }

        return $jumlah;
    }

    /**
     * @param  Collection<int, SalesOrder>  $pesanan
     * @param  list<int>  $diminta
     *
     * @throws RuntimeException
     */
    private function pastikanSemuaLayakMasuk($pesanan, array $diminta, int $warehouseId): void
    {
        if ($pesanan->count() !== count(array_unique($diminta))) {
            throw new RuntimeException('Ada pesanan yang tidak ditemukan. Muat ulang halaman lalu coba lagi.');
        }

        foreach ($pesanan as $order) {
            if ($order->warehouse_id !== $warehouseId) {
                // Satu daftar = satu kali jalan kaki di satu bangunan.
                // Pesanan gudang lain tidak akan pernah bisa diambil oleh
                // operator yang memegang daftar ini.
                throw new RuntimeException(sprintf(
                    'Pesanan %s bukan milik gudang ini. Satu daftar picking hanya boleh berisi pesanan dari satu gudang.',
                    $order->order_number
                ));
            }

            if ($order->status !== SalesOrder::STATUS_APPROVED) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s berstatus %s, jadi belum (atau sudah tidak) siap dipicking.',
                    $order->order_number,
                    strtolower($order->status_label)
                ));
            }

            if ($order->picking_list_id !== null) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s sudah masuk daftar picking lain. Satu pesanan hanya boleh ada di satu daftar — '.
                    'kalau tidak, barangnya diambil dua kali.',
                    $order->order_number
                ));
            }
        }
    }

    private function bebaskanPesanan(PickingList $daftar): void
    {
        foreach ($daftar->orders()->lockForUpdate()->get() as $order) {
            $order->forceFill([
                'picking_list_id' => null,
                // Kembali ke "siap dipicking". Statusnya baru berubah jadi
                // picking ketika operator memegang daftar, jadi mengembalikan
                // ke approved memang mengembalikannya ke keadaan semula.
                'status' => SalesOrder::STATUS_APPROVED,
            ])->save();
        }
    }

    private function alasanTidakBolehDibubarkan(PickingList $daftar): string
    {
        if ($daftar->status === PickingList::STATUS_COMPLETED) {
            return sprintf(
                'Daftar %s sudah selesai dan barangnya ada di loading dock. Membubarkannya hanya menghapus catatan — '.
                'barangnya tetap sudah turun dari rak, dan tidak ada lagi yang menjelaskan kenapa ia di sana.',
                $daftar->list_number
            );
        }

        if ($daftar->status === PickingList::STATUS_CANCELLED) {
            return sprintf('Daftar %s memang sudah dibubarkan.', $daftar->list_number);
        }

        return sprintf(
            'Daftar %s sudah dikerjakan sebagian oleh operator. Bubarkan hanya selama belum ada satu baris pun yang '.
            'ditandai; sesudah itu, barangnya sudah turun dari rak dan harus dikembalikan dulu secara fisik.',
            $daftar->list_number
        );
    }
}
