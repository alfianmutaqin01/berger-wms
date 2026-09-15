<?php

namespace App\Support\Outbound;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\SalesOrder;
use App\Support\Activity;
use App\Support\DocumentNumber;
use App\Support\Notifier;
use App\Support\Permission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Cara sebuah pesanan dibentuk — SATU tempat, dua pintu masuk.
 *
 * KENAPA DIANGKAT KE SINI
 * -----------------------
 * Sampai sekarang hanya ada satu pintu: Sales membuat pesanannya sendiri.
 * Sejak Admin/Manager boleh membuat pesanan atas nama Sales, pintunya jadi
 * dua — dan kalau masing-masing menyalin caranya sendiri, keduanya akan
 * berbeda pendapat suatu hari tentang hal yang tidak boleh berbeda: kapan
 * SLA mulai dihitung, penanda penolakan lama dibersihkan atau tidak, siapa
 * yang diberi tahu, dan apa yang tercatat di log.
 *
 * Bedanya kedua pintu itu hanya SATU, dan cuma itu yang boleh berbeda:
 * siapa yang mengetiknya. Selebihnya harus identik, karena begitu masuk
 * antrean Logistik tidak ada lagi yang membedakan keduanya — pesanan tetap
 * pesanan.
 *
 * BERKASNYA TETAP DI LUAR TRANSAKSI. Menyimpan dokumen PO ke disk lalu gagal
 * menulis barisnya meninggalkan berkas yatim; kebalikannya meninggalkan baris
 * yang menunjuk berkas yang tidak ada. Yang pertama jauh lebih murah.
 */
class OrderComposer
{
    public const DISK = 'local';

    public const FOLDER = 'sales-orders';

    /**
     * Pesanan kosong yang siap diisi.
     *
     * `placed_by` diisi HANYA kalau yang mengetik bukan Sales-nya sendiri.
     * Mewakili diri sendiri tidak berarti apa-apa dan membuat layar menulis
     * "dibuat Budi atas nama Budi" — basis data pun menolaknya lewat CHECK
     * sales_orders_placed_by_bukan_diri_sendiri.
     */
    public function baru(int $salesUserId, ?int $diketikOleh = null): SalesOrder
    {
        return new SalesOrder([
            // Nomor internal SELALU dibuat, termasuk untuk pesanan bermetode
            // dokumen — nomor PO customer tidak dijamin unik antar pelanggan,
            // jadi tidak bisa jadi identitas sistem.
            'order_number' => DocumentNumber::forSalesOrder(),
            'user_id' => $salesUserId,
            'placed_by' => ($diketikOleh !== null && $diketikOleh !== $salesUserId) ? $diketikOleh : null,
            'status' => SalesOrder::STATUS_DRAFT,
        ]);
    }

    /**
     * Mengisi kolom pesanan dari isian formulir.
     *
     * @param  array<string, mixed>  $data
     */
    public function isi(SalesOrder $order, array $data, ?UploadedFile $berkas): void
    {
        $order->fill([
            'customer_id' => $data['customer_id'],
            'warehouse_id' => $data['warehouse_id'],
            'payment_term_id' => $data['payment_term_id'],
            'order_source' => $data['order_source'],
            'notes' => $data['notes'] ?? null,
            'customer_po_number' => $data['order_source'] === SalesOrder::SOURCE_DOCUMENT
                ? $data['customer_po_number']
                : null,
        ]);

        if ($berkas !== null) {
            // Berkas lama dihapus SETELAH yang baru tersimpan, supaya
            // kegagalan penyimpanan tidak meninggalkan pesanan tanpa dokumen.
            $lama = $order->document_path;

            $order->fill([
                'document_path' => $berkas->store(self::FOLDER, self::DISK),
                'document_name' => $berkas->getClientOriginalName(),
                'document_size' => $berkas->getSize(),
                'document_mime' => $berkas->getMimeType(),
            ]);

            $this->hapusDokumen($lama);
        }

        // Berpindah ke metode rincian: dokumen lamanya tidak lagi berarti.
        if ($data['order_source'] === SalesOrder::SOURCE_MANUAL && filled($order->document_path)) {
            $this->hapusDokumen($order->document_path);
            $order->fill([
                'document_path' => null, 'document_name' => null,
                'document_size' => null, 'document_mime' => null,
            ]);
        }
    }

