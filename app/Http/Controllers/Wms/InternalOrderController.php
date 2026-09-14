<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\InternalOrderRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerBilling;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\Activity;
use App\Support\OrderCutoff;
use App\Support\Outbound\OrderComposer;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Buat Pesanan jalur internal — Admin & Manager, atas nama seorang Sales.
 *
 * KENAPA ADA
 * ----------
 * Permintaan pemilik produk: ada keadaan yang membuat pesanan tidak boleh
 * bergantung pada Sales-nya. Sampai sekarang satu-satunya pintu pembuatan
 * pesanan ada di Portal Sales, dan PRD §5.2 menutup portal itu rapat-rapat
 * untuk semua peran Warehouse/Admin — jadi ketika Sales berhalangan atau
 * tidak bisa diandalkan, tidak ada jalan lain sama sekali.
 *
 * TIGA HAL YANG MEMBEDAKANNYA DARI FORM SALES, dan ketiganya disengaja:
 *
 *   1. ATAS NAMA SIAPA harus dipilih. Pesanannya tetap MILIK Sales itu — ia
 *      yang melihatnya, ia yang mengunggah bukti Surat Jalan, angkanya masuk
 *      laporan Kinerja Sales atas namanya. Yang mengetik dicatat terpisah di
 *      `placed_by`, supaya catatannya tidak berbohong.
 *   2. ALASAN WAJIB. Pemilik produk memutuskan pembuat BOLEH menyetujui
 *      pesanannya sendiri, jadi tidak ada mata kedua di rantai ini. Kalimat
 *      yang harus diketik itulah yang dibaca orang saat suatu hari pesanan
 *      ini dipersoalkan.
 *   3. SALES-NYA DIBERI TAHU. Ia satu-satunya orang di luar rantai yang bisa
 *      menyadari kalau ada yang tidak beres, dan pesanan yang muncul di
 *      daftarnya tanpa penjelasan justru membuatnya diam.
 *
 * YANG SENGAJA TIDAK DIBEDAKAN: batas jam cutoff tetap berlaku. Cutoff ada
 * supaya gudang bisa merencanakan picking hari itu, bukan untuk mendisiplinkan
 * Sales — membebaskan jalur ini darinya berarti membuka cara mengacaukan
 * rencana picking yang tidak pernah disepakati siapa pun.
 *
 * TIDAK ADA UBAH DAN HAPUS DI SINI. Draft milik Sales tetap urusan Sales;
 * jalur ini membuat pesanan lalu selesai. Menambahkan ubah/hapus berarti
 * Admin bisa menyunting pesanan yang tercatat atas nama orang lain tanpa
 * orang itu tahu — dan itu jauh melewati apa yang diminta.
 *
 * DATA CONTRACT
 * -------------
 * create() : $salesTersedia, $gudangPilihan, $gudangTerpilih, $paymentTerms,
 *            $customerTerpilih, $produkTerpilih, $cutoffOpen, $cutoffLabel,
 *            $dokumenConfig
 */
class InternalOrderController extends Controller
{
    private const MIN_CARI = 2;

    private const MAKS_SARAN = 20;

    public function __construct(private readonly OrderComposer $komposer) {}

    public function create(Request $request): View
    {
        return view('wms.outbound.internal-order', $this->formData($request));
    }

    public function store(InternalOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['warehouse_id'] = $request->gudangTujuan();

        if ($galat = $this->galatDokumen($request)) {
            return back()->withInput()->withErrors(['document' => $galat]);
        }

        $sales = User::findOrFail($data['sales_user_id']);

        $order = DB::transaction(function () use ($request, $data, $sales): SalesOrder {
            // Dua orang yang BERBEDA: pemiliknya Sales, pengetiknya user ini.
            $order = $this->komposer->baru($sales->id, $request->user()->id);
            $order->placed_reason = trim($data['reason']);

            $this->komposer->isi($order, $data, $request->file('document'));
            $order->save();

            $this->komposer->tulisRincian($order, $data);

            if ($request->wantsSubmit()) {
                $this->komposer->kirimKeLogistik($order);
            }

            return $order;
        });

        /*
         * DICATAT TERSENDIRI, terpisah dari ORDER_SUBMIT.
         *
         * Yang perlu terbaca di log bukan cuma "pesanan dikirim" — itu terjadi
         * ribuan kali — melainkan "pesanan dibuat atas nama orang lain",
         * yang seharusnya jarang. Menggabungkannya ke satu jenis tindakan
         * membuat yang jarang tenggelam di antara yang biasa, dan penyaring
         * di halaman Log Aktivitas tidak bisa lagi memisahkannya.
         *
         * Draft pun dicatat: pesanan yang dibuat lalu dibiarkan sebagai draft
         * tetap membuat pesanan atas nama orang lain.
         */
        Activity::record(
            ActivityLog::ORDER_PLACED_INTERNAL,
            sprintf(
                'Membuat pesanan %s atas nama %s untuk %s%s. Alasan: %s',
                $order->order_number,
                $sales->full_name,
                $order->customer?->name ?? 'pelanggan',
                $request->wantsSubmit() ? '' : ' (masih draft)',
                $order->placed_reason,
            ),
            $order,
            $order->warehouse_id,
            [
                'atas_nama' => $sales->full_name,
                'customer' => $order->customer?->name,
                'alasan' => $order->placed_reason,
                'langsung_dikirim' => $request->wantsSubmit(),
            ],
        );

        return redirect()->route('wms.approval.index')->with(
            'success',
            $request->wantsSubmit()
                ? sprintf(
                    'Pesanan %s dibuat atas nama %s dan sudah masuk antrean. %s sudah diberi tahu.',
                    $order->order_number,
                    $sales->full_name,
                    $sales->full_name,
                )
                : sprintf(
                    'Draft pesanan %s tersimpan atas nama %s. Belum masuk antrean — kirim dari sini kalau sudah siap.',
                    $order->order_number,
                    $sales->full_name,
                ),
        );
    }

