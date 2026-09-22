<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ShipDeliveryNoteRequest;
use App\Jobs\SendArrivalNoticeToSales;
use App\Jobs\SendDeliveryNotification;
use App\Models\ActivityLog;
use App\Models\DeliveryNote;
use App\Models\Notification;
use App\Models\SalesOrder;
use App\Models\SalesOrderEmail;
use App\Support\Activity;
use App\Support\Messaging\EmailSales;
use App\Support\Notifier;
use App\Support\Outbound\ArrivalPhoto;
use App\Support\Outbound\DeliveryArrival;
use App\Support\Outbound\Shipment;
use App\Support\Outbound\SoNumberFixer;
use App\Support\WarehouseScope;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * Karena itu tidak ada nomor dokumen yang dibangkitkan di sini. Perannya mendukung transparansi, bukan menerbitkan.
 *
 * DATA CONTRACT
 * -------------
 * index()     : $notes LengthAwarePaginator<DeliveryNote>, $filters,
 *               $statuses, $stats{menunggu,tanpa_pasangan,siap_kirim},
 *               $gudangSaya
 * siapKirim() : $pesanan LengthAwarePaginator<SalesOrder>, $filters{search,
 *               belum_sj}, $stats{semua,belum_sj,terlama_hari}
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly Shipment $pengiriman,
        private readonly DeliveryArrival $kedatangan,
    ) {}

    /**
     * Sudah dipicking, belum berangkat — PEKERJAAN YANG BERHENTI DI TENGAH.
     *
     * KENAPA HALAMAN SENDIRI. Angkanya sudah lama ada sebagai kartu di layar
     * Surat Jalan, tetapi hanya sebagai angka: Logistik tahu ADA tujuh
     * pesanan yang menggantung tanpa bisa tahu YANG MANA. Satu-satunya jalan
     * adalah menggulir Daftar Picking dan mencocokkan sendiri nomor SO-nya
     * dengan dokumen yang sudah masuk — bisa dikerjakan selama datanya
     * sedikit, dan berhenti bisa dikerjakan persis ketika datanya banyak.
     *
     * Barangnya sudah turun dari rak dan berdiri di dermaga. Pesanan yang
     * tertinggal di sini bukan angka yang salah, melainkan barang sungguhan
     * yang tidak berangkat dan tidak ada yang menyadarinya.
     *
     * YANG BELUM PUNYA SURAT JALAN SELALU DI ATAS, lalu yang paling lama
     * menunggu. Dua-duanya perlu terlihat: yang belum punya SJ menunggu
     * dokumennya terbit di BC, sementara yang sudah punya hanya menunggu
     * seseorang menekan Berangkat — sebab berbeda, tindakan berbeda, dan
     * menyembunyikan salah satunya membuat layar ini berbohong.
     */
    public function siapKirim(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'search' => $request->query('search'),
            'belum_sj' => $request->boolean('belum_sj'),
        ];

        $dasar = fn () => WarehouseScope::apply(SalesOrder::query(), $user)
            ->where('status', SalesOrder::STATUS_READY_TO_SHIP);

        $pesanan = $dasar()
            ->with(['customer:id,code,name', 'warehouse:id,code,name'])
            ->withCount('details')
            ->withSum('details as unit_disetujui', 'qty_approved')
            // Dipakai untuk menandai baris yang dokumennya sudah masuk;
            // withExists supaya tidak menarik seluruh SJ hanya demi tahu ada.
            ->withExists('deliveryNotes as punya_sj')
            ->when($filters['belum_sj'], fn ($q) => $q->whereDoesntHave('deliveryNotes'))
            ->when($filters['search'], fn ($q, $cari) => $q->where(
                fn ($w) => $w->where('order_number', 'ilike', '%'.$cari.'%')
                    ->orWhere('bc_so_number', 'ilike', '%'.$cari.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', '%'.$cari.'%'))
            ))
            ->orderByRaw('CASE WHEN EXISTS (
                SELECT 1 FROM delivery_notes WHERE delivery_notes.sales_order_id = sales_orders.id
            ) THEN 1 ELSE 0 END')
            ->orderBy('picking_completed_at')
            ->paginate(20)
            ->withQueryString();

        // Yang paling lama menunggu di antara yang belum punya SJ. Satu angka
        // yang menjawab "seberapa buruk keadaannya" tanpa membaca tabelnya.
        $tertua = $dasar()->whereDoesntHave('deliveryNotes')->min('picking_completed_at');

        return view('wms.outbound.siap-kirim', [
            'pesanan' => $pesanan,
            'filters' => $filters,
            'stats' => [
                'semua' => $dasar()->count(),
                'belum_sj' => $dasar()->whereDoesntHave('deliveryNotes')->count(),
                'terlama_hari' => $tertua === null ? 0 : (int) Carbon::parse($tertua)->startOfDay()->diffInDays(now()->startOfDay()),
            ],
        ]);
    }

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
                // Belum ada No. SO yang sama, ia belum punya gudang — menyaringnya
                // dengan WarehouseScope akan menyembunyikan persis baris yang
                // paling perlu dilihat.
                'tanpa_pasangan' => DeliveryNote::query()->belumBerpasangan()->count(),
                'siap_kirim' => WarehouseScope::apply(SalesOrder::query(), $user)
                    ->where('status', SalesOrder::STATUS_READY_TO_SHIP)
                    ->count(),
            ],
        ]);
    }

    /**
     * "Tandai Sampai" — jalan keluar ketika supir tidak bisa konfirmasi.
     *
     * Alasannya wajib dan disimpan apa adanya; lihat
     * DeliveryArrival::markArrivedManually() untuk kenapa jalur ini tanpa foto.
     */
    public function markArrived(Request $request, DeliveryNote $note): RedirectResponse
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        $data = $request->validate([
            'received_by_name' => ['required', 'string', 'max:100'],
            'arrival_manual_reason' => ['required', 'string', 'max:500'],
        ], [
            'received_by_name.required' => 'Tulis nama orang yang menerima barangnya.',
            'arrival_manual_reason.required' => 'Tulis alasan kenapa supir tidak menekan konfirmasinya sendiri.',
        ], [
            'received_by_name' => 'nama penerima',
            'arrival_manual_reason' => 'alasan',
        ]);

        try {
            $this->kedatangan->markArrivedManually(
                $note,
                $data['received_by_name'],
                $data['arrival_manual_reason'],
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $note->refresh();

        Activity::record(
            ActivityLog::ARRIVAL_MANUAL,
            sprintf(
                'Menandai Surat Jalan %s sampai secara manual (diterima %s). Alasan: %s',
                $note->document_no,
                $data['received_by_name'],
                $data['arrival_manual_reason'],
            ),
            $note,
            $note->warehouse_id,
            ['alasan' => $data['arrival_manual_reason']],
        );

        /*
         * KABAR KE SALES LEWAT JALUR YANG SAMA dengan konfirmasi supir.
         * Sales tidak perlu tahu siapa yang menekan tombolnya — yang ia
         * tunggu adalah izin mengunggah bukti, dan izin itu sekarang ada.
         * Menghilangkan kabarnya di jalur ini berarti pesanan yang justru
         * sudah bermasalah menjadi yang paling sunyi.
         */
        Notifier::toUser(
            $note->salesOrder?->user_id,
            Notification::PROOF_NEEDED,
            'Barang sampai — unggah bukti Surat Jalan',
            sprintf(
                'Surat Jalan %s ditandai sampai oleh Logistik. Pesanan %s belum bisa ditutup sebelum foto '.
                'Surat Jalan bertanda tangan diunggah.',
                $note->document_no,
                $note->salesOrder?->order_number ?? '',
            ),
            $note->sales_order_id ? url('/sales/orders/'.$note->sales_order_id) : null,
            $note->warehouse_id,
            $note,
        );

        $note->forceFill(['sales_notify_status' => DeliveryNote::NOTIFY_PENDING])->save();
        SendArrivalNoticeToSales::dispatch($note->id);

        if ($note->sales_order_id !== null) {
            EmailSales::antrekan($note->sales_order_id, SalesOrderEmail::TYPE_DELIVERED, $note->id);
        }

        return back()->with('success', sprintf(
            'Surat Jalan %s ditandai sampai. Sales sudah dikabari untuk mengunggah buktinya.',
            $note->document_no,
        ));
    }

    /**
     * Foto bukti sampai yang dijepret supir (Fase 12).
     *
     * Batas gudang berlaku di sini juga, sama seperti halaman detailnya.
     * Berkas yang disajikan lewat rute sendiri mudah terlupakan saat
     * pembatasan ditambahkan ke halamannya — dan gambar isi gudang pelanggan
     * gudang lain sama bocornya dengan tabelnya.
     */
    public function arrivalPhoto(Request $request, DeliveryNote $note, ArrivalPhoto $foto): StreamedResponse
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        return $foto->tampilkan($note);
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
            // Kenapa daftarnya kosong. Tanpa ini, "tidak ada pesanan yang
            // cocok" adalah jalan buntu: tiga sebab yang berbeda terlihat
            // sama, padahal tindak lanjutnya berbeda.
            'diagnosa' => $note->sales_order_id === null
                ? $koreksi->diagnosaKandidat($note)
                : null,
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

        if ($note->sales_order_id !== null) {
            EmailSales::antrekan($note->sales_order_id, SalesOrderEmail::TYPE_SHIPPED, $note->id);
        }

        Activity::record(
            ActivityLog::DELIVERY_SHIP,
            sprintf(
                'Menyatakan Surat Jalan %s berangkat — %d unit, supir %s (%s).',
                $note->document_no,
                $hasil['dikirim'],
                $request->validated('driver_name'),
                $request->validated('vehicle_plate'),
            ),
            $note,
            $note->warehouse_id,
            [
                'surat_jalan' => $note->document_no,
                'dikirim' => $hasil['dikirim'],
                'dikembalikan' => $hasil['dikembalikan'],
                'kurang_di_rak' => $hasil['kurang_di_rak'],
                'substitusi' => $hasil['substitusi'],
                'supir' => $request->validated('driver_name'),
                'plat' => $request->validated('vehicle_plate'),
            ],
        );

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
            // "berhasil" membuat stocktake berikutnya menemukan selisih yang
            // sudah tidak bisa dilacak asalnya.
            $peringatan[] = sprintf(
                'Surat Jalan menyebut %d unit LEBIH BANYAK daripada yang tercatat dipicking. '.
                'Selisihnya sudah dikeluarkan dari stok mengikuti dokumen — artinya isi rak sebenarnya lebih sedikit '.
                'daripada angka di sistem. Perlu ditelusuri saat stocktake.',
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

        Activity::record(
            ActivityLog::DELIVERY_SUBSTITUTION,
            sprintf(
                'Menyatakan barang di Surat Jalan %s memang yang naik (beda SKU) — %s',
                $note->document_no,
                $data['substitution_reason'],
            ),
            $note,
            $note->warehouse_id,
            ['surat_jalan' => $note->document_no, 'alasan' => $data['substitution_reason']],
        );

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

    /**
     * Mencoba mengirim ulang pesan yang gagal — atau menerbitkan tautan baru
     * bila tautan supir sudah kedaluwarsa sebelum barangnya dikonfirmasi.
     *
     * TOKENNYA DIGANTI, bukan hanya diperpanjang. Tautan lama sudah beredar
     * di chat; memperpanjangnya menghidupkan kembali setiap salinan yang
     * pernah diteruskan ke orang lain.
     */
    public function resend(Request $request, DeliveryNote $note): RedirectResponse
    {
        WarehouseScope::assert($note->warehouse_id, $request->user());

        if ($note->epod_token === null) {
            return back()->with('error', 'Surat Jalan ini belum dinyatakan berangkat, jadi belum ada tautan untuk dikirim.');
        }

        $tautanBaru = $note->tautanEpodKedaluwarsa();

        $note->forceFill(array_merge([
            'notify_status' => DeliveryNote::NOTIFY_PENDING,
            'notify_error' => null,
        ], $tautanBaru ? [
            'epod_token' => Str::random(48),
            'epod_expires_at' => now()->addHours((int) config('wms.epod.berlaku_jam')),
        ] : []))->save();

        SendDeliveryNotification::dispatch($note->id);

        return back()->with('success', $tautanBaru
            ? 'Tautan baru diterbitkan dan dikirim ke supir. Tautan lama tidak bisa dibuka lagi.'
            : 'Pengiriman pesan dicoba lagi.');
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
