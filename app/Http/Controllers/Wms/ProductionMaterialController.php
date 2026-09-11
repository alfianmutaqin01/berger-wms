<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ProductionMaterialHolding;
use App\Support\Activity;
use App\Support\Production\MaterialRequisitionRun;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * "Material di Tangan Produksi" — buku besar milik Produksi.
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

        $dasar = fn () => WarehouseScope::apply(ProductionMaterialHolding::query(), $user)
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
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
            'stats' => [
                'baris_berjalan' => (clone $dasar())->whereNull('finished_at')->count(),
                'unit_sisa' => (int) (clone $dasar())->whereNull('finished_at')
                    ->selectRaw('COALESCE(SUM(qty_received - qty_consumed), 0) AS jumlah')
                    ->value('jumlah'),
                'menunggak' => (clone $dasar())->menunggak(self::AMBANG_MENUNGGAK_HARI)->count(),
            ],
        ]);
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
