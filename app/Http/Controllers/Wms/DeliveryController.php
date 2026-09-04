<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ShipDeliveryNoteRequest;
use App\Jobs\SendDeliveryNotification;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Support\Outbound\Shipment;
use App\Support\Outbound\SoNumberFixer;
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

        /*
         * SJ YATIM SELALU IKUT TERLIHAT. Dokumen yang belum menemukan
         * pesanannya belum punya gudang (importir mengambil gudang dari
         * pesanan yang saat itu tidak ketemu), sehingga penyaringan gudang
         * biasa justru menyembunyikan persis baris yang paling perlu
         * ditindak — dan saringan "tanpa pasangan" di bawah akan mengembalikan
         * daftar kosong padahal kartu di atas menghitungnya.
         */
        $batas = WarehouseScope::boundary($user);

        $terlihat = fn () => DeliveryNote::query()
            ->when($batas, fn ($q) => $q->where(
                fn ($w) => $w->where('warehouse_id', $batas)->orWhereNull('warehouse_id')
            ));

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
    public function show(Request $request, DeliveryNote $note, SoNumberFixer $koreksi): View
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        return view('wms.outbound.delivery-detail', [
            // Hanya dihitung untuk SJ yatim: pada dokumen yang sudah
            // berpasangan, daftar ini tidak akan pernah dipakai.
            'kandidat' => $note->sales_order_id === null
                ? $koreksi->kandidat($note)
                : collect(),
            'note' => $note->load([
                'lines.product:id,sku,name,uom', 'salesOrder.details.product:id,sku,name',
                'customer:id,code,name', 'warehouse:id,code,name',
                'importedBy:id,full_name', 'shippedBy:id,full_name',
                'substitutionConfirmedBy:id,full_name',
            ]),
            'perbandingan' => $this->pengiriman->bandingkan($note),
            // SKU berbeda MENGHENTIKAN pengiriman; layar perlu tahu itu
            // sebelum formulir supir digambar.
            'bedaSku' => $this->pengiriman->skuTidakCocok($note),
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

        // Keadaan yang TIDAK boleh lewat sebagai pesan sukses hijau, karena
        // semuanya menuntut tindakan orang di lapangan.
        $peringatan = [];

        if ($hasil['substitusi']) {
            // Disebut lebih dulu: inilah yang membuat dua peringatan di
            // bawahnya (barang kembali ke rak, barang keluar dari rak) masuk
            // akal. Tanpa kalimat ini keduanya terbaca sebagai dua masalah
            // terpisah, persis kekeliruan yang membuat fitur ini ada.
            $peringatan[] =
                'Pengiriman ini memakai BARANG PENGGANTI: SKU di Surat Jalan berbeda dari yang dipicking, '.
                'dan penggantiannya sudah dikonfirmasi. Baris pesanan yang digantikan ditutup, bukan '.
                'dibiarkan outstanding. Ini BUKAN selisih stok.';
        }

        if ($hasil['dikembalikan'] > 0) {
            // Barang fisik baru saja berpindah kembali ke rak, dan yang
            // menaruhnya di dock perlu tahu bahwa ia TIDAK boleh naik.
            $peringatan[] = sprintf(
                '%d unit yang sudah turun dari rak TIDAK ikut berangkat karena tidak tercantum di Surat Jalan, '.
                'dan sudah dikembalikan ke raknya masing-masing — pastikan barangnya benar-benar tidak naik ke kendaraan.',
                $hasil['dikembalikan'],
            );
        }

        if ($hasil['kurang_di_rak'] > 0) {
            // Temuan stok kurang. Ini justru yang paling berharga dari
            // seluruh pencocokan ini, dan menyembunyikannya di balik kata
            // "berhasil" membuat opname berikutnya menemukan selisih yang
            // sudah tidak bisa dilacak asalnya.
            $peringatan[] = sprintf(
                'Surat Jalan menyebut %d unit LEBIH BANYAK daripada yang tercatat dipicking. '.
                'Selisihnya sudah dikeluarkan dari stok mengikuti dokumen — artinya isi rak sebenarnya lebih sedikit '.
                'daripada angka di sistem. Perlu ditelusuri saat opname.',
                $hasil['kurang_di_rak'],
            );
        }

        if ($hasil['tidak_tertutup'] !== []) {
            $peringatan[] = sprintf(
                'Stok tercatat pun tidak cukup menutupi kekurangan pada: %s. '.
                'Angka stoknya tidak diturunkan di bawah nol; selisih ini WAJIB dibereskan lewat Penyesuaian Stok.',
                implode(', ', $hasil['tidak_tertutup']),
            );
        }

        return redirect()->route('wms.delivery.show', $note)->with(
            $peringatan === [] ? 'success' : 'warning',
            $peringatan === [] ? $pesan : $pesan.' '.implode(' ', $peringatan),
        );
    }

    /**
     * Menyatakan barang beda SKU memang yang naik kendaraan.
     *
     * Pintu terpisah, bukan centang di formulir berangkat: centang yang
     * menempel pada formulir yang sama akan ikut tercentang bersama yang lain.
     */
    public function confirmSubstitution(Request $request, DeliveryNote $note): RedirectResponse
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        $data = $request->validate([
            'substitution_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'substitution_reason.required' => 'Alasan wajib diisi.',
            'substitution_reason.min' => 'Tulis alasannya minimal 10 karakter, mis. "pelanggan setuju diganti ukuran 20Kg karena 5Kg kosong".',
        ], [
            'substitution_reason' => 'alasan penggantian',
        ]);

        try {
            $this->pengiriman->confirmSubstitution(
                $note,
                $data['substitution_reason'],
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('warning', sprintf(
            'Penggantian barang pada Surat Jalan %s dikonfirmasi atas nama Anda. '.
            'Barang yang semula dipicking akan dikembalikan ke rak, dan barang di Surat Jalan yang dikeluarkan. '.
            'Pastikan yang naik kendaraan memang barang di Surat Jalan.',
            $note->document_no,
        ));
    }

    /**
     * Memasangkan Surat Jalan yatim ke pesanannya (Fase 6 tahap 5).
     *
     * Inilah pintu utama untuk salah ketik nomor SO. Nomornya TIDAK diketik
     * ulang di sini — sistem menyalinnya dari dokumen BC. Lihat SoNumberFixer.
     */
    public function pair(Request $request, DeliveryNote $note, SoNumberFixer $koreksi): RedirectResponse
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        $data = $request->validate(
            ['sales_order_id' => ['required', 'integer']],
            ['sales_order_id.required' => 'Pilih dulu pesanan yang mau dipasangkan.'],
        );

        $order = SalesOrder::query()->find($data['sales_order_id']);

        if ($order === null) {
            return back()->with('error', 'Pesanan yang dipilih tidak ditemukan. Muat ulang halaman lalu coba lagi.');
        }

        WarehouseScope::assert($order->warehouse_id, $request->user());

        try {
            $koreksi->pair($note, $order, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Surat Jalan %s dipasangkan ke pesanan %s. Nomor SO pesanan disamakan dengan dokumen BC (%s).',
            $note->document_no,
            $order->order_number,
            $note->bc_so_number,
        ));
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
