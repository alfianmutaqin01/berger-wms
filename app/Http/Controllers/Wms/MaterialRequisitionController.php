<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Jobs\SendMrfApprovalRequest;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\MaterialRequisition;
use App\Models\MrfApproverContact;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Permission;
use App\Support\Production\MaterialRequisitionRun;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/**
 * MRF — Material Requisition Form, layar-layarnya.
 *
 * SATU CONTROLLER, TIGA PERAN, dan tiap pintu berdiri di balik izin yang
 * berbeda:
 *
 *   create/store   Produksi  menyusun permintaan, memilih atasan penyetuju
 *   approveForm    Logistik  memilih batch sungguhannya dari rak
 *   receive        Produksi  menyatakan barangnya sudah diambil
 *
 * Persetujuan atasan TIDAK ada di sini — ia terjadi di halaman publik
 * MrfApprovalController, karena yang menekannya tidak punya akun WMS.
 *
 * PEMBATASAN GUDANG. Permintaan material selalu menyangkut satu gudang saja,
 * jadi penyaringannya WarehouseScope::apply() biasa dan tiap titik masuk yang
 * menerima satu objek memanggil assert() — menyaring daftar saja tidak
 * menutup URL rinciannya.
 *
 * DATA CONTRACT
 * -------------
 * index()       : $halaman LengthAwarePaginator<MaterialRequisition>,
 *                 $filters, $gudangOptions, $stats
 * create()      : $produk Collection<Product>, $kontak Collection, $gudang
 * show()        : $mrf, $bolehMemutus bool, $bolehMenerima bool
 * approveForm() : $mrf, $batch Collection<InventoryStock> dikelompokkan produk
 */
class MaterialRequisitionController extends Controller
{
    public function __construct(private readonly MaterialRequisitionRun $mrf) {}

    /* ------------------------------------------------------------- Daftar */

    public function index(Request $request): View
    {
        $user = $request->user();
        $gudang = WarehouseScope::resolveFilter($request, $user);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'jenis' => $request->string('jenis')->toString() ?: null,
            'warehouse_id' => $gudang,
        ];

        $dasar = fn () => WarehouseScope::apply(MaterialRequisition::query(), $user)
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->search($filters['search'])
            ->when($filters['jenis'], fn ($q, $j) => $q->where('request_type', $j));

