<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SalesReturn;
use App\Models\SalesReturnDetail;
use App\Support\Activity;
use App\Support\Permission;
use App\Support\Returns\CustomerRejection;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

/**
 * PENOLAKAN CUSTOMER di Portal WMS — antrean gudang atas barang yang balik.
 *
 * SATU HALAMAN, TIGA PEKERJAAN. Logistik menyetujui klaim lalu memverifikasi
 * barangnya; Operator menaikkan ke rak di antara keduanya. Ketiganya melihat
 * daftar yang sama supaya tidak ada yang perlu ditelepon untuk tahu ada
 * barang menunggu — yang dipisah adalah tombolnya, bukan daftarnya.
 *
 * DATA CONTRACT
 *   index() : $baris LengthAwarePaginator<SalesReturn>, $warehouses,
 *             $filters{search,status,warehouse}, $stats{persetujuan,putaway,verifikasi}
 *   show()  : $retur SalesReturn (details.product, details.location, salesOrder,
 *             customer), $bolehSetujui, $bolehNaikkan, $bolehVerifikasi
 */
class CustomerRejectionController extends Controller
{
    public function __construct(private readonly CustomerRejection $penolakan) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'warehouse' => WarehouseScope::resolveFilter($request, $user, 'warehouse'),
        ];

        $baris = SalesReturn::query()
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['warehouse'], fn ($q, $w) => $q->where('warehouse_id', $w))
            ->tap(fn ($q) => WarehouseScope::apply($q, $user))
            ->with([
                'salesOrder:id,order_number,bc_so_number',
                'customer:id,code,name',
                'warehouse:id,code,name',
                'reportedBy:id,full_name',
            ])
            ->withCount('details')
            // Yang paling menuntut tindakan di atas, bukan yang paling baru:
            // laporan yang menunggu persetujuan menahan barang di truk, dan
            // itu lebih mendesak daripada dokumen yang sudah selesai.
            ->orderByRaw("
                CASE status
                    WHEN 'reported' THEN 0
                    WHEN 'putaway_pending' THEN 1
                    WHEN 'verification_pending' THEN 2
                    WHEN 'partial_verified' THEN 3
                    ELSE 4
                END
            ")
            ->latest('reported_at')
            ->paginate(20)
            ->withQueryString();

        return view('wms.inbound.returns', [
            'baris' => $baris,
            'warehouses' => WarehouseScope::options($user),
            'filters' => $filters,
            'statuses' => SalesReturn::STATUS_LABELS,
            'stats' => $this->angka($request),
        ]);
    }

    public function show(Request $request, SalesReturn $retur): View
    {
        WarehouseScope::assert($retur->warehouse_id, $request->user());

        $retur->load([
            'details.product:id,sku,name,uom',
            'details.location:id,code',
            'details.ddpLocation:id,code',
            'details.putawayBy:id,full_name',
            'salesOrder:id,order_number,bc_so_number,customer_po_number',
            'customer:id,code,name',
            'warehouse:id,code,name',
            'reportedBy:id,full_name',
            'approvedBy:id,full_name',
            'verifiedBy:id,full_name',
        ]);

        return view('wms.inbound.return-detail', [
            'retur' => $retur,
            'bolehSetujui' => Gate::allows(Permission::RETURN_APPROVE)
                && $retur->status === SalesReturn::STATUS_REPORTED,
            'bolehNaikkan' => Gate::allows(Permission::RETURN_PUTAWAY)
                && in_array($retur->status, [
                    SalesReturn::STATUS_PUTAWAY_PENDING,
                    SalesReturn::STATUS_VERIFICATION_PENDING,
                    SalesReturn::STATUS_PARTIAL_VERIFIED,
                ], true),
            'bolehVerifikasi' => Gate::allows(Permission::RETURN_APPROVE)
                && in_array($retur->status, [
                    SalesReturn::STATUS_VERIFICATION_PENDING,
                    SalesReturn::STATUS_PARTIAL_VERIFIED,
                ], true),
        ]);
    }

    /** Logistik menyetujui klaimnya — menilai kertasnya, bukan barangnya. */
    public function approve(Request $request, SalesReturn $retur): RedirectResponse
    {
        WarehouseScope::assert($retur->warehouse_id, $request->user());

        $data = $request->validate([
            'qty' => ['required', 'array'],
            'qty.*' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['qty' => 'jumlah disetujui', 'note' => 'catatan']);

        try {
            $this->penolakan->approve(
                $retur,
                array_map('intval', $data['qty']),
                $data['note'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $retur->refresh();

        Activity::record(
            ActivityLog::RETURN_APPROVE,
            sprintf(
                'Menyetujui laporan penolakan %s untuk %s (%s).',
                $retur->reference,
                $retur->salesOrder?->order_number ?? '—',
                $retur->status_label,
            ),
            $retur,
            $retur->warehouse_id,
            ['laporan' => $retur->reference, 'status' => $retur->status],
        );

        if ($retur->status === SalesReturn::STATUS_REJECTED) {
            return back()->with('warning',
                'Seluruh baris disetujui nol, jadi laporan ini dicatat sebagai DITOLAK. '.
                'Tidak ada barang yang masuk antrean put-away.'
            );
        }

        return back()->with('success',
            'Laporan disetujui. Barangnya sekarang masuk antrean put-away Operator, '.
            'dan stok baru bertambah setelah Anda memverifikasinya di rak.'
        );
    }

    public function reject(Request $request, SalesReturn $retur): RedirectResponse
    {
        WarehouseScope::assert($retur->warehouse_id, $request->user());

        $data = $request->validate([
            // Alasan WAJIB. Sales yang laporannya ditolak berhak tahu kenapa,
            // dan tanpa itu satu-satunya jalan bertanya adalah menelepon.
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['reason' => 'alasan']);

        try {
            $this->penolakan->reject($retur, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::RETURN_REJECT,
            sprintf('Menolak laporan penolakan %s: %s', $retur->reference, $data['reason']),
            $retur,
            $retur->warehouse_id,
            ['laporan' => $retur->reference, 'alasan' => $data['reason']],
        );

        return back()->with('success', 'Laporan ditolak. Sales akan melihat alasannya di detail pesanan.');
    }

    /** Operator menaikkan satu baris ke rak. Stok belum bergerak di sini. */
    public function putaway(Request $request, SalesReturnDetail $detail): RedirectResponse
    {
        $retur = $detail->salesReturn;

        WarehouseScope::assert($retur->warehouse_id, $request->user());

        $data = $request->validate([
            'qty_good' => ['required', 'integer', 'min:0'],
            'qty_ddp' => ['required', 'integer', 'min:0'],
            'location_good' => ['nullable', 'string', 'max:20'],
            'location_ddp' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'qty_good' => 'jumlah barang bagus',
            'qty_ddp' => 'jumlah barang DDP',
            'location_good' => 'rak barang bagus',
            'location_ddp' => 'rak barang DDP',
        ]);

        try {
            $this->penolakan->putaway(
                $detail,
                (int) $data['qty_good'],
                (int) $data['qty_ddp'],
                $data['location_good'] ?? null,
                $data['location_ddp'] ?? null,
                $data['note'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::RETURN_PUTAWAY,
            sprintf(
                'Menaikkan barang tolakan %s ke rak: %d bagus, %d DDP.',
                $retur->reference,
                (int) $data['qty_good'],
                (int) $data['qty_ddp'],
            ),
            $detail,
            $retur->warehouse_id,
            [
                'laporan' => $retur->reference,
                'bagus' => (int) $data['qty_good'],
                'ddp' => (int) $data['qty_ddp'],
            ],
        );

        return back()->with('success',
            'Tercatat. Stok belum bertambah — Logistik memverifikasi dulu barangnya di rak.'
        );
    }

    /** Logistik memverifikasi barangnya. DI SINILAH stok bertambah. */
    public function verify(Request $request, SalesReturn $retur): RedirectResponse
    {
        WarehouseScope::assert($retur->warehouse_id, $request->user());

        $data = $request->validate([
            'baris' => ['required', 'array', 'min:1'],
            'baris.*' => ['required', 'integer'],
        ], [], ['baris' => 'baris yang diverifikasi']);

        try {
            $jumlah = $this->penolakan->verify(
                $retur,
                array_map('intval', $data['baris']),
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $retur->refresh();

        Activity::record(
            ActivityLog::RETURN_VERIFY,
            sprintf(
                'Memverifikasi %d baris pada %s — stok barang tolakan resmi masuk.',
                $jumlah,
                $retur->reference,
            ),
            $retur,
            $retur->warehouse_id,
            ['laporan' => $retur->reference, 'baris' => $jumlah, 'status' => $retur->status],
        );

        return back()->with('success', sprintf(
            '%d baris diverifikasi. Stoknya sudah resmi masuk%s',
            $jumlah,
            $retur->status === SalesReturn::STATUS_VERIFIED
                ? ' dan laporan ini selesai.'
                : ', tapi masih ada baris yang belum diverifikasi.',
        ));
    }

    /* ------------------------------------------------------------- Dalam */

    /** @return array{persetujuan:int, putaway:int, verifikasi:int} */
    private function angka(Request $request): array
    {
        $q = fn () => WarehouseScope::apply(SalesReturn::query(), $request->user());

        return [
            'persetujuan' => $q()->menungguPersetujuan()->count(),
            'putaway' => $q()->menungguPutaway()->count(),
            'verifikasi' => $q()->menungguVerifikasi()->count(),
        ];
    }
}
