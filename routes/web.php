<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EpodController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Wms\AdminController;
use App\Http\Controllers\Wms\BillingController;
use App\Http\Controllers\Wms\CustomerController;
use App\Http\Controllers\Wms\DashboardController;
use App\Http\Controllers\Wms\ImportController;
use App\Http\Controllers\Wms\InboundController;
use App\Http\Controllers\Wms\InventoryController;
use App\Http\Controllers\Wms\LocationController;
use App\Http\Controllers\Wms\NotificationController;
use App\Http\Controllers\Wms\OutboundController;
use App\Http\Controllers\Wms\ProductController;
use App\Http\Controllers\Wms\ProfileController;
use App\Http\Controllers\Wms\ReportController;
use App\Http\Controllers\Wms\UserController;
use App\Support\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
});

/*
|--------------------------------------------------------------------------
| Autentikasi (PRD §6.1 F-AUTH-01/03/04/05)
|--------------------------------------------------------------------------
| Verifikasi Anti-Bot (F-AUTH-02, reCAPTCHA) belum terpasang — akan menyatu di
| POST /login yang sama, bukan rute terpisah. Lihat catatan di AuthController.
*/
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
| Dipanggil oleh health check pada pipeline deploy (.github/workflows/deploy.yml)
| dan oleh scripts/health-check.sh di server. Lihat docs/6_cicd_docker_setup.md §9.1.
|
| Mengembalikan 200 bila seluruh dependensi sehat, 503 bila ada yang gagal —
| sehingga `curl -f` pada pipeline otomatis menggagalkan deploy yang bermasalah.
*/
Route::get('/health', function () {
    $checks = [
        'database' => false,
        'redis' => false,
        'storage' => false,
    ];

    try {
        DB::connection()->getPdo();
        $checks['database'] = true;
    } catch (Throwable $e) {
        // Biarkan false; detail error sengaja tidak dibocorkan ke response.
    }

    try {
        Redis::ping();
        $checks['redis'] = true;
    } catch (Throwable $e) {
        // Biarkan false.
    }

    $checks['storage'] = is_writable(storage_path());

    $allHealthy = ! in_array(false, $checks, true);

    return response()->json([
        'status' => $allHealthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => now()->toISOString(),
    ], $allHealthy ? 200 : 503);
})->name('health');

// SALES PORTAL ROUTES
Route::prefix('sales')->middleware(['auth', 'session.track', 'portal:sales'])->group(function () {
    Route::get('/dashboard', function () {
        return view('sales.dashboard');
    });
    Route::get('/my-orders', [SalesOrderController::class, 'history']);
    Route::post('/report-return', [SalesOrderController::class, 'reportReturn']);
    Route::get('/new-order', [SalesOrderController::class, 'create']);
    Route::post('/new-order', [SalesOrderController::class, 'store']);

    // Dihapus pada PRD v1.1:
    // - GET /customers  -> Sales tidak lagi mengelola/mengajukan pelanggan
    //                      (kini lewat Master Customer di Portal WMS).
    // - GET /tracking    -> memanggil view 'sales.tracking' yang tidak pernah ada,
    //                      sehingga selalu melempar error 500. Tracking sudah
    //                      tersedia sebagai timeline di halaman My Orders.
});

