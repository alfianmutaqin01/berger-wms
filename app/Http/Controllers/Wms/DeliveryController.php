<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Support\WarehouseScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Surat Jalan & Pengiriman — PRD §6.5 F-OUT-04, Fase 6 tahap 4.
 *
 * SISTEM INI TIDAK MENERBITKAN SURAT JALAN. Dokumen resminya keluar dari
 * sistem BC (keputusan pemilik produk). Yang dikerjakan di sini:
 *
 *   1. MENYALIN Surat Jalan yang sudah terbit di BC (impor Excel harian).
 *   2. MENCOCOKKAN qty dokumen itu dengan apa yang benar-benar diambil
 *      operator dari rak.
 *   3. MENYATAKAN barang berangkat, lalu mengirim tautan konfirmasi ke supir.
 *
 * Karena itu tidak ada nomor dokumen yang dibangkitkan di sini, dan tidak
 * ada tombol cetak. Perannya mendukung transparansi, bukan menerbitkan.
 *
 * DATA CONTRACT
 * -------------
 * index() : $notes LengthAwarePaginator<DeliveryNote>, $filters,
 *           $statuses, $stats{menunggu,tanpa_pasangan,siap_kirim},
 *           $gudangSaya
 */
class DeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            // Saringan tersendiri karena inilah yang paling perlu ditindak:
            // SJ tanpa pasangan berarti nomor SO di BC berbeda dari yang
            // diketik saat menerima pesanan.
            'tanpa_pasangan' => $request->boolean('tanpa_pasangan'),
        ];

        $terlihat = fn () => WarehouseScope::apply(DeliveryNote::query(), $user);

        $notes = $terlihat()
            ->with(['salesOrder:id,order_number,status', 'customer:id,code,name', 'warehouse:id,code,name'])
            ->withCount('lines')
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['tanpa_pasangan'], fn ($q) => $q->belumBerpasangan())
            // Yang belum berangkat selalu di atas: itu satu-satunya yang
            // menunggu tindakan seseorang.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [DeliveryNote::STATUS_IMPORTED])
            ->orderByDesc('shipment_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('wms.outbound.delivery', [
            'notes' => $notes,
            'filters' => $filters,
            'statuses' => DeliveryNote::STATUS_LABELS,
            'gudangSaya' => $user?->warehouse,
            'stats' => [
                'menunggu' => $terlihat()->where('status', DeliveryNote::STATUS_IMPORTED)->count(),
                // SJ tanpa pasangan TIDAK ikut disaring gudang: justru karena
                // belum berpasangan, ia belum punya gudang — menyaringnya
                // dengan WarehouseScope akan menyembunyikan persis baris yang
                // paling perlu dilihat.
                'tanpa_pasangan' => DeliveryNote::query()->belumBerpasangan()->count(),
                'siap_kirim' => WarehouseScope::apply(SalesOrder::query(), $user)
                    ->where('status', SalesOrder::STATUS_READY_TO_SHIP)
                    ->count(),
            ],
        ]);
    }
}
