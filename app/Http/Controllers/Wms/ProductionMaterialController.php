<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\ProductionMaterialConsumption;
use App\Models\ProductionMaterialHolding;
use App\Support\Activity;
use App\Support\FilterTanggal;
use App\Support\Production\MaterialRequisitionRun;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * "MRF Picked" — buku besar milik Produksi.
 *
 * LAYAR YANG MENJAWAB PERTANYAAN YANG SELAMA INI TIDAK PUNYA JAWABAN:
 * barang apa saja yang pernah diminta Produksi, berapa yang sudah dipakai,
 * dan berapa yang masih berdiri di lantai menunggu dikerjakan. Sebelum ini,
 * satu-satunya tempat jawabannya tinggal adalah ingatan orang — dan ingatan
 * itulah yang habis lebih dulu.
 *
 * BARIS YANG MENUA DIANGKAT KE ATAS, bukan diurutkan menurut yang terbaru.
 * Yang berbahaya justru yang paling lama: 150 pcs sisa reproses bulan lalu
 * yang tidak lagi diingat siapa pun. Daftar yang menaruh yang terbaru di atas
 * akan menenggelamkannya persis pada saat ia paling perlu dilihat.
 *
 * DATA CONTRACT
 * -------------
 * index() : $halaman LengthAwarePaginator<ProductionMaterialHolding>,
 *           $filters, $gudangOptions, $stats
 */
class ProductionMaterialController extends Controller
{
    /** Sisa yang lebih tua dari ini dianggap menunggak dan ditandai merah. */
    private const AMBANG_MENUNGGAK_HARI = 30;

    public function __construct(private readonly MaterialRequisitionRun $mrf) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $gudang = WarehouseScope::resolveFilter($request, $user);

        $filters = [
            'keadaan' => $request->string('keadaan')->toString() ?: 'berjalan',
            'search' => $request->string('search')->toString() ?: null,
            'warehouse_id' => $gudang,
        ];

        // Tiap divisi peminta melihat materialnya sendiri — batas yang sama
        // dengan daftar MRF, dan dari sumber aturan yang sama.
        $dasar = fn () => WarehouseScope::apply(ProductionMaterialHolding::query(), $user)
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when(
                in_array($user?->role?->slug, MaterialRequisition::PERAN_SEDIVISI, true),
                fn ($q) => $q->whereHas('requisition', fn ($m) => $m->untukPembaca($user)),
            )
            ->when($filters['search'], function ($q, $cari) {
                $pola = '%'.str_replace('%', '\%', $cari).'%';

                return $q->where(fn ($w) => $w
                    ->where('batch_no', 'ILIKE', $pola)
                    ->orWhere('production_area', 'ILIKE', $pola)
                    ->orWhereHas('product', fn ($p) => $p
                        ->where('sku', 'ILIKE', $pola)->orWhere('name', 'ILIKE', $pola))
                    ->orWhereHas('requisition', fn ($m) => $m->where('mrf_number', 'ILIKE', $pola)));
            });

        $halaman = $dasar()
            ->when($filters['keadaan'] === 'berjalan', fn ($q) => $q->whereNull('finished_at'))
            ->when($filters['keadaan'] === 'habis', fn ($q) => $q->whereNotNull('finished_at'))
            ->with([
                'product:id,sku,name,uom',
                'warehouse:id,code,name',
                'requisition:id,mrf_number,request_type,purpose,requested_by',
                'requisition.requestedBy:id,full_name',
                // Tiga nama yang sering tiga orang berbeda: yang meminta, yang
                // menerima, dan yang terakhir memindahkan.
                'receivedBy:id,full_name',
                'areaMovedBy:id,full_name',
                'consumptions' => fn ($q) => $q->with('consumedBy:id,full_name')->orderBy('consumed_at'),
            ])
            // Yang masih ada sisanya selalu di atas, lalu yang PALING LAMA
            // diterima. Urutan inilah yang membuat sisa terlupakan muncul
            // sendiri tanpa ada yang harus mencarinya.
            ->orderByRaw('CASE WHEN finished_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('received_at')
            ->paginate(20)
            ->withQueryString();

        return view('wms.produksi.material-index', [
            'halaman' => $halaman,
            'filters' => $filters,
            'gudangOptions' => WarehouseScope::options($user),
            'ambangMenunggak' => self::AMBANG_MENUNGGAK_HARI,
            // Saran untuk isian "pindahkan ke": rak penyimpanan gudangnya
            // sendiri, tanpa rak transit — yang itu titik serah terima, bukan
            // tempat menyimpan. Hanya saran; isiannya tetap bebas karena
            // material produksi kerap berdiri di tempat yang bukan rak.
            'rakPenyimpanan' => Location::query()
                ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
                ->active()->penyimpanan()->orderBy('code')->pluck('code'),
            'stats' => [
                'baris_berjalan' => (clone $dasar())->whereNull('finished_at')->count(),
                'unit_sisa' => (int) (clone $dasar())->whereNull('finished_at')
                    ->selectRaw('COALESCE(SUM(qty_received - qty_consumed), 0) AS jumlah')
                    ->value('jumlah'),
                'menunggak' => (clone $dasar())->menunggak(self::AMBANG_MENUNGGAK_HARI)->count(),
            ],
        ]);
    }

