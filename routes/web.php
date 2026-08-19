<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// SALES PORTAL ROUTES
Route::prefix('sales')->group(function () {
    Route::get('/dashboard', function () {
        return view('sales.dashboard');
    });
    Route::get('/my-orders', [\App\Http\Controllers\Sales\SalesOrderController::class, 'history']);
    Route::get('/new-order', [\App\Http\Controllers\Sales\SalesOrderController::class, 'create']);
    Route::post('/new-order', [\App\Http\Controllers\Sales\SalesOrderController::class, 'store']);
    Route::get('/tracking', function () {
        return view('sales.tracking');
    });
    Route::get('/customers', function () {
        return view('sales.customers');
    });
});

// WMS PORTAL ROUTES
Route::prefix('wms')->group(function () {
    Route::get('/dashboard', function () {
        return redirect('/wms/dashboard/admin'); // Default redirect
    });
    Route::get('/dashboard/admin', [\App\Http\Controllers\Wms\DashboardController::class, 'admin']);
    Route::get('/dashboard/produksi', [\App\Http\Controllers\Wms\DashboardController::class, 'produksi']);
    Route::get('/dashboard/operator', [\App\Http\Controllers\Wms\DashboardController::class, 'operator']);
    
    Route::prefix('inbound')->group(function () {
        Route::get('/history', [\App\Http\Controllers\Wms\InboundController::class, 'historyIndex']);
        Route::get('/history/{doc_no}', [\App\Http\Controllers\Wms\InboundController::class, 'historyDetail']);
        Route::get('/create', [\App\Http\Controllers\Wms\InboundController::class, 'create']);
        Route::post('/preview', [\App\Http\Controllers\Wms\InboundController::class, 'previewExcel']);
        Route::get('/putaway', [\App\Http\Controllers\Wms\InboundController::class, 'putawayIndex']);
        Route::get('/putaway/{doc_no}', [\App\Http\Controllers\Wms\InboundController::class, 'putawayProcess']);
        Route::get('/verify', [\App\Http\Controllers\Wms\InboundController::class, 'verifyIndex']);
        Route::get('/verify/{doc_no}', [\App\Http\Controllers\Wms\InboundController::class, 'verifyProcess']);
    });

    // Inventory
    Route::get('/inventory', [\App\Http\Controllers\Wms\InventoryController::class, 'index']);
    Route::get('/orders', function () {
        return view('wms.orders');
    });
    Route::get('/delivery', function () {
        return view('wms.delivery');
    });
    Route::get('/billing', function () {
        return view('wms.billing');
    });
    Route::get('/approval', function () {
        return view('wms.approval');
    });
    
    // Reports
    Route::get('/reports', function () {
        return view('wms.reports.index');
    });
    
    // Master Data
    Route::prefix('master')->group(function () {
        Route::get('/customers', function () {
            return view('wms.master.customers');
        });
        Route::get('/products', function () {
            return view('wms.master.products');
        });
    });

    // Admin
    Route::prefix('admin')->group(function () {
        Route::get('/users', function () {
            return view('wms.admin.users');
        });
        Route::get('/sequence', function () {
            return view('wms.admin.sequence');
        });
    });

    // OUTBOUND
    Route::get('/outbound/approval', [\App\Http\Controllers\Wms\OutboundController::class, 'approval']);
    Route::post('/outbound/approve/{id}', [\App\Http\Controllers\Wms\OutboundController::class, 'approveOrder']);
    
    Route::get('/outbound/picking', [\App\Http\Controllers\Wms\OutboundController::class, 'picking']);
    Route::post('/outbound/complete-picking/{id}', [\App\Http\Controllers\Wms\OutboundController::class, 'completePicking']);
    
    Route::get('/outbound/delivery', [\App\Http\Controllers\Wms\OutboundController::class, 'delivery']);
    Route::post('/outbound/generate-sj/{id}', [\App\Http\Controllers\Wms\OutboundController::class, 'generateSuratJalan']);
    
    Route::get('/outbound/verification', [\App\Http\Controllers\Wms\OutboundController::class, 'verification']);
    Route::post('/outbound/verify-bukti/{id}', [\App\Http\Controllers\Wms\OutboundController::class, 'verifyBukti']);

    // BILLING
    Route::get('/billing', [\App\Http\Controllers\Wms\BillingController::class, 'index']);
    Route::post('/billing/confirm/{id}', [\App\Http\Controllers\Wms\BillingController::class, 'confirm']);
});

// E-POD (Public Link for Drivers)
Route::get('/epod/{po_number}', function ($po_number) {
    return view('driver.epod', compact('po_number'));
});
Route::post('/epod/{po_number}/confirm', function ($po_number) {
    return redirect()->back()->with('success', 'Barang berhasil dikonfirmasi sampai di tujuan. Terima kasih!');
});