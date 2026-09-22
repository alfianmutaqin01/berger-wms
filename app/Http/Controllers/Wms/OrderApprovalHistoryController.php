<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Support\WarehouseScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Riwayat penerimaan, penolakan, dan pembatalan pesanan — permintaan pemilik
 * produk. Hanya untuk dibaca; keputusan atas pesanan ada di
 * OrderApprovalController.
 *
 * DATA CONTRACT
 * -------------
 * history()     : $orders LengthAwarePaginator<SalesOrder>, $filters{search,hasil}
 * historyShow() : $order SalesOrder, $totals{dipesan,disetujui,terkirim,outstanding}
 */
class OrderApprovalHistoryController extends Controller
{
    /**
     * Rincian pesanan yang sudah dinilai — hanya untuk dibaca.
     *
     * KENAPA LAYAR SENDIRI, BUKAN show() YANG DILONGGARKAN
     * ----------------------------------------------------
     * show() adalah layar KEPUTUSAN: ia menghitung stok tersedia, mencari
     * barang yang sama di gudang lain, dan memasang tombol Terima/Tolak.
     * Melonggarkannya agar juga melayani pesanan yang sudah dinilai berarti
     * satu layar dengan dua watak, dan cepat atau lambat sebuah tombol
     * keputusan muncul di keadaan yang seharusnya tidak menerimanya lagi.
     *
     * YANG DIJAWAB LAYAR INI
     * ----------------------
     * "Waktu itu apa saja yang saya setujui, dan berapa." Sebelum ada layar
     * ini, daftar riwayat hanya menyebut "12 item" tanpa satu pun cara
     * membukanya — sehingga pertanyaan yang paling wajar tentang penerimaan
     * yang sudah lewat justru tidak bisa dijawab dari menu penerimaan.
     *
     * Ditampilkan APA ADANYA sampai hari ini: qty dipesan, disetujui,
     * terkirim, dan sisa outstanding. Tiga angka terakhir memang bergerak
     * setelah penerimaan, dan itu bukan alasan menyembunyikannya — justru
     * di situlah terlihat apakah yang disetujui benar-benar sampai.
     */
    public function historyShow(Request $request, SalesOrder $order): View
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $order->load([
            'customer', 'user:id,full_name', 'warehouse', 'paymentTerm',
            'approvedBy:id,full_name', 'rejectedBy:id,full_name', 'cancelledBy:id,full_name',
            'placedBy:id,full_name',
            'details.product:id,sku,name,uom',
            'rejections' => fn ($q) => $q->with('rejectedBy:id,full_name')->orderByDesc('attempt_no'),
            'cancellations' => fn ($q) => $q->with('cancelledBy:id,full_name')->latest('cancelled_at'),
            'emails' => fn ($q) => $q->with('deliveryNote:id,document_no')->orderBy('id'),
        ]);

        return view('wms.outbound.approval-history-detail', [
            'order' => $order,
            'totals' => [
                'dipesan' => (int) $order->details->sum('qty_ordered'),
                'disetujui' => (int) $order->details->sum('qty_approved'),
                'terkirim' => (int) $order->details->sum('qty_shipped'),
                'outstanding' => (int) $order->details->sum('outstanding_qty'),
            ],
        ]);
    }

    /** Riwayat penerimaan, penolakan, dan pembatalan (permintaan pemilik produk). */
    public function history(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'hasil' => $request->query('hasil'),
        ];

        $orders = WarehouseScope::apply(SalesOrder::query(), $request->user())
            // Dibungkus where() sendiri: tanpa itu orWhere di dalamnya akan
            // membatalkan filter pencarian dan filter hasil di sebelahnya,
            // sehingga riwayat memunculkan pesanan yang tidak dicari.
            ->where(fn ($q) => $q->whereNotNull('approved_at')
                ->orWhereNotNull('rejected_at')
                ->orWhereNotNull('cancelled_at')
                // Pesanan yang PERNAH dibatalkan lalu diterima lagi: kolom
                // cancelled_at-nya sudah dibersihkan supaya keadaan sekarang
                // jujur, jadi hanya tabel riwayat yang masih mengingatnya.
                ->orWhereHas('cancellations')
                // Sama halnya dengan penolakan: pesanan yang ditolak lalu
                // diperbaiki dan diajukan ulang sudah tidak punya rejected_at
                // lagi, dan hanya tabel riwayatnya yang masih mengingat.
                ->orWhereHas('rejections'))
            ->search($filters['search'])
            // "diterima" TIDAK mencakup yang sudah dibatalkan: pesanan yang
            // dibatalkan memang pernah diterima, tetapi hasil akhirnya bukan
            // itu lagi, dan menghitungnya sebagai diterima membuat rekap
            // penerimaan lebih besar daripada yang benar-benar berjalan.
            ->when($filters['hasil'] === 'diterima', fn ($q) => $q->whereNotNull('approved_at')->whereNull('cancelled_at'))
            ->when($filters['hasil'] === 'ditolak', fn ($q) => $q->whereHas('rejections'))
            // Menyaring lewat tabel riwayat, bukan lewat cancelled_at: pesanan
            // yang dibatalkan lalu diterima lagi tetap harus bisa ditemukan di
            // sini — pembatalannya benar-benar pernah terjadi, dan justru
            // pesanan seperti itulah yang paling perlu ditelusuri.
            ->when($filters['hasil'] === 'dibatalkan', fn ($q) => $q->whereHas('cancellations'))
            ->with(['customer:id,code,name', 'warehouse:id,code,name',
                'approvedBy:id,full_name', 'rejectedBy:id,full_name', 'cancelledBy:id,full_name',
                'cancellations' => fn ($q) => $q->with('cancelledBy:id,full_name')->latest('cancelled_at'),
                'rejections' => fn ($q) => $q->with('rejectedBy:id,full_name')->latest('rejected_at')])
            ->withCount(['details', 'cancellations', 'rejections'])
            ->orderByDesc(DB::raw("GREATEST(COALESCE(approved_at, 'epoch'), COALESCE(rejected_at, 'epoch'), COALESCE(cancelled_at, 'epoch'))"))
            ->paginate(15)
            ->withQueryString();

        return view('wms.outbound.approval-history', [
            'orders' => $orders,
            'filters' => $filters,
        ]);
    }
}
