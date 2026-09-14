<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ConfirmBillingPaymentRequest;
use App\Models\ActivityLog;
use App\Models\BillingPayment;
use App\Models\CustomerBilling;
use App\Models\SalesOrderDetail;
use App\Support\Activity;
use App\Support\Billing\Piutang;
use App\Support\WarehouseScope;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Billing — buku pantau piutang pesanan tempo (Fase 8, PRD §6.6).
 *
 * BUKAN PEMBUKUAN. Tidak ada nominal; pembayarannya sendiri tidak pernah
 * melewati sistem ini. Yang dijawab layar ini hanya: invoice mana yang belum
 * dibayar, kapan jatuh temponya, dan sudah dikonfirmasi lunas atau belum.
 *
 * TAB PERTAMA ADALAH PEKERJAAN MINGGU INI, bukan seluruh daftar. Daftar
 * piutang yang panjang dibaca dari atas dan berhenti di baris ke-20; tagihan
 * yang jatuh tempo lusa tidak boleh tenggelam di bawah tagihan bulan depan.
 */
class BillingController extends Controller
{
    public const TAB_SEGERA = 'segera';

    public const TAB_LEWAT = 'lewat';

    public const TAB_BERJALAN = 'berjalan';

    public const TAB_LUNAS = 'lunas';

    public const TABS = [self::TAB_SEGERA, self::TAB_LEWAT, self::TAB_BERJALAN, self::TAB_LUNAS];

    public function __construct(private readonly Piutang $piutang) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : self::TAB_SEGERA;
        $cari = trim((string) $request->query('search'));

        $dasar = fn () => WarehouseScope::apply(CustomerBilling::query(), $user)
            ->when($cari !== '', fn ($q) => $this->cari($q, $cari));

        $tagihan = $this->untukTab($dasar(), $tab)
            ->with([
                'customer:id,code,name',
                'warehouse:id,code,name',
                'paymentTerm:id,name,days',
                'salesOrder:id,order_number,bc_so_number,customer_po_number,user_id',
                'salesOrder.user:id,full_name',
                'mergedOrders:id,order_number,so_merged_into_id',
                'payment.confirmedBy:id,full_name',
            ])
            ->paginate(20)
            ->withQueryString();