    /**
     * Riwayat pemakaian material — seluruhnya, termasuk yang sudah habis.
     *
     * LAYAR TERSENDIRI, bukan lipatan di dalam kartu material. Daftar material
     * menjawab "apa yang masih ada di tangan Produksi", dan baris yang sudah
     * habis wajar menghilang dari sana. Tetapi pertanyaan yang datang
     * berbulan-bulan kemudian berbentuk lain: "batch ini dulu dipakai siapa,
     * kapan, dan untuk apa" — dan jawabannya tidak boleh ikut hilang bersama
     * material yang sudah selesai.
     *
     * Barisnya TIDAK PERNAH dihapus atau ditimpa: tiap pemakaian sudah dicatat
     * sebagai baris sendiri sejak awal (lihat ProductionMaterialConsumption).
     * Yang belum ada hanyalah tempat membacanya.
     *
     * DATA CONTRACT
     * -------------
     * riwayat() : $halaman LengthAwarePaginator<ProductionMaterialConsumption>,
     *             $filters, $gudangOptions, $stats
     */
    public function riwayat(Request $request): View
    {
        $user = $request->user();
        $gudang = WarehouseScope::resolveFilter($request, $user);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'dari' => FilterTanggal::bersih($request->query('dari')),
            'sampai' => FilterTanggal::bersih($request->query('sampai')),
            'warehouse_id' => $gudang,
        ];

        /*
         | Batas gudang ditegakkan lewat materialnya, bukan lewat baris
         | pemakaian: baris pemakaian tidak punya kolom gudang sendiri, dan
         | menambahkannya berarti dua tempat yang harus sepakat selamanya.
         |
         | BATAS DIVISI menumpang jalur yang sama. Produksi boleh menelusuri
         | pemakaiannya sendiri — justru merekalah yang paling sering mencari
         | "batch kemarin terpakai berapa" — tetapi pemakaian Sales, QC dan R&D
         | bukan urusannya. Logistik dan Manager tidak dibatasi: yang perlu
         | melacak seluruh barang keluar lewat MRF memang mereka.
         */
        $dasar = fn () => ProductionMaterialConsumption::query()
            ->whereHas('holding', fn ($q) => WarehouseScope::apply($q, $user)
                ->when($gudang, fn ($w, $id) => $w->where('warehouse_id', $id))
                ->when(
                    in_array($user?->role?->slug, MaterialRequisition::PERAN_SEDIVISI, true),
                    fn ($w) => $w->whereHas('requisition', fn ($m) => $m->untukPembaca($user)),
                ))
            ->when($filters['search'], function ($q, $cari) {
                $pola = '%'.str_replace('%', '\%', $cari).'%';

                return $q->where(fn ($w) => $w
                    ->where('note', 'ILIKE', $pola)
                    ->orWhereHas('holding', fn ($h) => $h
                        ->where('batch_no', 'ILIKE', $pola)
                        ->orWhere('production_area', 'ILIKE', $pola)
                        ->orWhereHas('product', fn ($p) => $p
                            ->where('sku', 'ILIKE', $pola)->orWhere('name', 'ILIKE', $pola))
                        ->orWhereHas('requisition', fn ($m) => $m->where('mrf_number', 'ILIKE', $pola)))
                    ->orWhereHas('consumedBy', fn ($u) => $u->where('full_name', 'ILIKE', $pola)));
            })
            ->when($filters['dari'], fn ($q, $t) => $q->whereDate('consumed_at', '>=', $t))
            ->when($filters['sampai'], fn ($q, $t) => $q->whereDate('consumed_at', '<=', $t));

