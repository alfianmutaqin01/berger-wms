<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreProductRequest;
use App\Http\Requests\Wms\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Data Produk — PRD §6.2 F-MASTER-02.
 *
 * DATA CONTRACT (view: wms.master.products)
 * -----------------------------------------
 * $products   : LengthAwarePaginator<Product> — sudah eager-load `category`
 * $categories : Collection<ProductCategory>   — isi dropdown "Product Type"
 * $uoms       : list<string>                  — satuan yang sudah dipakai produk
 * $stats      : array{total:int, active:int, inactive:int, no_pallet:int}
 * $filters    : array{search:?string, category_id:?string, uom:?string, status:?string}
 *
 * CATATAN: halaman ini sengaja TIDAK menampilkan jumlah stok. Stok tinggal di
 * `inventory_stocks` (Fase 4) per gudang/lokasi/batch; menampilkan satu angka
 * di sini akan menyesatkan karena mengabaikan pemisahan tersebut.
 */
class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'category_id' => $request->query('category_id'),
            'uom' => $request->query('uom'),
            'status' => $request->query('status'),
        ];

        $products = Product::query()
            ->with('category')
            ->search($filters['search'])
            ->when($filters['category_id'], fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['uom'], fn ($q, $uom) => $q->where('uom', $uom))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('sku')
            ->paginate(15)
            ->withQueryString();

        return view('wms.master.products', [
            'products' => $products,
            'categories' => ProductCategory::active()->orderBy('name')->get(),
            'uoms' => Product::query()->distinct()->orderBy('uom')->pluck('uom'),
            'stats' => [
                'total' => Product::count(),
                'active' => Product::where('is_active', true)->count(),
                'inactive' => Product::where('is_active', false)->count(),
                'no_pallet' => Product::whereNull('max_qty_per_pallet')->count(),
            ],
            'filters' => $filters,
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->productData();
        $data['created_by'] = $request->user()?->id;

        $product = Product::create($data);

        return redirect()->route('wms.products.index')
            ->with('success', $this->savedMessage($product, 'ditambahkan'));
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->productData());

        return redirect()->route('wms.products.index')
            ->with('success', $this->savedMessage($product, 'diperbarui'));
    }

    /**
     * Menonaktifkan/mengaktifkan produk.
     *
     * Memakai flag `is_active`, BUKAN penghapusan: SKU yang pernah dipakai
     * masih direferensikan oleh riwayat inbound, stok, dan pesanan lama.
     */
    public function toggleStatus(Product $product): RedirectResponse
    {
        $product->update(['is_active' => ! $product->is_active]);

        return back()->with('success', sprintf(
            'Produk %s berhasil %s.',
            $product->sku,
            $product->is_active ? 'diaktifkan' : 'dinonaktifkan'
        ));
    }

    /** Mengingatkan bila kapasitas palet belum terisi, karena PRD §7.1 bergantung padanya. */
    private function savedMessage(Product $product, string $action): string
    {
        $message = "Produk {$product->sku} berhasil {$action}.";

        if ($product->needsPalletCapacity()) {
            $message .= ' Kapasitas palet belum terisi karena ukuran kemasannya '.
                'tidak ada di aturan gudang — mohon lengkapi manual.';
        }

        return $message;
    }
}
