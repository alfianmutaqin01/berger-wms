<?php

namespace App\Support\Outbound;

use App\Models\DeliveryNote;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\SalesOrder;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Menyatakan barang berangkat — PRD §6.5 F-OUT-04, Fase 6 tahap 4.
 *
 * DOKUMEN BC YANG MENANG (keputusan pemilik produk). Qty yang berlaku adalah
 * qty di Surat Jalan, bukan qty hasil picking kami. Contoh nyata dari pemilik
 * produk: dipesan 15, dipicking 10, di SJ hanya 8 — maka yang berangkat 8,
 * sisanya 7 menjadi Outstanding, dan 2 pail yang sudah turun dari rak
 * KEMBALI ke stok.
 *
 * Bagian terakhir itu yang paling mudah terlewat. Barangnya nyata: ia ada di
 * loading dock, tidak ikut naik kendaraan. Tanpa mengembalikannya, stok
 * tercatat berkurang 10 sementara yang benar-benar pergi hanya 8 — dan
 * selisihnya tidak akan ketahuan sampai opname berikutnya.
 *
 * SJ TIDAK BOLEH LEBIH BANYAK DARIPADA YANG DIPICKING. Kasus ini tidak
 * disebut pemilik produk dan diputuskan di sini: mengirim 12 padahal hanya 10
 * yang diambil dari rak mustahil secara fisik. Mengikuti dokumen dalam kasus
 * ini bukan "menghormati BC", melainkan membuat catatan stok berbohong —
 * jadi ia ditolak dengan pesan yang menyebut angkanya.
 *
 * STATUS PESAN TERPISAH DARI STATUS BARANG. Kalau WhatsApp gagal, truk tetap
 * berangkat (keputusan pemilik produk); kegagalannya ditandai untuk
 * ditindaklanjuti, bukan menahan pengiriman seluruh gudang karena gangguan
 * di penyedia pihak ketiga.
 */
class Shipment
{
    public function __construct(private readonly PickingRun $picking) {}

    /**
     * Perbandingan qty dokumen BC dengan yang benar-benar diambil dari rak.
     *
     * Dipakai layar SEBELUM tombol ditekan, dan dihitung ulang di dalam
     * transaksi saat benar-benar dikirim — angka di layar bisa basi bila ada
     * impor ulang di antaranya.
     *
     * @return list<array{sku:string, nama:string, qty_sj:int, qty_picking:int, selisih:int, product_id:?int}>
     */
    public function bandingkan(DeliveryNote $note): array
    {
        $order = $note->salesOrder;

        if ($order === null) {
            return [];
        }

        $terpicking = $this->qtyTerpicking($order);
        $baris = [];

        foreach ($note->lines()->with('product:id,sku,name')->get() as $line) {
            $qtyPicking = $line->product_id === null ? 0 : ($terpicking[$line->product_id] ?? 0);

            $baris[] = [
                'sku' => $line->sku,
                'nama' => $line->product?->name ?? $line->description ?? '—',
                'qty_sj' => (int) $line->qty,
                'qty_picking' => $qtyPicking,
                'selisih' => $qtyPicking - (int) $line->qty,
                'product_id' => $line->product_id,
            ];

            unset($terpicking[$line->product_id]);
        }

        // Sisa isi $terpicking adalah barang yang SUDAH diturunkan dari rak
        // tetapi tidak disebut Surat Jalan sama sekali. Ia wajib muncul:
        // seluruhnya akan dikembalikan ke rak, dan Logistik perlu tahu itu
        // sebelum menekan tombol, bukan sesudahnya.
        foreach ($terpicking as $productId => $qty) {
            $item = PickingListItem::with('product:id,sku,name')
                ->where('sales_order_id', $order->id)
                ->where('product_id', $productId)
                ->first();

            $baris[] = [
                'sku' => $item?->product?->sku ?? '—',
                'nama' => $item?->product?->name ?? '—',
                'qty_sj' => 0,
                'qty_picking' => $qty,
                'selisih' => $qty,
                'product_id' => $productId,
            ];
        }

        return $baris;
    }