        return view('wms.produksi.material-riwayat', [
            'halaman' => $dasar()
                ->with([
                    'consumedBy:id,full_name',
                    'holding:id,material_requisition_id,product_id,warehouse_id,batch_no,production_area,qty_received,qty_consumed,finished_at',
                    'holding.product:id,sku,name,uom',
                    'holding.warehouse:id,code,name',
                    'holding.requisition:id,mrf_number,request_type',
                ])
                // Yang terbaru di atas: layar ini dibuka untuk menelusuri,
                // dan penelusuran hampir selalu berangkat dari yang terakhir.
                ->orderByDesc('consumed_at')
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'filters' => $filters,
            'gudangOptions' => WarehouseScope::options($user),
            'stats' => [
                'baris' => (clone $dasar())->count(),
                'unit' => (int) (clone $dasar())->sum('qty'),
            ],
        ]);
    }

    /**
     * Produksi memindahkan materialnya ke area lain.
     *
     * Area yang tertulis saat serah terima berasal dari titik transit yang
     * dipilih operator — keterangan pembuka, bukan keputusan akhir. Barangnya
     * hampir selalu berpindah lagi ke lantai tempat ia benar-benar dikerjakan,
     * dan tanpa pintu ini satu-satunya cara menyebutkannya adalah menulis di
     * kolom keterangan pemakaian, yang baru terbaca setelah barangnya habis.
     *
     * Hanya AREA yang berubah. Jumlah, batch, dan asal MRF-nya tidak disentuh:
     * memindahkan barang bukan mengubah apa yang diterima.
     */
    public function move(Request $request, ProductionMaterialHolding $holding): RedirectResponse
    {
        WarehouseScope::assert($holding->warehouse_id, $request->user());

        $data = $request->validate([
            'production_area' => ['required', 'string', 'max:100'],
        ], [
            'production_area.required' => 'Isi dulu area produksi tempat material ini sekarang berada.',
        ], ['production_area' => 'area produksi']);

        if ($holding->sudahHabis()) {
            return back()->with('error', sprintf(
                'Material %s batch %s sudah habis terpakai, jadi tidak ada yang bisa dipindahkan.',
                $holding->product?->sku ?? 'ini',
                $holding->batch_no ?? '—',
            ));
        }

        $sebelum = (string) $holding->production_area;
        $sesudah = trim($data['production_area']);

        if ($sebelum === $sesudah) {
            return back()->with('error', 'Area produksinya sama dengan yang sekarang — tidak ada yang dipindahkan.');
        }

        // Siapa yang memindahkan ikut dicatat. Produksi bukan satu orang, dan
        // pertanyaan yang muncul berbulan-bulan kemudian selalu berbentuk
        // "siapa yang memegang ini terakhir".
        $holding->forceFill([
            'production_area' => $sesudah,
            'area_moved_at' => now(),
            'area_moved_by' => $request->user()?->id,
        ])->save();

        Activity::record(
            ActivityLog::MRF_MOVE,
            sprintf(
                'Produksi memindahkan %d unit %s batch %s dari %s ke %s.',
                $holding->qty_sisa,
                $holding->product?->sku ?? 'produk',
                $holding->batch_no ?? '—',
                $sebelum === '' ? '—' : $sebelum,
                $sesudah,
            ),
            $holding,
            $holding->warehouse_id,
            [
                'mrf' => $holding->requisition?->mrf_number,
                'batch' => $holding->batch_no,
                'dari' => $sebelum,
                'ke' => $sesudah,
                'sisa' => $holding->qty_sisa,
            ],
        );

        return back()->with('success', sprintf(
            'Sisa %s batch %s sekarang tercatat di %s.',
            $holding->product?->sku ?? 'material',
            $holding->batch_no ?? '—',
            $sesudah,
        ));
    }

    /** Produksi mencatat pemakaian — sebagian, atau seluruh sisanya. */
    public function consume(Request $request, ProductionMaterialHolding $holding): RedirectResponse
    {
        WarehouseScope::assert($holding->warehouse_id, $request->user());

        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'qty.required' => 'Isi berapa yang dipakai. Kalau seluruh sisanya, tekan tombol "Pakai Semua Sisa".',
        ], ['qty' => 'qty pemakaian', 'note' => 'keterangan']);

        try {
            $this->mrf->pakai(
                $holding,
                (int) $data['qty'],
                $data['note'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $holding->refresh();

        Activity::record(
            ActivityLog::MRF_CONSUME,
            sprintf(
                'Produksi memakai %d unit %s batch %s dari %s. Sisa sekarang %d.',
                (int) $data['qty'],
                $holding->product?->sku ?? 'produk',
                $holding->batch_no ?? '—',
                $holding->requisition?->mrf_number ?? '—',
                $holding->qty_sisa,
            ),
            $holding,
            $holding->warehouse_id,
            [
                'mrf' => $holding->requisition?->mrf_number,
                'batch' => $holding->batch_no,
                'dipakai' => (int) $data['qty'],
                'sisa' => $holding->qty_sisa,
                'keterangan' => $data['note'] ?? null,
            ],
        );

        if ($holding->sudahHabis()) {
            return back()->with('success', sprintf(
                'Pemakaian %d unit dicatat. Material %s batch %s kini HABIS — seluruh %d unit yang diterima sudah terpakai.',
                (int) $data['qty'],
                $holding->product?->sku ?? 'ini',
                $holding->batch_no ?? '—',
                $holding->qty_received,
            ));
        }

        return back()->with('success', sprintf(
            'Pemakaian %d unit dicatat. Sisa %s batch %s sekarang %d unit di %s.',
            (int) $data['qty'],
            $holding->product?->sku ?? 'ini',
            $holding->batch_no ?? '—',
            $holding->qty_sisa,
            $holding->production_area,
        ));
    }
}
