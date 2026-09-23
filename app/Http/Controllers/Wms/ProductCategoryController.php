<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreProductCategoryRequest;
use App\Http\Requests\Wms\UpdateProductCategoryRequest;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Kategori Produk — PRD §6.2 F-MASTER-03.
 *
 * KENAPA HALAMAN INI BARU ADA SEKARANG. Tabelnya, modelnya, dan kolom
 * products.category_id sudah ada sejak awal, dan kategorinya dipakai sebagai
 * saringan di Master Produk dan Daftar Stok. Yang tidak pernah ada adalah
 * cara MENGELOLANYA: kategori hanya bisa masuk lewat impor produk atau
 * seeder. Akibatnya kategori baru menuntut impor berkas atau orang yang bisa
 * menyentuh basis data — untuk pekerjaan yang semestinya satu isian.
 *
 * TIDAK ADA PENGHAPUSAN, hanya aktif/non-aktif — aturan yang sama dengan
 * Master Produk dan Master Pelanggan. Kategori yang pernah dipakai masih
 * ditunjuk produk yang ada, dan produk lama harus tetap bisa menyebut
 * kategorinya apa adanya. Yang dinonaktifkan hilang dari pilihan saat
 * menambah produk baru, tanpa mengubah satu pun produk yang sudah ada.
 *
 * DATA CONTRACT
 * -------------
 * index() : $categories LengthAwarePaginator<ProductCategory> (dengan
 *           products_count), $filters{search,status},
 *           $stats{total,active,inactive,tanpa_produk}
 */
class ProductCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
        ];

        $categories = ProductCategory::query()
            // Dipakai layar untuk memutuskan boleh-tidaknya dinonaktifkan
            // tanpa memicu satu query per baris.
            ->withCount('products')
            ->when($filters['search'], fn ($q, $cari) => $q->where(
                fn ($w) => $w->where('name', 'ilike', '%'.$cari.'%')
                    ->orWhere('description', 'ilike', '%'.$cari.'%')
            ))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('wms.master.product-categories', [
            'categories' => $categories,
            'filters' => $filters,
            'stats' => [
                'total' => ProductCategory::count(),
                'active' => ProductCategory::where('is_active', true)->count(),
                'inactive' => ProductCategory::where('is_active', false)->count(),
                // Kategori tanpa satu pun produk: biasanya salah ketik yang
                // ditinggalkan, dan tidak ada layar lain yang menyebutnya.
                'tanpa_produk' => ProductCategory::whereDoesntHave('products')->count(),
            ],
        ]);
    }

    public function store(StoreProductCategoryRequest $request): RedirectResponse
    {
        $category = ProductCategory::create($request->categoryData());

        Activity::record(
            ActivityLog::MASTER_CREATE,
            sprintf('Menambah kategori produk %s.', $category->name),
            $category,
            null,
            ['nama' => $category->name],
        );

        return redirect()->route('wms.product-categories.index')
            ->with('success', sprintf('Kategori %s berhasil ditambahkan.', $category->name));
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        $namaLama = $category->name;

        $category->update($request->categoryData());

        Activity::record(
            ActivityLog::MASTER_UPDATE,
            $namaLama === $category->name
                ? sprintf('Mengubah kategori produk %s.', $category->name)
                : sprintf('Mengganti nama kategori produk %s menjadi %s.', $namaLama, $category->name),
            $category,
            null,
            ['nama' => $category->name, 'kolom_berubah' => array_keys($category->getChanges())],
        );

        return redirect()->route('wms.product-categories.index')
            ->with('success', sprintf('Kategori %s berhasil diperbarui.', $category->name));
    }

    /**
     * Menonaktifkan/mengaktifkan kategori.
     *
     * Memakai flag `is_active`, BUKAN penghapusan — alasannya di catatan
     * kelas ini. Menonaktifkan kategori yang masih dipakai TETAP DIIZINKAN:
     * justru begitulah cara berhenti memakai kategori lama tanpa menyentuh
     * produk yang sudah terlanjur memakainya. Jumlah produknya disebut di
     * pesan supaya yang menekan tahu persis apa yang ia sentuh.
     */
    public function toggleStatus(ProductCategory $category): RedirectResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        $jumlah = Product::where('category_id', $category->id)->count();

        Activity::record(
            ActivityLog::MASTER_DEACTIVATE,
            sprintf(
                'Kategori produk %s %s.',
                $category->name,
                $category->is_active ? 'diaktifkan' : 'dinonaktifkan',
            ),
            $category,
            null,
            ['nama' => $category->name, 'aktif' => $category->is_active, 'produk' => $jumlah],
        );

        return back()->with('success', sprintf(
            'Kategori %s berhasil %s.%s',
            $category->name,
            $category->is_active ? 'diaktifkan' : 'dinonaktifkan',
            ! $category->is_active && $jumlah > 0
                ? sprintf(' %d produk yang memakainya TIDAK berubah; kategori ini hanya tidak lagi muncul sebagai pilihan baru.', $jumlah)
                : '',
        ));
    }
}
