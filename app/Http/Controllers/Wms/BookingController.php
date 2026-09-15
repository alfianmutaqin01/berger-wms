<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBooking;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Outbound\FifoAllocator;
use App\Support\Outbound\ProductBooking;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
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
 *           $warehouse, $filters{search,status,warehouse},
 *           $stats{berlaku,menunggu,tertahan}
 * lookupCustomers()/lookupProducts() : JSON untuk kolom ketik-lalu-pilih
 */
class BookingController extends Controller
{
    /** Ketikan sependek ini belum menyempitkan apa pun; hasilnya kosong. */
    private const MIN_CARI = 2;

    /** Saran yang lebih panjang daripada layar mengembalikan masalah gulirnya. */
    private const MAKS_SARAN = 10;

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
            // Customer dan produk TIDAK lagi dikirim sebagai daftar penuh:
            // keduanya dicari sambil mengetik lewat lookupCustomers() dan
            // lookupProducts(). Yang dulu ikut halaman ini 1.840 + 1.734
            // baris, hampir seluruhnya tidak pernah dipakai.
            'pilihanLama' => $this->pilihanLama(),
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

        Activity::record(
            ActivityLog::BOOKING_CREATE,
            sprintf(
                'Membuat booking %s untuk %s: %d %s (%d ditahan dari stok, %d menunggu produksi).',
                $booking->reference,
                $booking->customer?->name ?? '—',
                $booking->qty_booked,
                $booking->product?->sku ?? '—',
                $tertahan,
                $menunggu,
            ),
            $booking,
            $booking->warehouse_id,
            [
                'referensi' => $booking->reference,
                'customer' => $booking->customer?->name,
                'sku' => $booking->product?->sku,
                'qty' => $booking->qty_booked,
                'tertahan' => $tertahan,
                'menunggu' => $menunggu,
                'dibutuhkan' => $data['needed_by'] ?? null,
            ],
        );

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

        Activity::record(
            ActivityLog::BOOKING_CANCEL,
            sprintf(
                'Membatalkan booking %s (%s) — %d unit kembali jadi stok bebas. Alasan: %s',
                $booking->reference,
                $booking->customer?->name ?? '—',
                $dilepas,
                $data['cancel_reason'],
            ),
            $booking,
            $booking->warehouse_id,
            [
                'referensi' => $booking->reference,
                'customer' => $booking->customer?->name,
                'sku' => $booking->product?->sku,
                'dilepas' => $dilepas,
                'alasan' => $data['cancel_reason'],
            ],
        );

