<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBooking;
use App\Models\Warehouse;
use App\Support\Outbound\FifoAllocator;
use App\Support\Outbound\ProductBooking;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Booking produk — menahan jatah untuk satu customer sebelum pesanannya
 * resmi masuk (keputusan pemilik produk).
 *
 * KEJADIAN YANG MELAHIRKANNYA
 * ---------------------------
 * Customer meminta jatah jauh sebelum barangnya diproduksi. Barangnya belum
 * ada, jadi tidak ada apa pun di sistem yang memegang janji itu; begitu
 * produksi selesai dan stoknya naik rak, barang mendarat dalam keadaan bebas
 * dan pesanan lain menyambarnya lewat FIFO. Pengiriman ke customer itu baru
 * dua sampai tiga minggu sekali, jadi kehilangannya baru ketahuan lama
 * sesudahnya.
 *
 * YANG MEMBUAT LAYAR INI CUKUP SATU HALAMAN
 * -----------------------------------------
 * Booking tidak punya mesin sendiri. Ia memindahkan qty ke `qty_allocated`,
 * jalan yang sama dengan alokasi pesanan, sehingga stok yang dibooking hilang
 * dengan sendirinya dari angka yang boleh dijanjikan — tanpa satu pun layar
 * ketersediaan perlu diubah. Halaman ini hanya pintu masuk dan jendela
 * pengawasnya.
 *
 * DATA CONTRACT
 * -------------
 * index() : $bookings LengthAwarePaginator<StockBooking>, $warehouses,
 *           $warehouse, $customers, $products,
 *           $filters{search,status,warehouse}, $stats{berlaku,menunggu,tertahan}
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly ProductBooking $booking,
        private readonly FifoAllocator $allocator,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $pilihan = WarehouseScope::options($user);
        $gudangId = WarehouseScope::resolveFilter($request, $user, 'warehouse');
        $gudang = $gudangId ? $pilihan->firstWhere('id', $gudangId) : $pilihan->first();

        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'warehouse' => $gudangId,
        ];

        $bookings = WarehouseScope::apply(StockBooking::query(), $user)
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'], fn ($q, $cari) => $q->where(function ($w) use ($cari) {
                $pola = '%'.$cari.'%';

                $w->where('reference', 'ILIKE', $pola)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ILIKE', $pola)
                        ->orWhere('code', 'ILIKE', $pola))
                    ->orWhereHas('product', fn ($p) => $p->where('sku', 'ILIKE', $pola)
                        ->orWhere('name', 'ILIKE', $pola));
            }))
            ->with([
                'customer:id,code,name', 'product:id,sku,name,uom',
                'warehouse:id,code,name', 'createdBy:id,full_name',
                'allocations.stock:id,batch_no,location_id',
                'allocations.stock.location:id,code',
            ])
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        $berlaku = fn () => WarehouseScope::apply(StockBooking::berlaku(), $user);

        return view('wms.outbound.booking', [
            'bookings' => $bookings,
            'warehouses' => $pilihan,
            'warehouse' => $gudang,
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'products' => Product::where('is_active', true)->orderBy('sku')->get(['id', 'sku', 'name', 'uom']),
            'filters' => $filters,
            'statuses' => StockBooking::STATUS_LABELS,
            'stats' => [
                'berlaku' => $berlaku()->count(),
                // Dua angka yang BERBEDA dan sengaja dipisah: yang sudah
                // benar-benar dipegang dari rak, dan yang baru dijanjikan
                // tetapi barangnya belum ada. Meleburnya membuat "sudah aman"
                // dan "belum tentu ada" terbaca sama.
                'tertahan' => (int) $berlaku()->withSum('allocations', 'qty')->get()->sum('allocations_sum_qty'),
                'menunggu' => $berlaku()->with('allocations')->get()->sum(fn (StockBooking $b) => $b->qty_waiting),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'needed_by' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'customer_id' => 'customer',
            'product_id' => 'produk',
            'needed_by' => 'tanggal dibutuhkan',
        ]);

        WarehouseScope::assert((int) $data['warehouse_id'], $request->user());

        try {
            $booking = $this->booking->create(
                Warehouse::findOrFail($data['warehouse_id']),
                Customer::findOrFail($data['customer_id']),
                Product::findOrFail($data['product_id']),
                (int) $data['qty'],
                $data['needed_by'] ?? null,
                $data['note'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $tertahan = $booking->qty_reserved;
        $menunggu = $booking->qty_waiting;

        $pesan = sprintf(
            'Booking %s dibuat untuk %s: %d %s.',
            $booking->reference,
            $booking->customer?->name ?? '—',
            $booking->qty_booked,
            $booking->product?->uom ?? 'unit',
        );

        // Dua keadaan yang BERBEDA dan harus dikatakan apa adanya. Booking
        // yang belum memegang apa pun bukan kegagalan — ia memang menunggu
        // produksi — tetapi menyebutnya "berhasil ditahan" akan membuat orang
        // mengira barangnya sudah aman.
        if ($menunggu > 0) {
            return redirect()->route('wms.booking.index')->with('warning', $pesan.sprintf(
                ' %d unit langsung ditahan dari stok, %d unit MENUNGGU produksi — jatahnya akan diambilkan '.
                'otomatis begitu barang baru diverifikasi.',
                $tertahan,
                $menunggu,
            ));
        }

        return redirect()->route('wms.booking.index')->with('success', $pesan.sprintf(
            ' Seluruh %d unit langsung ditahan dari stok dan tidak lagi bisa dipesan pelanggan lain.',
            $tertahan,
        ));
    }

    public function cancel(Request $request, StockBooking $booking): RedirectResponse
    {
        WarehouseScope::assert($booking->warehouse_id, $request->user());

        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'cancel_reason.required' => 'Alasan pembatalan wajib diisi — jatah customer tidak dilepas tanpa sebab.',
        ], [
            'cancel_reason' => 'alasan pembatalan',
        ]);

        try {
            $dilepas = $this->booking->cancel($booking, $data['cancel_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('wms.booking.index')->with('success', sprintf(
            'Booking %s dibatalkan. %d unit kembali menjadi stok bebas.',
            $booking->reference,
            $dilepas,
        ));
    }

    /** Stok bebas satu produk — dipakai formulir untuk menunjukkan sisanya. */
    public function availability(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
        ]);

        WarehouseScope::assert((int) $data['warehouse_id'], $request->user());

        $tersedia = $this->allocator->availableFor(
            [(int) $data['product_id']],
            (int) $data['warehouse_id'],
        );

        return response()->json([
            // Angka ini SUDAH bersih dari yang dibooking dan yang teralokasi
            // pesanan — keduanya duduk di qty_allocated, sementara availableFor
            // hanya menjumlahkan qty_available.
            'tersedia' => $tersedia[(int) $data['product_id']] ?? 0,
        ]);
    }
}