        $halaman = $dasar()
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->with([
                'warehouse:id,code,name',
                'requestedBy:id,full_name',
                'items:id,material_requisition_id,product_id,qty_requested',
                'handoverLocation:id,code',
            ])
            ->withCount('items')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('wms.produksi.mrf-index', [
            'halaman' => $halaman,
            'filters' => $filters,
            'gudangOptions' => WarehouseScope::options($user),
            'stats' => [
                'menunggu_atasan' => (clone $dasar())->where('status', MaterialRequisition::STATUS_PENDING_APPROVAL)->count(),
                'menunggu_logistik' => (clone $dasar())->where('status', MaterialRequisition::STATUS_PENDING_LOGISTICS)->count(),
                'menunggu_picking' => (clone $dasar())->where('status', MaterialRequisition::STATUS_PENDING_PICKING)->count(),
                'siap_diambil' => (clone $dasar())->where('status', MaterialRequisition::STATUS_READY_FOR_PICKUP)->count(),
            ],
        ]);
    }

    /* ------------------------------------------------------ Produksi: minta */

    public function create(Request $request): View
    {
        $user = $request->user();
        $gudangId = WarehouseScope::boundary($user);

        return view('wms.produksi.mrf-create', [
            'gudang' => $gudangId === null ? null : Warehouse::find($gudangId),
            'gudangOptions' => WarehouseScope::options($user),
            // Nomor yang pernah disimpan Produksi sendiri — inilah yang
            // membuat MRF kedua dan seterusnya cuma butuh satu klik.
            'kontak' => MrfApproverContact::query()
                ->untukGudang($gudangId)
                ->orderBy('name')
                ->get(),
            'jenisOptions' => MaterialRequisition::TYPES,
        ]);
    }

    /**
     * Memperbaiki permintaan yang ditolak — formulir yang sama, terisi.
     *
     * Hanya pemohonnya sendiri. Permintaan material membawa nama orang yang
     * memintanya sampai ke WhatsApp atasan; membiarkan rekan lain menyuntingnya
     * berarti nama itu berhenti berarti apa-apa.
     */
    public function edit(Request $request, MaterialRequisition $mrf): View
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());
        $this->pastikanMilikSendiri($request, $mrf);

        abort_unless(
            $mrf->bolehDiperbaiki(),
            403,
            'Hanya permintaan yang sedang ditolak yang bisa diperbaiki.',
        );

        $user = $request->user();

        $mrf->load('items.product:id,sku,name,uom');

        return view('wms.produksi.mrf-create', [
            'mrf' => $mrf,
            // Disusun di sini, bukan di Blade: ekspresi bertingkat di dalam
            // @json() tidak bisa diurai Blade, dan galatnya muncul sebagai
            // kesalahan sintaksis yang menunjuk baris yang tidak bersalah.
            'barisAwal' => $mrf->items->map(fn ($item) => [
                'id' => $item->product_id,
                'label' => trim(($item->product?->sku ?? '').' — '.($item->product?->name ?? '')),
                'qty' => $item->qty_requested,
                'note' => $item->note,
            ])->values()->all(),
            'gudang' => $mrf->warehouse,
            'gudangOptions' => WarehouseScope::options($user),
            'kontak' => MrfApproverContact::query()
                ->untukGudang($mrf->warehouse_id)
                ->orderBy('name')
                ->get(),
            'jenisOptions' => MaterialRequisition::TYPES,
        ]);
    }

    /** Menyimpan perbaikan lalu mengajukannya lagi dengan nomor yang sama. */
    public function update(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());
        $this->pastikanMilikSendiri($request, $mrf);

        abort_unless(
            $mrf->bolehDiperbaiki(),
            403,
            'Hanya permintaan yang sedang ditolak yang bisa diperbaiki.',
        );

        $data = $request->validate($this->aturanFormulir(), $this->pesanFormulir(), $this->namaFormulir());

        try {
            $mrf = $this->mrf->ajukanUlang(
                mrf: $mrf,
                jenis: $data['request_type'],
                keperluan: $data['purpose'],
                baris: array_values($data['items']),
                namaApprover: $data['approver_name'],
                nomorApprover: $data['approver_phone'],
                simpanKontak: (bool) ($data['simpan_kontak'] ?? false),
                pemohon: $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        SendMrfApprovalRequest::dispatch($mrf->id);

        Activity::record(
            ActivityLog::MRF_CREATE,
            sprintf(
                'Mengajukan ulang permintaan material %s (%s) setelah ditolak — %d produk, persetujuan '.
                'diminta lagi ke %s.',
                $mrf->mrf_number,
                $mrf->jenis_label,
                count($data['items']),
                $mrf->approver_name,
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'pengajuan_ulang' => true,
                'jenis' => $mrf->request_type,
                'keperluan' => $mrf->purpose,
                'approver' => $mrf->approver_name,
            ],
        );

        return redirect()
            ->route('wms.mrf.show', $mrf)
            ->with('success', sprintf(
                'Permintaan %s diajukan ulang ke %s dengan nomor yang sama. Catatan bahwa permintaan ini '.
                'pernah ditolak tetap tersimpan dan ikut terbaca oleh yang menyetujui.',
                $mrf->mrf_number,
                $mrf->approver_name,
            ));
    }

    /**
     * Aturan formulir MRF, dipakai bersama oleh pengajuan dan perbaikan.
     *
     * Ditulis sekali karena keduanya mengisi berkas yang sama. Kalau disalin,
     * suatu hari salah satunya diberi aturan baru dan yang lain tidak — dan
     * pengajuan ulang menjadi pintu belakang yang menerima isian yang sudah
     * tidak diterima di pintu depan.
     *
     * `warehouse_id` sengaja TIDAK di sini: hanya pengajuan pertama yang
     * memilih gudang. Perbaikan tidak boleh memindahkan berkas ke gudang lain
     * setelah nomornya beredar.
     *
     * @return array<string, list<string>>
     */
    private function aturanFormulir(): array
    {
        return [
            'request_type' => ['required', 'string', 'in:'.implode(',', array_keys(MaterialRequisition::TYPES))],
            'purpose' => ['required', 'string', 'min:5', 'max:1000'],
            'approver_name' => ['required', 'string', 'max:100'],
            'approver_phone' => ['required', 'string', 'max:25'],
            'simpan_kontak' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999999'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    private function pesanFormulir(): array
    {
        return [
            'purpose.required' => 'Keperluan wajib diisi — inilah satu-satunya keterangan yang menjelaskan kenapa barang keluar dari gudang.',
            'purpose.min' => 'Keperluan terlalu pendek untuk bisa dipahami orang yang membacanya nanti.',
            'items.required' => 'Belum ada satu produk pun yang diminta.',
            'approver_name.required' => 'Nama atasan yang akan menyetujui wajib diisi.',
            'approver_phone.required' => 'Nomor WhatsApp atasan wajib diisi.',
        ];
    }

    /** @return array<string, string> */
    private function namaFormulir(): array
    {
        return [
            'purpose' => 'keperluan',
            'approver_name' => 'nama atasan',
            'approver_phone' => 'nomor WhatsApp atasan',
        ];
    }

    /** Pemohonnya sendiri, bukan rekan sedepartemen. */
    private function pastikanMilikSendiri(Request $request, MaterialRequisition $mrf): void
    {
        abort_unless(
            $mrf->requested_by === $request->user()?->id,
            403,
            'Permintaan ini bukan milik Anda.',
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate(
            ['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']] + $this->aturanFormulir(),
            $this->pesanFormulir(),
            $this->namaFormulir(),
        );

        WarehouseScope::assert((int) $data['warehouse_id'], $user);

        try {
            $mrf = $this->mrf->ajukan(
                pemohon: $user,
                warehouseId: (int) $data['warehouse_id'],
                jenis: $data['request_type'],
                keperluan: $data['purpose'],
                baris: array_values($data['items']),
                namaApprover: $data['approver_name'],
                nomorApprover: $data['approver_phone'],
                simpanKontak: (bool) ($data['simpan_kontak'] ?? false),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Diantrekan, bukan dikirim di sini: penyedia WhatsApp yang lambat
        // tidak boleh membuat tombol Simpan seolah rusak.
        SendMrfApprovalRequest::dispatch($mrf->id);

        Activity::record(
            ActivityLog::MRF_CREATE,
            sprintf(
                'Membuat permintaan material %s (%s) — %d produk, persetujuan diminta ke %s.',
                $mrf->mrf_number,
                $mrf->jenis_label,
                count($data['items']),
                $mrf->approver_name,
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'jenis' => $mrf->request_type,
                'keperluan' => $mrf->purpose,
                'approver' => $mrf->approver_name,
            ],
        );

        return redirect()
            ->route('wms.mrf.show', $mrf)
            ->with('success', sprintf(
                'Permintaan %s tersimpan. Tautan persetujuan sudah disiapkan untuk %s — '.
                'kirimkan lewat tombol WhatsApp di halaman ini kalau belum terkirim sendiri.',
                $mrf->mrf_number,
                $mrf->approver_name,
            ));
    }

    /**
     * Pencarian produk sambil mengetik untuk formulir MRF.
     *
     * TIDAK MENYERTAKAN ANGKA STOK, dan itu disengaja. Yang menentukan ada
     * atau tidaknya barang adalah Logistik saat memilih batch — angka yang
     * ditampilkan di sini akan sudah basi sebelum permintaannya dibaca siapa
     * pun, dan angka basi yang terlihat resmi lebih menyesatkan daripada
     * tidak ada angka sama sekali.
     */
    public function lookupProducts(Request $request): JsonResponse
    {
        $cari = trim((string) $request->query('q', ''));

        if (mb_strlen($cari) < 2) {
            return response()->json([]);
        }

        $pola = '%'.str_replace('%', '\%', $cari).'%';

        $produk = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('sku', 'ILIKE', $pola)->orWhere('name', 'ILIKE', $pola))
            ->orderBy('sku')
            ->limit(10)
            ->get(['id', 'sku', 'name', 'uom']);

        return response()->json($produk->map(fn (Product $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'uom' => $p->uom,
        ])->all());
    }

    /* ------------------------------------------------------------ Rincian */

    public function show(Request $request, MaterialRequisition $mrf): View
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        $mrf->load([
            'warehouse:id,code,name',
            'requestedBy:id,full_name',
            'items.product:id,sku,name,uom',
            'items.allocations',
            'allocations.product:id,sku,name,uom',
            'allocations.pickingItem:id,material_requisition_allocation_id,location_id,status,qty_picked,discrepancy_reason',
            'allocations.pickingItem.location:id,code',
            'holdings.consumptions.consumedBy:id,full_name',
            'holdings.product:id,sku,name,uom',
            'pickingList:id,list_number,status,claimed_by',
            'pickingList.claimedBy:id,full_name',
            'handoverLocation:id,code',
            'logisticsApprovedBy:id,full_name',
            'logisticsRejectedBy:id,full_name',
            'receivedBy:id,full_name',
            'cancelledBy:id,full_name',
        ]);

        return view('wms.produksi.mrf-detail', [
            'mrf' => $mrf,
            'bolehMemutus' => $request->user()?->can(Permission::MRF_APPROVE) && $mrf->menungguLogistik(),
            'bolehMenerima' => $request->user()?->can(Permission::MRF_RECEIVE)
                && $mrf->status === MaterialRequisition::STATUS_READY_FOR_PICKUP,
            'bolehMembatalkan' => $mrf->bolehDibatalkan()
                && ($request->user()?->can(Permission::MRF_CREATE) || $request->user()?->can(Permission::MRF_APPROVE)),
        ]);
    }

    /**
     * Mengirim ulang tautan persetujuan.
     *
     * Perlu ada: nomor bisa salah ketik, dan pesan yang gagal terkirim tidak
     * boleh berarti permintaannya harus disusun ulang dari nol.
     */
    public function resend(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        if ($mrf->status !== MaterialRequisition::STATUS_PENDING_APPROVAL) {
            return back()->with('error', sprintf(
                'Permintaan %s sudah diputus (%s), jadi tautannya tidak perlu dikirim lagi.',
                $mrf->mrf_number,
                $mrf->status_label,
            ));
        }

        // Status dikembalikan ke "menunggu" supaya job mau mencoba lagi —
        // ia sengaja menolak mengirim ulang yang sudah berstatus terkirim.
        $mrf->forceFill([
            'notify_status' => MaterialRequisition::NOTIFY_PENDING,
            'notify_error' => null,
        ])->save();

        SendMrfApprovalRequest::dispatch($mrf->id);

        return back()->with('success', sprintf(
            'Tautan persetujuan dikirim ulang ke %s (%s).',
            $mrf->approver_name,
            $mrf->approver_phone,
        ));
    }

    /* ------------------------------------------------ Logistik: pilih batch */

    public function approveForm(Request $request, MaterialRequisition $mrf): View|RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        if (! $mrf->menungguLogistik()) {
            return redirect()->route('wms.mrf.show', $mrf)->with('error', sprintf(
                'MRF %s berstatus "%s", bukan menunggu keputusan Logistik.',
                $mrf->mrf_number,
                $mrf->status_label,
            ));
        }

        $mrf->load(['items.product:id,sku,name,uom', 'warehouse:id,code,name', 'requestedBy:id,full_name']);

        return view('wms.produksi.mrf-approve', [
            'mrf' => $mrf,
            // Batch per produk yang diminta saja. Menyodorkan seluruh isi
            // gudang membuat layar ini tidak terbaca, dan yang dicari Logistik
            // memang cuma produk yang tertulis di permintaannya.
            'batchPerProduk' => $this->batchUntukPermintaan($mrf),
        ]);
    }

    public function approve(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        $data = $request->validate([
            'baris' => ['required', 'array', 'min:1'],
            'baris.*.item_id' => ['required', 'integer'],
            'baris.*.stock_id' => ['required', 'integer'],
            'baris.*.qty' => ['required', 'integer', 'min:1'],
        ], [
            'baris.required' => 'Belum ada satu batch pun yang dipilih. Isi qty pada batch yang akan diambilkan.',
        ]);

        try {
            $mrf = $this->mrf->setujuiLogistik($mrf, array_values($data['baris']), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $mrf->load('pickingList:id,list_number');

        Activity::record(
            ActivityLog::MRF_LOGISTICS_APPROVE,
            sprintf(
                'Menyetujui permintaan material %s: %d batch dicadangkan, daftar picking %s dibuat.',
                $mrf->mrf_number,
                $mrf->allocations()->count(),
                $mrf->pickingList?->list_number ?? '—',
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'daftar_picking' => $mrf->pickingList?->list_number,
                'batch' => $mrf->allocations()->count(),
            ],
        );

        Notifier::toUser(
            $mrf->requested_by,
            Notification::MRF_DECIDED,
            'Permintaan material disetujui Logistik',
            sprintf(
                'MRF %s disetujui. Barangnya sedang diambilkan operator; Anda akan dikabari lagi begitu siap diambil.',
                $mrf->mrf_number,
            ),
            route('wms.mrf.show', $mrf),
            $mrf->warehouse_id,
            $mrf,
        );

        return redirect()->route('wms.mrf.show', $mrf)->with('success', sprintf(
            'MRF %s disetujui. Daftar picking %s sudah masuk antrean operator.',
            $mrf->mrf_number,
            $mrf->pickingList?->list_number ?? '—',
        ));
    }

    public function reject(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi — Produksi perlu tahu apakah harus menunggu atau mencari jalan lain.',
        ], ['reason' => 'alasan penolakan']);

        try {
            $mrf = $this->mrf->tolakLogistik($mrf, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::MRF_LOGISTICS_REJECT,
            sprintf('Menolak permintaan material %s. Alasan: %s', $mrf->mrf_number, $data['reason']),
            $mrf,
            $mrf->warehouse_id,
            ['nomor' => $mrf->mrf_number, 'alasan' => $data['reason']],
        );

        Notifier::toUser(
            $mrf->requested_by,
            Notification::MRF_DECIDED,
            'Permintaan material ditolak Logistik',
            sprintf('MRF %s ditolak. Alasan: %s', $mrf->mrf_number, $data['reason']),
            route('wms.mrf.show', $mrf),
            $mrf->warehouse_id,
            $mrf,
        );

        return redirect()->route('wms.mrf.show', $mrf)->with('warning', sprintf(
            'MRF %s ditolak dan Produksi sudah dikabari.',
            $mrf->mrf_number,
        ));
    }

    /* -------------------------------------------------- Produksi: menerima */

    public function receive(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        $data = $request->validate([
            'production_area' => ['nullable', 'string', 'max:60'],
        ], [], ['production_area' => 'lokasi di area produksi']);

        try {
            $hasil = $this->mrf->terima(
                $mrf,
                $data['production_area'] ?? 'Transit Produksi',
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::MRF_RECEIVE,
            sprintf(
                'Produksi menerima material dari %s: %d unit dalam %d batch, ditaruh di %s.',
                $mrf->mrf_number,
                $hasil['unit'],
                $hasil['baris'],
                $data['production_area'] ?? 'Transit Produksi',
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'unit' => $hasil['unit'],
                'batch' => $hasil['baris'],
                'area' => $data['production_area'] ?? 'Transit Produksi',
            ],
        );

        return redirect()->route('wms.material-produksi.index')->with('success', sprintf(
            'Material dari %s diterima: %d unit dalam %d batch. Barangnya sekarang tercatat di buku Produksi — '.
            'catat pemakaiannya di halaman ini setiap kali dipakai, supaya sisanya tidak terlupakan.',
            $mrf->mrf_number,
            $hasil['unit'],
            $hasil['baris'],
        ));
    }

    /* ------------------------------------------------------------ Batalkan */

    public function cancel(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['reason' => 'alasan pembatalan']);

        try {
            $this->mrf->batal($mrf, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::MRF_CANCEL,
            sprintf('Membatalkan permintaan material %s. Alasan: %s', $mrf->mrf_number, $data['reason']),
            $mrf,
            $mrf->warehouse_id,
            ['nomor' => $mrf->mrf_number, 'alasan' => $data['reason']],
        );

        return redirect()->route('wms.mrf.index')->with('warning', sprintf(
            'Permintaan %s dibatalkan. Barang yang sempat dicadangkan sudah kembali bisa dipakai.',
            $mrf->mrf_number,
        ));
    }

    /* ------------------------------------------------------- Kontak approver */

    /** Menghapus nomor yang tersimpan — mis. atasan yang sudah pindah bagian. */
    public function destroyContact(Request $request, MrfApproverContact $contact): RedirectResponse
    {
        $gudang = WarehouseScope::boundary($request->user());

        abort_if(
            $gudang !== null && $contact->warehouse_id !== null && $contact->warehouse_id !== $gudang,
            403,
            'Kontak ini milik gudang lain.'
        );

        $nama = $contact->name;
        $contact->delete();

        return back()->with('success', sprintf(
            'Nomor %s dihapus dari daftar tersimpan. MRF yang sudah pernah dikirim ke nomor itu tidak berubah.',
            $nama,
        ));
    }

    /* --------------------------------------------------------------- Dalam */

    /**
     * Batch yang bisa dipilih Logistik, dikelompokkan per baris permintaan.
     *
     * DDP IKUT, dan itu justru intinya. Jenis permintaan yang paling sering
     * dipakai adalah reproses barang DDP; menyaringnya keluar seperti pada
     * penjualan membuat layar ini kosong persis pada perkara yang paling
     * sering terjadi. Barang karantina TIDAK ikut — ia sedang ditahan QC dan
     * belum boleh ke mana-mana.
     *
     * @return array<int, Collection> dikunci id baris permintaan
     */
    private function batchUntukPermintaan(MaterialRequisition $mrf): array
    {
        $produkId = $mrf->items->pluck('product_id')->unique()->all();

        $batch = InventoryStock::query()
            ->where('warehouse_id', $mrf->warehouse_id)
            ->whereIn('product_id', $produkId)
            ->where('qty_available', '>', 0)
            ->whereIn('status', [InventoryStock::STATUS_ACTIVE, InventoryStock::STATUS_DDP])
            ->with(['location:id,code', 'product:id,sku,name,uom'])
            // FIFO: yang paling dekat kedaluwarsa di atas. Untuk reproses,
            // itu pula yang paling pantas didahulukan.
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $hasil = [];

        foreach ($mrf->items as $item) {
            $hasil[$item->id] = $batch->get($item->product_id, collect());
        }

        return $hasil;
    }
}
