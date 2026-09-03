<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ShipDeliveryNoteRequest;
use App\Jobs\SendDeliveryNotification;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Support\Outbound\Shipment;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

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
    public function __construct(private readonly Shipment $pengiriman) {}

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

    /** Rincian satu Surat Jalan: perbandingan qty, data supir, status pesan. */
    public function show(Request $request, DeliveryNote $note): View
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        return view('wms.outbound.delivery-detail', [
            'note' => $note->load([
                'lines.product:id,sku,name,uom', 'salesOrder.details.product:id,sku,name',
                'customer:id,code,name', 'warehouse:id,code,name',
                'importedBy:id,full_name', 'shippedBy:id,full_name',
            ]),
            'perbandingan' => $this->pengiriman->bandingkan($note),
            'nomorTerakhir' => $this->nomorSupirTerakhir($request),
        ]);
    }

    /** Menyatakan barang berangkat, lalu mengantre pesan untuk supir. */
    public function ship(ShipDeliveryNoteRequest $request, DeliveryNote $note): RedirectResponse
    {
        try {
            $hasil = $this->pengiriman->ship($note, [
                'driver_name' => $request->validated('driver_name'),
                'driver_phone' => $request->validated('driver_phone'),
                'vehicle_plate' => $request->validated('vehicle_plate'),
            ], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Diantrekan SETELAH transaksi selesai. Kalau dikirim dari dalam
        // transaksi, job bisa berjalan lebih dulu daripada commit dan membaca
        // dokumen yang belum punya token.
        SendDeliveryNotification::dispatch($note->id);

        $pesan = sprintf(
            'Surat Jalan %s dinyatakan berangkat: %d unit.',
            $note->document_no,
            $hasil['dikirim'],
        );

        if ($hasil['dikembalikan'] > 0) {
            // Pengembalian ke rak TIDAK boleh lewat sebagai pesan sukses
            // biasa: barang fisik baru saja berpindah kembali, dan yang
            // menaruhnya di dock perlu tahu bahwa ia harus dinaikkan lagi.
            return redirect()->route('wms.delivery.show', $note)->with('warning', $pesan.sprintf(
                ' %d unit yang sudah turun dari rak TIDAK ikut berangkat karena tidak tercantum di Surat Jalan, '.
                'dan sudah dikembalikan ke raknya masing-masing — pastikan barangnya benar-benar dinaikkan kembali.',
                $hasil['dikembalikan'],
            ));
        }

        return redirect()->route('wms.delivery.show', $note)->with('success', $pesan);
    }

    /** Mencoba mengirim ulang pesan yang gagal. */
    public function resend(Request $request, DeliveryNote $note): RedirectResponse
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        if ($note->epod_token === null) {
            return back()->with('error', 'Surat Jalan ini belum dinyatakan berangkat, jadi belum ada tautan untuk dikirim.');
        }

        $note->forceFill([
            'notify_status' => DeliveryNote::NOTIFY_PENDING,
            'notify_error' => null,
        ])->save();

        SendDeliveryNotification::dispatch($note->id);

        return back()->with('success', 'Pengiriman pesan dicoba lagi.');
    }

    /* --------------------------------------------------------------- Dalam */

    /**
     * Nomor supir yang pernah dipakai, sebagai saran ketik.
     *
     * BUKAN master data supir — pemilik produk menolaknya dengan alasan yang
     * tepat: supir berganti setiap hari dan sebagian besar dari perusahaan
     * jasa lain, sehingga daftar induk hanya akan jadi ratusan baris tak
     * terawat. Daftar ini tumbuh SENDIRI dari pengiriman yang sudah terjadi
     * dan tidak perlu dirawat siapa pun, tetapi tetap menolong pada kasus
     * yang paling sering: supir vendor yang sama datang lagi.
     *
     * @return list<array{nama:string, nomor:string, plat:string}>
     */
    private function nomorSupirTerakhir(Request $request): array
    {
        return WarehouseScope::apply(DeliveryNote::query(), $request->user())
            ->whereNotNull('driver_phone')
            ->orderByDesc('shipped_at')
            ->limit(20)
            ->get(['driver_name', 'driver_phone', 'vehicle_plate'])
            ->unique('driver_phone')
            ->values()
            ->map(fn (DeliveryNote $n) => [
                'nama' => (string) $n->driver_name,
                'nomor' => (string) $n->driver_phone,
                'plat' => (string) $n->vehicle_plate,
            ])
            ->all();
    }
}