        return view('wms.billing.index', [
            'tagihan' => $tagihan,
            'qty' => $this->qtyTerkirim($tagihan->getCollection()),
            'tab' => $tab,
            'search' => $cari,
            'jumlah' => collect(self::TABS)->mapWithKeys(
                fn ($t) => [$t => $this->untukTab($dasar(), $t)->count()]
            )->all(),
            'ringkasan' => $this->ringkasan($user),
            'hariIni' => CustomerBilling::hariIni(),
        ]);
    }

    /** F-BILL-02: satu konfirmasi untuk satu atau beberapa tagihan satu customer. */
    public function pay(ConfirmBillingPaymentRequest $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $pembayaran = $this->piutang->lunasi(
                $request->validated('billing_ids'),
                $request->only(['paid_on', 'method', 'reference', 'notes']),
                $user->id,
                fn (CustomerBilling $t) => WarehouseScope::allows($t->warehouse_id, $user),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $nomor = $pembayaran->billings->map(fn ($t) => $this->piutang->nomor($t))->join(', ');

        Activity::record(
            ActivityLog::BILLING_PAY,
            sprintf(
                'Mengonfirmasi lunas %d tagihan %s (%s) — %s %s, bukti diterima %s.',
                $pembayaran->billings->count(),
                $pembayaran->customer?->name ?? 'customer',
                $nomor,
                $pembayaran->method_label,
                $pembayaran->reference ?? '',
                $pembayaran->paid_on->format('d/m/Y'),
            ),
            $pembayaran,
            $pembayaran->billings->first()?->warehouse_id,
            [
                'tagihan' => $pembayaran->billings->pluck('id')->all(),
                'metode' => $pembayaran->method,
                'referensi' => $pembayaran->reference,
            ],
        );

        return redirect()
            ->route('wms.billing.index', ['tab' => self::TAB_LUNAS])
            ->with('success', sprintf('%d tagihan dinyatakan lunas: %s.', $pembayaran->billings->count(), $nomor));
    }

    /** Membatalkan konfirmasi lunas yang keliru — Manager, dengan alasan. */
    public function void(Request $request, BillingPayment $payment): RedirectResponse
    {
        $user = $request->user();

        $payment->load('billings:id,billing_payment_id,warehouse_id,sales_order_id', 'billings.salesOrder:id,order_number,bc_so_number');

        foreach ($payment->billings as $t) {
            WarehouseScope::assert($t->warehouse_id, $user);
        }

        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:10', 'max:1000']],
            [],
            ['reason' => 'alasan pembatalan'],
        );

        $nomor = $payment->billings->map(fn ($t) => $this->piutang->nomor($t))->join(', ');

        try {
            $jumlah = $this->piutang->batalkan($payment, $data['reason'], $user->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::BILLING_VOID,
            sprintf('Membatalkan konfirmasi lunas %s — %s', $nomor, $data['reason']),
            $payment,
            $payment->billings->first()?->warehouse_id,
            ['tagihan' => $payment->billings->pluck('id')->all(), 'alasan' => $data['reason']],
        );

        return redirect()
            ->route('wms.billing.index', ['tab' => self::TAB_BERJALAN])
            ->with('warning', sprintf('Konfirmasi lunas dibatalkan. %d tagihan kembali belum lunas: %s.', $jumlah, $nomor));
    }

    /* --------------------------------------------------------------- Dalam */

    private function untukTab(BuilderContract $query, string $tab): BuilderContract
    {
        return match ($tab) {
            self::TAB_SEGERA => $query->jatuhTempoDalam(CustomerBilling::HARI_SEGERA)->orderBy('due_date')->orderBy('id'),
            self::TAB_LEWAT => $query->lewatJatuhTempo()->orderBy('due_date')->orderBy('id'),
            self::TAB_BERJALAN => $query->belumLunas()->orderBy('due_date')->orderBy('id'),
            self::TAB_LUNAS => $query->lunas()->orderByDesc('updated_at')->orderByDesc('id'),
        };
    }

    private function cari(BuilderContract $query, string $cari): BuilderContract
    {
        $pola = '%'.$cari.'%';

        return $query->where(fn ($q) => $q
            ->whereHas('customer', fn ($c) => $c->where('name', 'ILIKE', $pola)->orWhere('code', 'ILIKE', $pola))
            ->orWhereHas('salesOrder', fn ($o) => $o->where('bc_so_number', 'ILIKE', $pola)
                ->orWhere('order_number', 'ILIKE', $pola)
                ->orWhere('customer_po_number', 'ILIKE', $pola)));
    }

    /**
     * Qty terkirim per tagihan (induk + anak gabungan), satu query untuk satu halaman.
     *
     * @return array<int, int> id tagihan => qty
     */
    private function qtyTerkirim($tagihan): array
    {
        if ($tagihan->isEmpty()) {
            return [];
        }

        $indukKe = [];

        foreach ($tagihan as $t) {
            $indukKe[$t->sales_order_id] = $t->id;

            foreach ($t->mergedOrders as $anak) {
                $indukKe[$anak->id] = $t->id;
            }
        }

        $hasil = array_fill_keys($tagihan->pluck('id')->all(), 0);

        SalesOrderDetail::query()
            ->whereIn('sales_order_id', array_keys($indukKe))
            ->selectRaw('sales_order_id, SUM(qty_shipped) AS qty')
            ->groupBy('sales_order_id')
            ->get()
            ->each(function ($baris) use (&$hasil, $indukKe) {
                $hasil[$indukKe[$baris->sales_order_id]] += (int) $baris->qty;
            });

        return $hasil;
    }

    /** Tiga kartu di atas daftar. */
    private function ringkasan($user): array
    {
        $dasar = fn () => WarehouseScope::apply(CustomerBilling::query(), $user);
        $awalBulan = CustomerBilling::hariIni()->startOfMonth()->toDateString();

        return [
            'lewat' => [
                'tagihan' => $dasar()->lewatJatuhTempo()->count(),
                'customer' => $dasar()->lewatJatuhTempo()->distinct()->count('customer_id'),
            ],
            'berjalan' => [
                'tagihan' => $dasar()->belumLunas()->count(),
                'customer' => $dasar()->belumLunas()->distinct()->count('customer_id'),
            ],
            'lunas_bulan_ini' => [
                'tagihan' => $dasar()->lunas()
                    ->whereHas('payment', fn ($p) => $p->whereDate('paid_on', '>=', $awalBulan))
                    ->count(),
            ],
        ];
    }
}
