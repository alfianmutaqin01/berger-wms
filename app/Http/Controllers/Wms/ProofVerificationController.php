<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\RejectDeliveryProofRequest;
use App\Models\DeliveryProof;
use App\Models\SalesOrder;
use App\Support\Outbound\ProofOfDelivery;
use App\Support\WarehouseScope;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Verifikasi bukti Surat Jalan — PRD §6.5 F-OUT-06, Fase 6 tahap 5.
 *
 * TIGA TAB, SATU STATUS. Pemilik produk memilih tidak menambah status baru
 * antara "sampai tujuan" dan "menunggu verifikasi bukti", supaya tampilan di
 * HP Sales tetap satu label. Konsekuensinya di sisi Logistik: antrean tidak
 * boleh disaring dengan status, sebab pesanan yang fotonya belum ada dan
 * pesanan yang fotonya sudah menunggu berstatus SAMA.
 *
 * Yang membedakan keduanya adalah ADA-TIDAKNYA foto yang menunggu diperiksa.
 * Itulah dasar pembagian tab di sini — bukan kolom status.
 *
 * DATA CONTRACT
 * -------------
 * index() : $orders LengthAwarePaginator<SalesOrder>, $tab, $filters,
 *           $jumlah{perlu_diperiksa,menunggu_bukti,riwayat}
 * show()  : $order, $bukti Collection<DeliveryProof>, $adaMenunggu bool
 */
class ProofVerificationController extends Controller
{
    /** Menunggu foto dari Sales (belum ada, atau yang lama ditolak). */
    public const TAB_MENUNGGU_BUKTI = 'menunggu-bukti';

    /** Foto sudah ada, giliran Logistik memeriksanya. */
    public const TAB_PERLU_DIPERIKSA = 'perlu-diperiksa';

    public const TAB_RIWAYAT = 'riwayat';

    public function __construct(private readonly ProofOfDelivery $bukti) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $tab = in_array($request->query('tab'), [
            self::TAB_PERLU_DIPERIKSA, self::TAB_MENUNGGU_BUKTI, self::TAB_RIWAYAT,
        ], true) ? $request->query('tab') : self::TAB_PERLU_DIPERIKSA;

        $dasar = fn () => WarehouseScope::apply(SalesOrder::query(), $user)
            ->whereNull('cancelled_at');

        $orders = $this->untukTab($dasar(), $tab)
            ->with([
                'customer:id,code,name', 'warehouse:id,code,name',
                'paymentTerm:id,code,name,days',
                'deliveryNotes:id,sales_order_id,document_no,delivered_at',
            ])
            ->withCount([
                'proofs as bukti_menunggu' => fn ($q) => $q->menunggu(),
                'proofs as bukti_ditolak' => fn ($q) => $q->where('status', DeliveryProof::STATUS_REJECTED),
            ])
            ->search($request->query('search'))
            ->latest('delivered_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('wms.outbound.verification', [
            'orders' => $orders,
            'tab' => $tab,
            'filters' => ['search' => $request->query('search')],
            'jumlah' => [
                self::TAB_PERLU_DIPERIKSA => $this->untukTab($dasar(), self::TAB_PERLU_DIPERIKSA)->count(),
                self::TAB_MENUNGGU_BUKTI => $this->untukTab($dasar(), self::TAB_MENUNGGU_BUKTI)->count(),
                self::TAB_RIWAYAT => $this->untukTab($dasar(), self::TAB_RIWAYAT)->count(),
            ],
        ]);
    }

    public function show(Request $request, SalesOrder $order): View
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $order->load([
            'customer:id,code,name', 'warehouse:id,code,name', 'paymentTerm',
            'details.product:id,sku,name,uom',
            'deliveryNotes:id,sales_order_id,document_no,driver_name,vehicle_plate,shipped_at,delivered_at,received_by_name',
            'completedBy:id,full_name',
            'proofs.uploadedBy:id,full_name', 'proofs.verifiedBy:id,full_name',
        ]);

        return view('wms.outbound.verification-detail', [
            'order' => $order,
            'bukti' => $order->proofs->sortByDesc('uploaded_at'),
            'adaMenunggu' => $order->proofs->contains('status', DeliveryProof::STATUS_PENDING),
        ]);
    }

    /** F-OUT-06 #4: bukti sah, pesanan selesai. */
    public function complete(Request $request, SalesOrder $order): RedirectResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        try {
            $status = $this->bukti->complete($order, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.verification.index')
            ->with('success', sprintf(
                'Pesanan %s dinyatakan selesai (%s).',
                $order->order_number,
                SalesOrder::STATUS_LABELS[$status] ?? $status,
            ));
    }

    /** Bukti tidak sesuai: dikembalikan ke Sales beserta alasannya. */
    public function reject(RejectDeliveryProofRequest $request, SalesOrder $order): RedirectResponse
    {
        try {
            $jumlah = $this->bukti->reject($order, $request->validated('reason'), $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('warning', sprintf(
            '%d foto ditolak. Sales akan melihat alasannya dan bisa mengunggah ulang.',
            $jumlah,
        ));
    }

    /** Pratinjau foto di layar. Berkasnya privat, jadi harus lewat sini. */
    public function preview(Request $request, DeliveryProof $proof)
    {
        WarehouseScope::assert($proof->salesOrder?->warehouse_id, $request->user());

        return $this->bukti->response($proof);
    }

    /** F-OUT-06 #3: Logistik boleh mengunduh foto untuk arsip. */
    public function download(Request $request, DeliveryProof $proof)
    {
        WarehouseScope::assert($proof->salesOrder?->warehouse_id, $request->user());

        return $this->bukti->download($proof);
    }

    /* ------------------------------------------------------------- Dalam */

    private function untukTab(BuilderContract $query, string $tab): BuilderContract
    {
        return match ($tab) {
            self::TAB_PERLU_DIPERIKSA => $query
                ->where('status', SalesOrder::STATUS_PROOF_UPLOADED)
                ->whereHas('proofs', fn ($q) => $q->menunggu()),

            self::TAB_MENUNGGU_BUKTI => $query
                ->whereIn('status', [SalesOrder::STATUS_SHIPPING, SalesOrder::STATUS_PROOF_UPLOADED])
                ->whereDoesntHave('proofs', fn ($q) => $q->menunggu()),

            default => $query->whereIn('status', [
                SalesOrder::STATUS_COMPLETED,
                SalesOrder::STATUS_COMPLETED_BILLING,
            ]),
        };
    }
}
