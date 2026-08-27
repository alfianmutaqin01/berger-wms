<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EpodController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Wms\AdminController;
use App\Http\Controllers\Wms\BillingController;
use App\Http\Controllers\Wms\DashboardController;
use App\Http\Controllers\Wms\InboundController;
use App\Http\Controllers\Wms\InventoryController;
use App\Http\Controllers\Wms\MasterController;
use App\Http\Controllers\Wms\NotificationController;
use App\Http\Controllers\Wms\OutboundController;
use App\Http\Controllers\Wms\ProfileController;
use App\Http\Controllers\Wms\ReportController;
use App\Http\Controllers\Wms\UserController;
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
| MFA (F-AUTH-02) belum ada di sini — lihat catatan di AuthController.
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
    Route::get('/dashboard', function () {
        return redirect('/wms/dashboard/admin');
    });
    Route::get('/dashboard/admin', [DashboardController::class, 'admin']);
    Route::get('/dashboard/produksi', [DashboardController::class, 'produksi']);
    Route::get('/dashboard/operator', [DashboardController::class, 'operator']);

    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::prefix('inbound')->group(function () {
        Route::get('/history', [InboundController::class, 'historyIndex']);
        Route::get('/history/{doc_no}', [InboundController::class, 'historyDetail']);
        Route::get('/create', [InboundController::class, 'create']);
        Route::post('/preview', [InboundController::class, 'previewExcel']);
        Route::get('/putaway', [InboundController::class, 'putawayIndex']);
        Route::get('/putaway/{doc_no}', [InboundController::class, 'putawayProcess']);
        Route::get('/verify', [InboundController::class, 'verifyIndex']);
        Route::get('/verify/{doc_no}', [InboundController::class, 'verifyProcess']);

        Route::get('/returns', [InboundController::class, 'returnsIndex']);
        Route::post('/returns/{id}', [InboundController::class, 'processReturn']);
    });

    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer']);

    Route::get('/reports', [ReportController::class, 'index']);

    Route::prefix('master')->group(function () {
        Route::get('/customers', [MasterController::class, 'customers']);
        Route::get('/products', [MasterController::class, 'products']);
    });

    Route::get('/profile', [ProfileController::class, 'index']);
    Route::post('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::prefix('admin')->group(function () {
        // Manajemen User (PRD §6.2 F-MASTER-01) — sudah terhubung ke database.
        Route::get('/users', [UserController::class, 'index'])->name('wms.users.index');
        Route::post('/users', [UserController::class, 'store'])->name('wms.users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('wms.users.update');
        Route::patch('/users/{user}/status', [UserController::class, 'toggleStatus'])->name('wms.users.status');

        Route::get('/sequence', [AdminController::class, 'sequence']);
    });

    // OUTBOUND
    Route::get('/outbound/approval', [OutboundController::class, 'approval']);
    Route::post('/outbound/approve/{id}', [OutboundController::class, 'approveOrder']);
    Route::get('/outbound/picking/batching', [OutboundController::class, 'pickingBatching']);
    Route::get('/outbound/picking', [OutboundController::class, 'picking']);
    Route::post('/outbound/complete-picking/{id}', [OutboundController::class, 'completePicking']);
    Route::get('/outbound/delivery', [OutboundController::class, 'delivery']);
    Route::post('/outbound/generate-sj/{id}', [OutboundController::class, 'generateSuratJalan']);
    Route::get('/outbound/verification', [OutboundController::class, 'verification']);
    Route::post('/outbound/verify-bukti/{id}', [OutboundController::class, 'verifyBukti']);

    // BILLING
    Route::get('/billing', [BillingController::class, 'index']);
    Route::post('/billing/confirm/{id}', [BillingController::class, 'confirm']);
});

// E-POD
Route::get('/epod/{po_number}', [EpodController::class, 'show']);
Route::post('/epod/{po_number}/confirm', [EpodController::class, 'confirm']);
