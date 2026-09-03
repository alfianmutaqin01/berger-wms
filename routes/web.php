<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EpodController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Wms\AdminController;
use App\Http\Controllers\Wms\BillingController;
use App\Http\Controllers\Wms\CustomerController;
use App\Http\Controllers\Wms\DashboardController;
use App\Http\Controllers\Wms\DeliveryController;
use App\Http\Controllers\Wms\ImportController;
use App\Http\Controllers\Wms\InboundController;
use App\Http\Controllers\Wms\InventoryController;
use App\Http\Controllers\Wms\LocationController;
use App\Http\Controllers\Wms\NotificationController;
use App\Http\Controllers\Wms\OrderApprovalController;
use App\Http\Controllers\Wms\OutboundController;
use App\Http\Controllers\Wms\PickingController;
use App\Http\Controllers\Wms\ProductController;
use App\Http\Controllers\Wms\ProfileController;
use App\Http\Controllers\Wms\ReportController;
use App\Http\Controllers\Wms\StockTransferController;
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

    /*
    | Pencarian sambil mengetik untuk form Buat Pesanan.
    |
    | Customer dan produk berjumlah ribuan, jadi keduanya TIDAK ikut dikirim
    | bersama halaman: Sales di lapangan memakai HP dan tidak mungkin
    | menggulir ribuan baris. Kedua endpoint ini menuntut minimal 2 huruf dan
    | membatasi hasilnya, sehingga kolom kosong tidak pernah menumpahkan
    | seluruh isi tabel.
    */
    Route::get('/lookup/customers', [SalesOrderController::class, 'lookupCustomers']);
    Route::get('/lookup/products', [SalesOrderController::class, 'lookupProducts']);

    /*
    | Pesanan milik Sales sendiri. Kepemilikan diperiksa di controller
    | (pastikanMilikSendiri) dan menjawab 404 untuk pesanan orang lain —
    | menjawab 403 justru membocorkan bahwa nomor pesanan itu ada.
    |
    | Ubah/hapus HANYA berlaku untuk draft (F-OUT-01 #7); begitu disubmit,
    | pesanan sudah masuk antrean Logistik dan tidak boleh berubah.
    */
    Route::get('/orders/{order}', [SalesOrderController::class, 'show']);
    Route::get('/orders/{order}/document', [SalesOrderController::class, 'document']);
    Route::get('/orders/{order}/edit', [SalesOrderController::class, 'edit']);
    Route::put('/orders/{order}', [SalesOrderController::class, 'update']);
    Route::delete('/orders/{order}', [SalesOrderController::class, 'destroy']);
    Route::post('/orders/{order}/submit', [SalesOrderController::class, 'submit']);

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

        // Put-away (PRD §6.3 F-INB-02) — sudah terhubung ke database.
        // Operator menempatkan tiap palet ke bin dan berwenang mengoreksi
        // Qty Aktual; SKU & batch dikunci karena berasal dari dokumen produksi.
        Route::middleware('can:'.Permission::INBOUND_PUTAWAY)->group(function () {
            Route::get('/putaway', [InboundController::class, 'putawayIndex'])->name('wms.inbound.putaway');
            Route::get('/putaway/{doc_no}', [InboundController::class, 'putawayProcess'])
                ->name('wms.inbound.putaway.process');
            Route::post('/putaway/{doc_no}', [InboundController::class, 'putawayStore'])
                ->name('wms.inbound.putaway.store');
        });

        // Verifikasi Maker-Checker (PRD §6.3 F-INB-03) — sudah terhubung ke
        // database. Logistik adalah CHECKER: boleh mengoreksi qty & lokasi
        // hasil put-away, tapi tidak SKU/batch. Palet yang sudah terverifikasi
        // terkunci di sini — koreksinya lewat Menu Stok (F-INB-04).
        Route::middleware('can:'.Permission::INBOUND_VERIFY)->group(function () {
            Route::get('/verify', [InboundController::class, 'verifyIndex'])->name('wms.inbound.verify');
            Route::get('/verify/{doc_no}', [InboundController::class, 'verifyProcess'])
                ->name('wms.inbound.verify.process');
            Route::post('/verify/{doc_no}', [InboundController::class, 'verifyStore'])
                ->name('wms.inbound.verify.store');
        });

        Route::middleware('can:'.Permission::INBOUND_RETURNS)->group(function () {
            Route::get('/returns', [InboundController::class, 'returnsIndex']);
            Route::post('/returns/{id}', [InboundController::class, 'processReturn']);
        });
    });

    // Produksi & Operator boleh MELIHAT stok, tapi tidak mengubahnya —
    // karena itu adjust/transfer dipagari gate yang berbeda dari index.
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('can:'.Permission::INVENTORY_VIEW)
        ->name('wms.inventory.index');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])
        ->middleware('can:'.Permission::INVENTORY_ADJUST);
    // Menambah baris stok yang belum pernah tercatat — gate yang SAMA dengan
    // adjust (Manager & Super Admin), karena keduanya sama-sama menciptakan
    // angka stok tanpa dokumen inbound di belakangnya.
    Route::post('/inventory/stocks', [InventoryController::class, 'store'])
        ->middleware('can:'.Permission::INVENTORY_ADJUST)
        ->name('wms.inventory.store');
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer'])
        ->middleware('can:'.Permission::INVENTORY_TRANSFER);

    // Impor Stok Awal — mengisi gudang yang sudah berjalan ke sistem baru.
    // Memakai kerangka impor yang sama dengan Master Produk/Pelanggan.
    Route::post('/inventory/import/preview', [ImportController::class, 'preview'])
        ->defaults('type', 'opening-stock')->middleware('can:'.Permission::INVENTORY_ADJUST)
        ->name('wms.inventory.import.preview');
    Route::post('/inventory/import', [ImportController::class, 'store'])
        ->defaults('type', 'opening-stock')->middleware('can:'.Permission::INVENTORY_ADJUST)
        ->name('wms.inventory.import');
    Route::post('/inventory/import/cancel', [ImportController::class, 'cancel'])
        ->defaults('type', 'opening-stock')->middleware('can:'.Permission::INVENTORY_ADJUST)
        ->name('wms.inventory.import.cancel');

    // Transfer antar gudang (F-INV-05). Rutenya ditaruh SEBELUM
    // /transfers/{transfer} tidak diperlukan di sini karena "create" bukan
    // angka dan binding-nya memakai id — tetapi urutannya tetap dijaga agar
    // tidak jadi jebakan saat kelak nomor transfer dipakai sebagai kunci URL.
    Route::prefix('transfers')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])
            ->middleware('can:'.Permission::TRANSFER_HISTORY)
            ->name('wms.transfers.index');
        Route::get('/create', [StockTransferController::class, 'create'])
            ->middleware('can:'.Permission::TRANSFER_SEND)
            ->name('wms.transfers.create');
        Route::post('/', [StockTransferController::class, 'store'])
            ->middleware('can:'.Permission::TRANSFER_SEND)
            ->name('wms.transfers.store');
        Route::get('/{transfer}', [StockTransferController::class, 'show'])
            ->middleware('can:'.Permission::TRANSFER_HISTORY)
            ->name('wms.transfers.show');
        // Menerima dipagari gate TERSENDIRI: ia yang memutuskan angka stok
        // final di gudang tujuan, setara Verifikasi Logistik pada inbound.
        Route::get('/{transfer}/receive', [StockTransferController::class, 'receiveForm'])
            ->middleware('can:'.Permission::TRANSFER_RECEIVE)
            ->name('wms.transfers.receive.form');
        Route::post('/{transfer}/receive', [StockTransferController::class, 'receive'])
            ->middleware('can:'.Permission::TRANSFER_RECEIVE)
            ->name('wms.transfers.receive');
        Route::post('/{transfer}/cancel', [StockTransferController::class, 'cancel'])
            ->middleware('can:'.Permission::TRANSFER_SEND)
            ->name('wms.transfers.cancel');
    });

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
        // PENERIMAAN PESANAN (Fase 6 tahap 1). URUTAN PENTING: '/approval/history'
        // harus didaftarkan SEBELUM '/approval/{order}', kalau tidak kata
        // "history" akan tertangkap sebagai id pesanan dan halamannya 404.
        Route::middleware('can:'.Permission::OUTBOUND_APPROVAL)->group(function () {
            Route::get('/approval', [OrderApprovalController::class, 'index'])
                ->name('wms.approval.index');
            Route::get('/approval/history', [OrderApprovalController::class, 'history'])
                ->name('wms.approval.history');
            Route::get('/approval/{order}', [OrderApprovalController::class, 'show'])
                ->name('wms.approval.show');
            Route::get('/approval/{order}/document', [OrderApprovalController::class, 'document'])
                ->name('wms.approval.document');
            Route::post('/approval/{order}/resolve', [OrderApprovalController::class, 'resolve'])
                ->name('wms.approval.resolve');
            Route::post('/approval/{order}/accept', [OrderApprovalController::class, 'accept'])
                ->name('wms.approval.accept');
            Route::post('/approval/{order}/reject', [OrderApprovalController::class, 'reject'])
                ->name('wms.approval.reject');
            // Memeriksa nomor SO sambil diketik, sebelum Terima ditekan —
            // pada pesanan bermetode dokumen, ditolak setelah menekan Terima
            // berarti seluruh tempelan dari BC harus diulang.
            Route::post('/approval/{order}/check-so', [OrderApprovalController::class, 'checkSoNumber'])
                ->name('wms.approval.check-so');
            // Pembatalan pesanan yang SUDAH diterima: customer batal, atau BC
            // tidak menyetujui. Nomor SO-nya kembali bisa dipakai.
            Route::post('/approval/{order}/cancel', [OrderApprovalController::class, 'cancel'])
                ->name('wms.approval.cancel');
        });

        // PICKING (Fase 6 tahap 3). Dua kelompok untuk dua orang: Logistik
        // MENYUSUN daftar, Operator MENGERJAKANNYA. URUTAN PENTING:
        // '/picking/batching' dan '/picking/queue' harus didaftarkan SEBELUM
        // '/picking/{list}', kalau tidak keduanya tertangkap sebagai id.
        Route::middleware('can:'.Permission::OUTBOUND_PICKING_LIST)->group(function () {
            Route::get('/picking/batching', [PickingController::class, 'batching'])
                ->name('wms.picking.batching');
            Route::post('/picking/list', [PickingController::class, 'store'])
                ->name('wms.picking.store');
            Route::post('/picking/list/{list}/cancel', [PickingController::class, 'cancel'])
                ->name('wms.picking.cancel');
        });

        Route::middleware('can:'.Permission::OUTBOUND_PICKING_PROCESS)->group(function () {
            Route::get('/picking', [PickingController::class, 'queue'])
                ->name('wms.picking.queue');
            Route::post('/picking/list/{list}/claim', [PickingController::class, 'claim'])
                ->name('wms.picking.claim');
            Route::post('/picking/list/{list}/item/{item}/pick', [PickingController::class, 'pick'])
                ->name('wms.picking.item.pick');
            Route::post('/picking/list/{list}/item/{item}/short', [PickingController::class, 'short'])
                ->name('wms.picking.item.short');
            Route::post('/picking/list/{list}/item/{item}/reset', [PickingController::class, 'reset'])
                ->name('wms.picking.item.reset');
            Route::post('/picking/list/{list}/complete', [PickingController::class, 'complete'])
                ->name('wms.picking.complete');
        });

        // Rincian daftar dibaca KEDUA peran: Logistik memeriksa hasil
        // susunannya, Operator mengerjakannya. Gate-nya "salah satu boleh",
        // bukan salah satunya saja — karena itu fiturnya sendiri, bukan
        // menumpang salah satu dari keduanya.
        Route::get('/picking/list/{list}', [PickingController::class, 'show'])
            ->middleware('can:'.Permission::OUTBOUND_PICKING_VIEW)
            ->name('wms.picking.show');

        // SURAT JALAN (Fase 6 tahap 4). TIDAK ada rute "cetak": dokumen
        // resminya terbit di sistem BC, dan yang dikerjakan di sini adalah
        // menyalin lalu mencocokkannya.
        Route::middleware('can:'.Permission::OUTBOUND_DELIVERY)->group(function () {
            Route::get('/delivery', [DeliveryController::class, 'index'])
                ->name('wms.delivery.index');

            Route::post('/delivery/import/preview', [ImportController::class, 'preview'])
                ->defaults('type', 'delivery-notes')
                ->name('wms.delivery.import.preview');
            Route::post('/delivery/import', [ImportController::class, 'store'])
                ->defaults('type', 'delivery-notes')
                ->name('wms.delivery.import');
            Route::post('/delivery/import/cancel', [ImportController::class, 'cancel'])
                ->defaults('type', 'delivery-notes')
                ->name('wms.delivery.import.cancel');

            // URUTAN PENTING: '/delivery/import*' di atas harus didaftarkan
            // SEBELUM '/delivery/{note}', kalau tidak "import" tertangkap
            // sebagai id dokumen.
            Route::get('/delivery/{note}', [DeliveryController::class, 'show'])
                ->name('wms.delivery.show');
            Route::post('/delivery/{note}/ship', [DeliveryController::class, 'ship'])
                ->name('wms.delivery.ship');
            Route::post('/delivery/{note}/resend', [DeliveryController::class, 'resend'])
                ->name('wms.delivery.resend');
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

/*
| E-POD — konfirmasi penerimaan oleh supir (F-OUT-04 #10).
|
| PUBLIK, DI LUAR SELURUH MIDDLEWARE. Supir tidak punya akun dan tidak akan
| pernah punya: ia berganti setiap hari dan sebagian besar dari perusahaan
| jasa lain. Yang menjadi kunci adalah TOKEN di dalam alamatnya — acak, 48
| karakter, disimpan sebagai kolom. Parameternya dulu bernama {po_number},
| yang berarti siapa pun yang tahu (atau menebak) nomor PO bisa menyatakan
| kiriman orang lain sudah sampai.
|
| Dibatasi kecepatan aksesnya: halaman ini terbuka ke internet, dan token
| tidak boleh bisa dicari dengan mencoba satu per satu.
*/
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/epod/{token}', [EpodController::class, 'show'])->name('epod.show');
    Route::post('/epod/{token}/confirm', [EpodController::class, 'confirm'])->name('epod.confirm');
});