    /**
     * @param  array{driver_name:string, driver_phone:string, vehicle_plate:string}  $supir
     * @return array{dikirim:int, dikembalikan:int}
     *
     * @throws RuntimeException
     */
    public function ship(DeliveryNote $note, array $supir, ?int $userId): array
    {
        return DB::transaction(function () use ($note, $supir, $userId) {
            $terkunci = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);

            // Diperiksa ULANG di dalam kunci: dua Logistik yang membuka layar
            // yang sama sama-sama melihat tombol Kirim aktif.
            $this->pastikanBolehDikirim($terkunci);

            $order = SalesOrder::query()->lockForUpdate()->findOrFail($terkunci->sales_order_id);

            if ($order->status !== SalesOrder::STATUS_READY_TO_SHIP) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s berstatus %s, bukan siap kirim. Barangnya belum selesai dipicking, atau sudah berangkat.',
                    $order->order_number,
                    strtolower($order->status_label)
                ));
            }

            $dikirim = 0;
            $dikembalikan = 0;
            $terpicking = $this->qtyTerpicking($order);
            $qtySj = [];

            foreach ($terkunci->lines as $line) {
                $qtyPicking = $line->product_id === null ? 0 : ($terpicking[$line->product_id] ?? 0);

                if ($line->qty > $qtyPicking) {
                    throw new RuntimeException(sprintf(
                        'Surat Jalan menyebut %d %s, tetapi yang benar-benar diambil dari rak hanya %d. '.
                        'Barang yang tidak ada di kendaraan tidak bisa dinyatakan berangkat — '.
                        'perbaiki dokumennya di BC, atau picking dulu kekurangannya.',
                        $line->qty,
                        $line->sku,
                        $qtyPicking,
                    ));
                }

                if ($line->product_id !== null) {
                    $qtySj[$line->product_id] = ($qtySj[$line->product_id] ?? 0) + (int) $line->qty;
                }

                $dikirim += (int) $line->qty;
            }

            // Selisihnya dikembalikan SESUDAH seluruh baris lolos pemeriksaan.
            // Kalau dikembalikan sambil jalan, satu baris yang ditolak di
            // tengah akan meninggalkan sebagian barang sudah dikembalikan —
            // transaksi memang membatalkannya, tetapi urutan ini membuat
            // maksudnya terbaca tanpa harus memercayai transaksi.
            foreach ($terpicking as $productId => $qtyPicking) {
                $kelebihan = $qtyPicking - ($qtySj[$productId] ?? 0);

                if ($kelebihan > 0) {
                    $dikembalikan += $this->picking->kembalikanSebagian(
                        $order,
                        $productId,
                        $kelebihan,
                        sprintf(
                            'Surat Jalan %s menyebut lebih sedikit; sisanya dikembalikan ke rak',
                            $terkunci->document_no
                        ),
                        $userId,
                    );
                }
            }

            $this->catatQtyTerkirim($order, $qtySj);

            $order->forceFill([
                'status' => SalesOrder::STATUS_SHIPPING,
                // Argo SLA §7.6 mulai berjalan di sini (F-OUT-04 #8).
                'shipped_at' => now(),
            ])->save();

            $terkunci->fill([
                'status' => DeliveryNote::STATUS_SHIPPED,
                'driver_name' => $supir['driver_name'],
                // Disimpan dalam bentuk kirim WhatsApp (62…), bukan bentuk
                // yang diketik. Satu bentuk simpan berarti saran ketik dan
                // pengiriman pesan membaca hal yang sama — nomor yang sama
                // ditulis dua cara adalah dua baris berbeda di daftar saran.
                'driver_phone' => PhoneNumber::forWhatsApp($supir['driver_phone']),
                'vehicle_plate' => strtoupper(trim($supir['vehicle_plate'])),
                'shipped_at' => now(),
                'shipped_by' => $userId,
                // Token dibuat SEKARANG, sekali seumur dokumen. Panjang dan
                // acak: tautan tanpa login berarti tokennya sendiri yang
                // menjadi kunci, dan token yang bisa ditebak dari nomor urut
                // membuat siapa pun bisa mengonfirmasi kiriman orang lain.
                'epod_token' => $terkunci->epod_token ?? Str::random(48),
                'notify_status' => DeliveryNote::NOTIFY_PENDING,
                'notify_attempts' => 0,
                'notify_error' => null,
            ])->save();

            return ['dikirim' => $dikirim, 'dikembalikan' => $dikembalikan];
        });
    }

    /**
     * Konfirmasi dari supir lewat tautan tanpa login.
     *
     * @throws RuntimeException
     */
    public function confirmDelivery(DeliveryNote $note, ?string $penerima): void
    {
        DB::transaction(function () use ($note, $penerima) {
            $terkunci = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);

            if ($terkunci->status === DeliveryNote::STATUS_DELIVERED) {
                // Bukan galat: supir yang menekan dua kali, atau membuka
                // tautannya lagi untuk memastikan. Diam-diam menerima
                // konfirmasi kedua akan menggeser waktu sampainya.
                throw new RuntimeException('Pengiriman ini sudah dikonfirmasi sebelumnya. Terima kasih.');
            }

            if ($terkunci->status !== DeliveryNote::STATUS_SHIPPED) {
                throw new RuntimeException('Pengiriman ini belum dinyatakan berangkat, jadi belum bisa dikonfirmasi.');
            }

            $terkunci->fill([
                'status' => DeliveryNote::STATUS_DELIVERED,
                'delivered_at' => now(),
                'received_by_name' => filled($penerima) ? trim($penerima) : null,
            ])->save();

            $order = SalesOrder::query()->lockForUpdate()->find($terkunci->sales_order_id);

            if ($order !== null && $order->status === SalesOrder::STATUS_SHIPPING) {
                $order->forceFill([
                    // Barang sampai, tetapi belum selesai: bukti Surat Jalan
                    // bertanda tangan masih harus diunggah dan diverifikasi
                    // (F-OUT-05, tahap 5).
                    'status' => SalesOrder::STATUS_PROOF_UPLOADED,
                    'delivered_at' => now(),
                ])->save();
            }
        });
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Qty yang benar-benar diambil dari rak, per produk.
     *
     * Dibaca dari baris picking, bukan dari alokasi: alokasi sudah habis
     * dipakai saat Siap Loading ditekan, dan yang tersisa sebagai catatan apa
     * yang turun dari rak hanyalah picking_list_items.
     *
     * @return array<int, int>
     */
    private function qtyTerpicking(SalesOrder $order): array
    {
        return PickingListItem::query()
            ->where('picking_list_items.sales_order_id', $order->id)
            ->where('picking_list_items.status', '<>', PickingListItem::STATUS_PENDING)
            ->join('picking_lists', 'picking_lists.id', '=', 'picking_list_items.picking_list_id')
            ->where('picking_lists.status', PickingList::STATUS_COMPLETED)
            ->groupBy('picking_list_items.product_id')
            ->selectRaw('picking_list_items.product_id, SUM(picking_list_items.qty_picked) AS qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Menuliskan qty yang benar-benar berangkat ke baris pesanan.
     *
     * `outstanding_qty` DIHITUNG ULANG dari qty pesanan dikurangi yang
     * dikirim, bukan ditambahkan ke nilai lama. Nilai lama adalah selisih
     * saat penerimaan; menambahkannya akan menghitung kekurangan yang sama
     * dua kali pada pesanan yang memang sejak awal disetujui sebagian.
     *
     * @param  array<int, int>  $qtySj
     */
    private function catatQtyTerkirim(SalesOrder $order, array $qtySj): void
    {
        foreach ($order->details as $detail) {
            $terkirim = $qtySj[$detail->product_id] ?? 0;

            $detail->forceFill([
                'qty_shipped' => $terkirim,
                'outstanding_qty' => max(0, (int) $detail->qty_ordered - $terkirim),
            ])->save();
        }
    }

    /**
     * @throws RuntimeException
     */
    private function pastikanBolehDikirim(DeliveryNote $note): void
    {
        if ($note->sales_order_id === null) {
            throw new RuntimeException(sprintf(
                'Surat Jalan %s belum menemukan pesanannya di sistem ini (SO %s). '.
                'Periksa nomor SO-nya — kalau seharusnya ada, berarti nomor yang diketik saat menerima pesanan berbeda dari BC.',
                $note->document_no,
                $note->bc_so_number,
            ));
        }

        if ($note->status === DeliveryNote::STATUS_SHIPPED) {
            throw new RuntimeException(sprintf('Surat Jalan %s sudah dinyatakan berangkat.', $note->document_no));
        }

        if ($note->status === DeliveryNote::STATUS_DELIVERED) {
            throw new RuntimeException(sprintf('Surat Jalan %s sudah sampai tujuan.', $note->document_no));
        }

        if ($note->lines()->count() < 1) {
            throw new RuntimeException(sprintf('Surat Jalan %s tidak memuat satu baris barang pun.', $note->document_no));
        }
    }
}