        return redirect()->route('wms.booking.index')->with('success', sprintf(
            'Booking %s dibatalkan. %d unit kembali menjadi stok bebas.',
            $booking->reference,
            $dilepas,
        ));
    }

    /**
     * Label pilihan yang harus muncul lagi setelah formulir ditolak.
     *
     * Kolom ketik-lalu-pilih menyimpan ID di input tersembunyi dan NAMA di
     * kolom yang terlihat. Saat formulirnya dikembalikan karena validasi
     * gagal, id-nya ikut kembali lewat old() tetapi namanya tidak — kolomnya
     * terlihat KOSONG padahal id-nya masih terkirim. Orang lalu mengisi ulang
     * customer yang sebenarnya sudah benar, atau lebih buruk: mengira ia
     * belum memilih apa-apa dan menekan simpan lagi.
     *
     * @return array{customer: ?string, produk: ?string}
     */
    private function pilihanLama(): array
    {
        $customer = old('customer_id')
            ? Customer::find((int) old('customer_id'), ['code', 'name'])
            : null;

        $produk = old('product_id')
            ? Product::find((int) old('product_id'), ['sku', 'name'])
            : null;

        return [
            'customer' => $customer ? $customer->code.' — '.$customer->name : null,
            'produk' => $produk ? $produk->sku.' — '.$produk->name : null,
        ];
    }

    /**
     * Cari customer sambil mengetik.
     *
     * KENAPA TIDAK LAGI DIKIRIM SEBAGAI DAFTAR PENUH. Dropdown lamanya memuat
     * SELURUH customer aktif — 1.840 baris saat ini, dan akan terus bertambah.
     * Dua akibatnya: halaman membawa ratusan kilobita yang hampir seluruhnya
     * tidak akan dipakai, dan orang harus menggulir mencari nama yang
     * kebetulan ada di tengah. Yang kedua lebih mahal: menggulir daftar 1.840
     * nama untuk mencari satu customer bukan pekerjaan, itu hukuman.
     */
    public function lookupCustomers(Request $request): JsonResponse
    {
        return response()->json($this->cari(
            $request,
            Customer::where('is_active', true)->orderBy('name'),
            fn ($q, string $pola) => $q->where(fn ($w) => $w
                ->where('name', 'ILIKE', $pola)->orWhere('code', 'ILIKE', $pola)),
            ['id', 'code', 'name'],
            fn (Customer $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name],
        ));
    }

    /**
     * Cari produk sambil mengetik, sekalian dengan stok bebasnya.
     *
     * Stoknya ikut supaya pilihan bisa diambil TANPA mencoba satu per satu.
     * Gudangnya dibaca dari permintaan karena formulir booking memang punya
     * pemilih gudang — tetapi tetap lewat WarehouseScope::assert, jadi
     * mengganti angkanya di URL tidak membuka gudang yang bukan wewenangnya.
     */
    public function lookupProducts(Request $request): JsonResponse
    {
        $gudangId = (int) $request->query('warehouse_id');

        if ($gudangId > 0) {
            WarehouseScope::assert($gudangId, $request->user());
        }

        $produk = $this->cari(
            $request,
            Product::where('is_active', true)->orderBy('sku'),
            fn ($q, string $pola) => $q->where(fn ($w) => $w
                ->where('sku', 'ILIKE', $pola)->orWhere('name', 'ILIKE', $pola)),
            ['id', 'sku', 'name', 'uom'],
            fn (Product $p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'uom' => $p->uom],
        );

        if ($produk === [] || $gudangId <= 0) {
            return response()->json($produk);
        }

        // Sekali untuk seluruh hasil, bukan satu query per produk.
        $tersedia = $this->allocator->availableFor(array_column($produk, 'id'), $gudangId);

        return response()->json(array_map(
            fn (array $p) => $p + ['tersedia' => $tersedia[$p['id']] ?? 0],
            $produk,
        ));
    }

    /**
     * Kerangka pencarian yang sama untuk keduanya.
     *
     * Batas hasilnya disengaja: daftar saran yang lebih panjang daripada
     * layar mengembalikan persis masalah yang sedang diperbaiki — menggulir
     * mencari yang benar. Yang tidak ketemu dalam sepuluh baris teratas
     * lebih cepat ditemukan dengan mengetik satu huruf lagi.
     *
     * @return list<array<string, mixed>>
     */
    private function cari(Request $request, $query, callable $saring, array $kolom, callable $bentuk): array
    {
        $q = trim((string) $request->query('q'));

        // Dua huruf. Satu huruf mengembalikan hampir seluruh master data dan
        // tidak menyempitkan apa pun.
        if (mb_strlen($q) < self::MIN_CARI) {
            return [];
        }

        return $saring($query, '%'.$q.'%')
            ->limit(self::MAKS_SARAN)
            ->get($kolom)
            ->map($bentuk)
            ->values()
            ->all();
    }

    /** Stok bebas satu produk — dipakai formulir untuk menunjukkan sisanya. */
    public function availability(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
        ]);

        WarehouseScope::assert((int) $data['warehouse_id'], $request->user());

        $produkId = (int) $data['product_id'];
        $gudangId = (int) $data['warehouse_id'];

        $tersedia = $this->allocator->availableFor([$produkId], $gudangId);

        return response()->json([
            // Angka ini SUDAH bersih dari yang dibooking dan yang teralokasi
            // pesanan — keduanya duduk di qty_allocated, sementara availableFor
            // hanya menjumlahkan qty_available.
            'tersedia' => $tersedia[$produkId] ?? 0,
            // "Nol di sini" dan "tidak ada di mana pun" adalah dua keadaan yang
            // sangat berbeda dan dahulu terbaca sama. Stok gudang lain TIDAK
            // bisa dipakai booking ini, tetapi orang yang baru saja
            // memasukkannya berhak tahu ke mana perginya.
            'gudang_lain' => $this->allocator->elsewhereFor([$produkId], $gudangId)[$produkId] ?? [],
        ]);
    }
}