    /* ------------------------------------------------- Pencarian isian */

    public function lookupCustomers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (mb_strlen($q) < self::MIN_CARI) {
            return response()->json([]);
        }

        $hasil = Customer::active()
            ->search($q)
            ->orderBy('name')
            ->limit(self::MAKS_SARAN)
            ->get(['id', 'code', 'name']);

        // F-BILL-03: penanda di form Buat Pesanan — informasi, tidak memblokir.
        $piutang = CustomerBilling::penandaCustomer($hasil->pluck('id')->all());

        return response()->json($hasil->map(fn (Customer $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'menunggak' => $piutang[$c->id]['lewat_terlama'] ?? 0,
        ]));
    }

    /**
     * Cari produk — TANPA angka stok.
     *
     * Sengaja mengikuti aturan semi-blind yang berlaku di Portal Sales
     * (F-INV-03): yang dipakai saat memesan adalah indikator, bukan angka.
     * Menambahkan angka di sini akan membuat dua jalur pemesanan bekerja
     * dengan dasar yang berbeda, dan Logistik tetap yang memutuskan qty
     * sebenarnya saat approval.
     */
    public function lookupProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (mb_strlen($q) < self::MIN_CARI) {
            return response()->json([]);
        }

        return response()->json(
            Product::where('is_active', true)
                ->search($q)
                ->orderBy('sku')
                ->limit(self::MAKS_SARAN)
                ->get(['id', 'sku', 'name', 'uom'])
                ->map(fn (Product $p) => [
                    'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'uom' => $p->uom,
                ])
        );
    }

    /* ------------------------------------------------------------ Bantuan */

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        $user = $request->user();
        $gudangPilihan = WarehouseScope::options($user);

        // Tiga sumber, dan urutannya penting. Batas kewenangan mengalahkan
        // apa pun; sesudah itu isian yang kembali dari validasi gagal
        // mengalahkan pilihan di URL, supaya formulir yang ditolak tidak
        // diam-diam berpindah gudang di tangan orang yang sedang membetulkan
        // isiannya.
        $gudangTerpilih = WarehouseScope::boundary($user)
            ?? (int) (old('warehouse_id')
                ?: $request->query('warehouse_id')
                ?: $gudangPilihan->first()?->id);

        return [
            'salesTersedia' => $this->salesDiGudang($gudangTerpilih),
            'gudangPilihan' => $gudangPilihan,
            'gudangTerpilih' => $gudangTerpilih,
            // Akun lintas gudang (Super Admin) memilih gudangnya; yang
            // dibatasi tidak, karena pilihannya cuma satu dan kolomnya hanya
            // akan jadi tempat mencoba gudang lain.
            'bolehPilihGudang' => WarehouseScope::unrestricted($user),
            'paymentTerms' => PaymentTerm::where('is_active', true)
                ->orderBy('sort_order')->get(['id', 'code', 'name', 'days']),
            'customerTerpilih' => $this->customerTerpilih(),
            'produkTerpilih' => $this->produkTerpilih(),
            'cutoffOpen' => OrderCutoff::isOpen(),
            'cutoffLabel' => OrderCutoff::label(),
            'dokumenConfig' => config('wms.order_document'),
        ];
    }

    /**
     * Sales aktif di satu gudang.
     *
     * Jumlahnya sedikit — satu sampai beberapa per gudang — jadi daftar biasa
     * sudah cukup dan tidak perlu kolom pencarian seperti customer/produk.
     *
     * @return Collection<int, User>
     */
    private function salesDiGudang(?int $gudangId)
    {
        if ($gudangId === null) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->where('warehouse_id', $gudangId)
            ->whereHas('role', fn ($r) => $r->where('slug', Role::SALES))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_id']);
    }

    /**
     * Pilihan yang harus muncul lagi setelah formulir ditolak validasi.
     *
     * Kolom ketik-lalu-pilih menyimpan id tersembunyi dan nama di kolom yang
     * terlihat; tanpa ini kolomnya kosong padahal id-nya masih terkirim.
     *
     * @return array{id:int, code:string, name:string}|null
     */
    private function customerTerpilih(): ?array
    {
        $id = old('customer_id');

        if (blank($id)) {
            return null;
        }

        $customer = Customer::find($id, ['id', 'code', 'name']);

        return $customer ? ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name] : null;
    }

    /** @return array<int, array{id:int, sku:string, name:string, uom:string}> */
    private function produkTerpilih(): array
    {
        $ids = collect(old('items', []))->pluck('product_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::whereIn('id', $ids)
            ->get(['id', 'sku', 'name', 'uom'])
            ->mapWithKeys(fn (Product $p) => [$p->id => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'uom' => $p->uom,
            ]])
            ->all();
    }

    /**
     * Dokumen wajib pada metode dokumen.
     *
     * Tidak bisa ditaruh di FormRequest karena di jalur Sales aturannya
     * bergantung pada draft yang sedang disunting; di sini selalu pesanan
     * baru, tetapi bentuk pemeriksaannya dijaga tetap sama supaya pesan
     * galatnya identik di kedua jalur.
     */
    private function galatDokumen(InternalOrderRequest $request): ?string
    {
        if ($request->input('order_source') !== SalesOrder::SOURCE_DOCUMENT) {
            return null;
        }

        if ($request->hasFile('document')) {
            return null;
        }

        return 'Unggah dokumen PO customer — pesanan bermetode dokumen tidak bisa diproses Logistik tanpa berkasnya.';
    }
}