// WMS PORTAL ROUTES
Route::prefix('wms')->middleware(['auth', 'session.track', 'portal:wms'])->group(function () {
    /*
    | Middleware `can:<fitur>` mengacu ke Gate yang didaftarkan dari
    | App\Support\Permission — matriks yang SAMA dipakai sidebar untuk
    | menyembunyikan menu. Menyembunyikan menu saja tidak mengamankan apa pun;
    | baris `can:` di bawah inilah penegak sebenarnya.
    */

    // Mengarahkan ke dashboard yang sesuai role — Produksi & Operator tidak
    // punya akses ke dashboard utama, jadi tidak boleh diarahkan ke sana.
    Route::get('/dashboard', function () {
        return redirect(DashboardController::pathFor(request()->user()));
    });
    Route::get('/dashboard/admin', [DashboardController::class, 'admin'])
        ->middleware('can:'.Permission::DASHBOARD_MAIN);
    Route::get('/dashboard/produksi', [DashboardController::class, 'produksi'])
        ->middleware('can:'.Permission::DASHBOARD_PRODUKSI);
    Route::get('/dashboard/operator', [DashboardController::class, 'operator'])
        ->middleware('can:'.Permission::DASHBOARD_OPERATOR);

    // Notifikasi & profil: milik pribadi tiap user, tidak dibatasi role.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/profile', [ProfileController::class, 'index']);
    Route::post('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::prefix('inbound')->group(function () {
        Route::middleware('can:'.Permission::INBOUND_HISTORY)->group(function () {
            Route::get('/history', [InboundController::class, 'historyIndex'])->name('wms.inbound.history');
            Route::get('/history/{doc_no}', [InboundController::class, 'historyDetail']);
        });

        // Input Produksi (PRD §6.3 F-INB-01) — sudah terhubung ke database.
        // Alur tiga langkah: form -> pratinjau (tanpa menyentuh DB) -> simpan.
        Route::middleware('can:'.Permission::INBOUND_CREATE)->group(function () {
            Route::get('/create', [InboundController::class, 'create'])->name('wms.inbound.create');
            Route::post('/preview', [InboundController::class, 'previewExcel'])->name('wms.inbound.preview');
            Route::post('/store', [InboundController::class, 'store'])->name('wms.inbound.store');
            Route::post('/cancel', [InboundController::class, 'cancelPreview'])->name('wms.inbound.cancel');
        });

        Route::middleware('can:'.Permission::INBOUND_PUTAWAY)->group(function () {
            Route::get('/putaway', [InboundController::class, 'putawayIndex']);
            Route::get('/putaway/{doc_no}', [InboundController::class, 'putawayProcess']);
        });

        Route::middleware('can:'.Permission::INBOUND_VERIFY)->group(function () {
            Route::get('/verify', [InboundController::class, 'verifyIndex']);
            Route::get('/verify/{doc_no}', [InboundController::class, 'verifyProcess']);
        });

        Route::middleware('can:'.Permission::INBOUND_RETURNS)->group(function () {
            Route::get('/returns', [InboundController::class, 'returnsIndex']);
            Route::post('/returns/{id}', [InboundController::class, 'processReturn']);
        });
    });

    // Produksi & Operator boleh MELIHAT stok, tapi tidak mengubahnya —
    // karena itu adjust/transfer dipagari gate yang berbeda dari index.
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('can:'.Permission::INVENTORY_VIEW);
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])
        ->middleware('can:'.Permission::INVENTORY_ADJUST);
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer'])
        ->middleware('can:'.Permission::INVENTORY_TRANSFER);

    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('can:'.Permission::REPORTS_VIEW);

    Route::prefix('master')->group(function () {
        // Master Pelanggan (PRD §6.2 F-MASTER-06) — sudah terhubung ke database.
        Route::middleware('can:'.Permission::MASTER_CUSTOMERS)->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])->name('wms.customers.index');
            Route::post('/customers', [CustomerController::class, 'store'])->name('wms.customers.store');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('wms.customers.update');
            Route::patch('/customers/{customer}/status', [CustomerController::class, 'toggleStatus'])->name('wms.customers.status');
        });

        // Master Produk (PRD §6.2 F-MASTER-02) — sudah terhubung ke database.
        Route::middleware('can:'.Permission::MASTER_PRODUCTS)->group(function () {
            Route::get('/products', [ProductController::class, 'index'])->name('wms.products.index');
            Route::post('/products', [ProductController::class, 'store'])->name('wms.products.store');
            Route::put('/products/{product}', [ProductController::class, 'update'])->name('wms.products.update');
            Route::patch('/products/{product}/status', [ProductController::class, 'toggleStatus'])->name('wms.products.status');
        });

        // Master Lokasi Rak (PRD §5.2) — sudah terhubung ke database.
        Route::middleware('can:'.Permission::MASTER_LOCATIONS)->group(function () {
            Route::get('/locations', [LocationController::class, 'index'])->name('wms.locations.index');
            // Denah gudang — didaftarkan SEBELUM /locations/{location} agar
            // "map" tidak tertangkap sebagai parameter route model binding.
            Route::get('/locations/map', [LocationController::class, 'map'])->name('wms.locations.map');
            Route::post('/locations', [LocationController::class, 'store'])->name('wms.locations.store');
            Route::put('/locations/{location}', [LocationController::class, 'update'])->name('wms.locations.update');
            Route::patch('/locations/{location}/status', [LocationController::class, 'toggleStatus'])->name('wms.locations.status');
        });

        /*
        | Impor Excel. Dipagari gate yang sama dengan halaman masternya —
        | siapa yang boleh mengubah data master, dia pula yang boleh mengimpor.
        | Dua tahap: preview (tanpa menyentuh DB) lalu store (menyimpan).
        */
        Route::post('/products/import/preview', [ImportController::class, 'preview'])
            ->defaults('type', 'products')->middleware('can:'.Permission::MASTER_PRODUCTS)
            ->name('wms.products.import.preview');
        Route::post('/products/import', [ImportController::class, 'store'])
            ->defaults('type', 'products')->middleware('can:'.Permission::MASTER_PRODUCTS)
            ->name('wms.products.import');
        Route::post('/products/import/cancel', [ImportController::class, 'cancel'])
            ->defaults('type', 'products')->middleware('can:'.Permission::MASTER_PRODUCTS)
            ->name('wms.products.import.cancel');

        Route::post('/customers/import/preview', [ImportController::class, 'preview'])
            ->defaults('type', 'customers')->middleware('can:'.Permission::MASTER_CUSTOMERS)
            ->name('wms.customers.import.preview');
        Route::post('/customers/import', [ImportController::class, 'store'])
            ->defaults('type', 'customers')->middleware('can:'.Permission::MASTER_CUSTOMERS)
            ->name('wms.customers.import');
        Route::post('/customers/import/cancel', [ImportController::class, 'cancel'])
            ->defaults('type', 'customers')->middleware('can:'.Permission::MASTER_CUSTOMERS)
            ->name('wms.customers.import.cancel');
    });

    Route::prefix('admin')->group(function () {
        // Manajemen User (PRD §6.2 F-MASTER-01) — sudah terhubung ke database.
        Route::middleware('can:'.Permission::ADMIN_USERS)->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('wms.users.index');
            Route::post('/users', [UserController::class, 'store'])->name('wms.users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('wms.users.update');
            Route::patch('/users/{user}/status', [UserController::class, 'toggleStatus'])->name('wms.users.status');
        });

        Route::get('/sequence', [AdminController::class, 'sequence'])
            ->middleware('can:'.Permission::ADMIN_SEQUENCE);
    });

    // OUTBOUND — proses picking di tangan Operator; sisanya alur Logistik.
    Route::prefix('outbound')->group(function () {
        Route::middleware('can:'.Permission::OUTBOUND_APPROVAL)->group(function () {
            Route::get('/approval', [OutboundController::class, 'approval']);
            Route::post('/approve/{id}', [OutboundController::class, 'approveOrder']);
        });

        Route::get('/picking/batching', [OutboundController::class, 'pickingBatching'])
            ->middleware('can:'.Permission::OUTBOUND_PICKING_LIST);

        Route::middleware('can:'.Permission::OUTBOUND_PICKING_PROCESS)->group(function () {
            Route::get('/picking', [OutboundController::class, 'picking']);
            Route::post('/complete-picking/{id}', [OutboundController::class, 'completePicking']);
        });

        Route::middleware('can:'.Permission::OUTBOUND_DELIVERY)->group(function () {
            Route::get('/delivery', [OutboundController::class, 'delivery']);
            Route::post('/generate-sj/{id}', [OutboundController::class, 'generateSuratJalan']);
        });

        Route::middleware('can:'.Permission::OUTBOUND_VERIFICATION)->group(function () {
            Route::get('/verification', [OutboundController::class, 'verification']);
            Route::post('/verify-bukti/{id}', [OutboundController::class, 'verifyBukti']);
        });
    });

    // BILLING
    Route::middleware('can:'.Permission::BILLING_VIEW)->group(function () {
        Route::get('/billing', [BillingController::class, 'index']);
        Route::post('/billing/confirm/{id}', [BillingController::class, 'confirm']);
    });
});

// E-POD
Route::get('/epod/{po_number}', [EpodController::class, 'show']);
Route::post('/epod/{po_number}/confirm', [EpodController::class, 'confirm']);
