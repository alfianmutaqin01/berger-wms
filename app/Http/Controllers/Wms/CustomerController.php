<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreCustomerRequest;
use App\Http\Requests\Wms\UpdateCustomerRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerBilling;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Pelanggan — PRD §6.2 F-MASTER-06.
 *
 * DATA CONTRACT (view: wms.master.customers)
 * ------------------------------------------
 * $customers   : LengthAwarePaginator<Customer>
 * $territories : Collection<string> — Territory Code yang sudah terpakai
 * $stats       : array{total:int, active:int, inactive:int, no_ship_to:int}
 * $filters     : array{search:?string, territory:?string, status:?string}
 *
 * CATATAN: tidak ada antrean persetujuan. Sejak PRD v1.1, Sales tidak lagi
 * mengajukan pelanggan — Manager/Super Admin mendaftarkan langsung dan
 * pelanggan langsung aktif.
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'territory' => $request->query('territory'),
            'status' => $request->query('status'),
        ];

        $customers = Customer::query()
            ->search($filters['search'])
            ->when($filters['territory'], fn ($q, $t) => $q->where('territory_code', $t))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('wms.master.customers', [
            'customers' => $customers,
            // F-BILL-03: satu query untuk seluruh halaman, bukan satu per baris.
            'piutang' => CustomerBilling::penandaCustomer($customers->pluck('id')->all()),
            'territories' => Customer::query()
                ->whereNotNull('territory_code')
                ->distinct()
                ->orderBy('territory_code')
                ->pluck('territory_code'),
            'stats' => [
                'total' => Customer::count(),
                'active' => Customer::where('is_active', true)->count(),
                'inactive' => Customer::where('is_active', false)->count(),
                'no_ship_to' => Customer::whereNull('ship_to_code')->count(),
            ],
            'filters' => $filters,
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $data = $request->customerData();
        $data['created_by'] = $request->user()?->id;

        $customer = Customer::create($data);

        Activity::record(
            ActivityLog::MASTER_CREATE,
            sprintf('Menambah pelanggan %s — %s.', $customer->code, $customer->name),
            $customer,
            // Pelanggan tidak dimiliki satu gudang; wilayahnya diatur
            // terpisah lewat warehouse_territories.
            null,
            ['kode' => $customer->code],
        );

        return redirect()->route('wms.customers.index')
            ->with('success', "Pelanggan {$customer->name} berhasil ditambahkan.");
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->customerData());

        Activity::record(
            ActivityLog::MASTER_UPDATE,
            sprintf('Mengubah pelanggan %s — %s.', $customer->code, $customer->name),
            $customer,
            // Pelanggan tidak dimiliki satu gudang; wilayahnya diatur
            // terpisah lewat warehouse_territories.
            null,
            ['kode' => $customer->code, 'kolom_berubah' => array_keys($customer->getChanges())],
        );

        return redirect()->route('wms.customers.index')
            ->with('success', "Pelanggan {$customer->name} berhasil diperbarui.");
    }

    /**
     * Menonaktifkan/mengaktifkan pelanggan.
     *
     * Memakai flag `is_active`, BUKAN penghapusan: pelanggan yang pernah
     * bertransaksi masih direferensikan pesanan, surat jalan, dan tagihan lama.
     * Pelanggan non-aktif tidak lagi muncul di form Buat Pesanan Sales.
     */
    public function toggleStatus(Customer $customer): RedirectResponse
    {
        $customer->update(['is_active' => ! $customer->is_active]);

        Activity::record(
            ActivityLog::MASTER_DEACTIVATE,
            sprintf(
                'Pelanggan %s (%s) %s.',
                $customer->name,
                $customer->code,
                $customer->is_active ? 'diaktifkan' : 'dinonaktifkan',
            ),
            $customer,
            null,
            ['kode' => $customer->code, 'aktif' => $customer->is_active],
        );

        return back()->with('success', sprintf(
            'Pelanggan %s berhasil %s.',
            $customer->name,
            $customer->is_active ? 'diaktifkan' : 'dinonaktifkan'
        ));
    }
}
