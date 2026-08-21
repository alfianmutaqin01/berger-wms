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
    Route::post('/report-return', function (Illuminate\Http\Request $request) {
        $retur = [
            'id' => 'RET-' . rand(1000, 9999),
            'po' => $request->po_number,
            'customer' => $request->customer,
            'sku' => $request->sku,
            'qty' => $request->qty,
            'reason' => $request->reason,
            'time' => now()->format('H:i') . ' WIB',
            'date' => 'Hari Ini'
        ];
        // Simpan ke session untuk dibaca oleh Inbound WMS
        session()->push('pending_returns', $retur);
        
        // Simpan penanda bahwa PO ini ada returnya (untuk verifikasi SJ)
        session()->put('po_has_return_' . $request->po_number, true);
        
        return redirect()->back()->with('success', 'Laporan Retur Kendala untuk ' . $request->po_number . ' telah dikirim ke Gudang.');
    });
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
    
    // Notifications
    Route::get('/notifications', function () {
        return view('wms.notifications');
    });
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
        
        // Retur
        Route::get('/returns', function () {
            $pendingReturns = session('pending_returns', []);
            return view('wms.inbound.returns', compact('pendingReturns'));
        });
        Route::post('/returns/{id}', function ($id, Illuminate\Http\Request $request) {
            $pending = session('pending_returns', []);
            $newPending = [];
            
            foreach ($pending as $retur) {
                if ($retur['id'] !== $id) {
                    $newPending[] = $retur;
                }
            }
            session(['pending_returns' => $newPending]);
            
            // Simpan hasil verifikasi (GR/DDP)
            session()->put('processed_return_' . $id, $request->alokasi);
            
            return redirect()->back()->with('success', 'Barang retur berhasil dialokasikan ke ' . $request->alokasi . '.');
        });
    });

    // Inventory
    Route::get('/inventory', [\App\Http\Controllers\Wms\InventoryController::class, 'index']);
    Route::post('/inventory/adjust', [\App\Http\Controllers\Wms\InventoryController::class, 'adjust']);
    Route::post('/inventory/transfer', [\App\Http\Controllers\Wms\InventoryController::class, 'transfer']);
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

    // Akun & Pengaturan
    Route::get('/profile', function () {
        return view('wms.profile');
    });
    Route::post('/profile/password', function () {
        return redirect()->back()->with('success', 'Kata sandi berhasil diperbarui.');
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