    /**
     * Menulis baris item.
     *
     * Pesanan bermetode dokumen sengaja TIDAK punya baris item — rinciannya
     * diisi Logistik saat approval sambil membaca dokumennya.
     *
     * @param  array<string, mixed>  $data
     */
    public function tulisRincian(SalesOrder $order, array $data): void
    {
        if ($data['order_source'] === SalesOrder::SOURCE_DOCUMENT) {
            return;
        }

        foreach ($data['items'] ?? [] as $item) {
            $order->details()->create([
                'product_id' => $item['product_id'],
                'qty_ordered' => $item['qty'],
            ]);
        }
    }

    /** Submit: status berpindah dan SLA (§7.6) mulai dihitung dari sini. */
    public function kirimKeLogistik(SalesOrder $order): void
    {
        $order->forceFill([
            'status' => SalesOrder::STATUS_PENDING,
            'submitted_at' => now(),
            // Pesanan yang pernah ditolak lalu diperbaiki: penanda
            // penolakannya dibersihkan supaya keadaan SEKARANG-nya jujur —
            // ia sedang menunggu dinilai, bukan sedang ditolak. Riwayatnya
            // tetap utuh di sales_order_rejections, dan dari sanalah catatan
            // "pernah ditolak" tetap terbaca sampai akhir.
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
        ])->save();

        $order->loadMissing(['customer:id,name', 'user:id,full_name', 'placedBy:id,full_name']);

        $lewatOrangLain = $order->placed_by !== null;

        /*
         * Percobaan ke berapa ikut dicatat: pesanan yang bolak-balik ditolak
         * lalu diajukan lagi adalah pola yang justru paling perlu terbaca,
         * dan kolom di pesanannya sendiri sudah dibersihkan di atas.
         */
        Activity::record(
            ActivityLog::ORDER_SUBMIT,
            sprintf(
                'Mengirim pesanan %s untuk %s ke Logistik%s.',
                $order->order_number,
                $order->customer?->name ?? 'pelanggan',
                $lewatOrangLain
                    ? ' — dibuat '.($order->placedBy?->full_name ?? 'pengguna internal')
                        .' atas nama '.($order->user?->full_name ?? 'Sales')
                    : '',
            ),
            $order,
            $order->warehouse_id,
            [
                'nomor_po_customer' => $order->customer_po_number,
                'pengajuan_ke' => $order->rejections()->count() + 1,
                // Ditulis apa adanya, termasuk saat NULL: yang menelusuri
                // sengketa perlu bisa membedakan "Sales sendiri" dari "belum
                // sempat dicatat".
                'jalur' => $lewatOrangLain ? 'internal' : 'sales',
                'diketik_oleh' => $order->placedBy?->full_name,
            ],
        );

        Notifier::toPermission(
            Permission::OUTBOUND_APPROVAL,
            $order->warehouse_id,
            Notification::ORDER_PENDING,
            'Pesanan baru menunggu diterima',
            sprintf(
                '%s dari %s diajukan %s.',
                $order->order_number,
                $order->customer?->name ?? 'pelanggan',
                $lewatOrangLain
                    ? ($order->placedBy?->full_name ?? 'pengguna internal')
                        .' atas nama '.($order->user?->full_name ?? 'Sales')
                    : ($order->user?->full_name ?? 'Sales'),
            ),
            route('wms.approval.index'),
            $order,
        );

        /*
         * SALES-NYA SENDIRI DIBERI TAHU — dan ini bukan kesopanan.
         *
         * Pesanan muncul di daftarnya atas namanya, dan dialah yang nanti
         * harus mengunggah foto Surat Jalan bertanda tangan. Tanpa kabar ini
         * ia menemukan pesanan yang tidak pernah ia buat tanpa penjelasan
         * apa pun — dan karena pembuatnya boleh menyetujui sendiri, Sales
         * inilah satu-satunya orang di luar rantai itu yang bisa menyadari
         * kalau ada yang tidak beres.
         */
        if ($lewatOrangLain) {
            Notifier::toUser(
                $order->user_id,
                Notification::ORDER_PENDING,
                'Pesanan dibuat atas nama Anda',
                sprintf(
                    '%s untuk %s dibuat %s dan sudah masuk antrean Logistik. Bukti Surat Jalan nanti diunggah dari daftar pesanan Anda.',
                    $order->order_number,
                    $order->customer?->name ?? 'pelanggan',
                    $order->placedBy?->full_name ?? 'pengguna internal',
                ),
                url('/sales/orders/'.$order->id),
                $order->warehouse_id,
                $order,
            );
        }
    }

    public function hapusDokumen(?string $path): void
    {
        if (filled($path) && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
