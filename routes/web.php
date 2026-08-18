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
    Route::get('/my-orders', function () {
        return view('sales.dashboard');
    });
    Route::get('/new-order', function () {
        return view('sales.new_order');
    });
    Route::get('/tracking', function () {
        return view('sales.tracking');
    });
});

// WMS PORTAL ROUTES
Route::prefix('wms')->group(function () {
    Route::get('/dashboard', function () {
        return view('wms.dashboard');
    });
    Route::prefix('inbound')->group(function () {
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
    });
});

