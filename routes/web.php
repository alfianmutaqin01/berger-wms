<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EpodController;
use App\Http\Controllers\MrfApprovalController;
use App\Http\Controllers\MrfRequestLinkController;
use App\Http\Controllers\Sales\DashboardController as SalesDashboardController;
use App\Http\Controllers\Sales\DeliveryProofController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Wms\ActivityLogController;
use App\Http\Controllers\Wms\AdminController;
use App\Http\Controllers\Wms\BillingController;
use App\Http\Controllers\Wms\BookingController;
use App\Http\Controllers\Wms\CustomerController;
use App\Http\Controllers\Wms\CustomerRejectionController;
use App\Http\Controllers\Wms\DashboardController;
use App\Http\Controllers\Wms\DeliveryController;
use App\Http\Controllers\Wms\ImportController;
use App\Http\Controllers\Wms\InboundController;
use App\Http\Controllers\Wms\InternalOrderController;
use App\Http\Controllers\Wms\InventoryController;
use App\Http\Controllers\Wms\LocationController;
use App\Http\Controllers\Wms\MaterialRequisitionController;
use App\Http\Controllers\Wms\NotificationController;
use App\Http\Controllers\Wms\OrderApprovalController;
use App\Http\Controllers\Wms\OutstandingController;
use App\Http\Controllers\Wms\PalletCapacityController;
use App\Http\Controllers\Wms\PickingController;
use App\Http\Controllers\Wms\ProductController;
use App\Http\Controllers\Wms\ProductionMaterialController;
use App\Http\Controllers\Wms\ProfileController;
use App\Http\Controllers\Wms\ProofVerificationController;
use App\Http\Controllers\Wms\ReportController;
use App\Http\Controllers\Wms\StockLedgerController;
use App\Http\Controllers\Wms\StockTakeController;
use App\Http\Controllers\Wms\StockTransferController;
use App\Http\Controllers\Wms\UserController;
use App\Support\Detak;
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
| Verifikasi Anti-Bot (F-AUTH-02, reCAPTCHA) menyatu di POST /login yang sama,
| bukan rute terpisah. Lihat catatan di AuthController.
*/
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
| Dipanggil oleh health check pada pipeline deploy (.github/workflows/deploy.yml)
| dan oleh pemantau luar (mis. UptimeRobot). Lihat docs/9_panduan_go_live.md.
|
| Mengembalikan 200 bila seluruh dependensi sehat, 503 bila ada yang gagal —
| sehingga `curl -f` pada pipeline otomatis menggagalkan deploy yang bermasalah.
|
| penjadwal & antrean: null berarti BELUM PERNAH berdetak (beberapa menit
| pertama setelah pemasangan) dan tidak menggagalkan; false berarti detaknya
| basi — prosesnya berhenti. Lihat App\Support\Detak.
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
    $checks['penjadwal'] = Detak::segar(Detak::PENJADWAL);
    $checks['antrean'] = Detak::segar(Detak::ANTREAN);

    $allHealthy = ! in_array(false, $checks, true);

    return response()->json([
        'status' => $allHealthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => now()->toISOString(),
    ], $allHealthy ? 200 : 503);
})->name('health');

/*
|--------------------------------------------------------------------------
| Profil sendiri — MILIK SEMUA ROLE, lintas portal
|--------------------------------------------------------------------------
|
| Sengaja DI LUAR prefix /wms. Sebelumnya profil hanya ada di Portal WMS,
| sehingga Tim Sales — satu-satunya role yang dipagari keluar dari portal itu
| oleh middleware `portal:wms` — tidak punya cara mengganti kata sandinya
| sendiri maupun melihat perangkat mana saja yang sedang memakai akunnya.
| Justru merekalah yang paling sering berpindah perangkat.
|
| Mengganti sandi dan mengusir perangkat asing bukan fitur gudang; itu milik
| akun, dan setiap akun berhak atasnya. Karena itu di sini hanya ada `auth`
| dan `session.track`, tanpa `portal:` maupun `can:`.
*/
Route::middleware(['auth', 'session.track'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])
        ->name('profile.password');
    Route::post('/profile/sessions/revoke-others', [ProfileController::class, 'revokeOtherSessions'])
        ->name('profile.sessions.revoke-others');
    Route::delete('/profile/sessions/{session}', [ProfileController::class, 'revokeSession'])
        ->name('profile.sessions.revoke');

    /*
     * Lonceng notifikasi — DI SINI, dengan alasan yang persis sama dengan
     * profil di atas. Sebelumnya rutenya di dalam prefix /wms, sehingga Tim
     * Sales tidak akan pernah bisa membuka loncengnya sendiri — padahal
     * merekalah yang paling butuh diberi tahu pesanannya sudah disetujui atau
     * ditolak.
     *
     * Tidak ada `can:` di sini dan itu disengaja: yang menentukan siapa
     * menerima apa sudah diputuskan App\Support\Notifier saat mengirimnya.
     * Menambahkan gate di sini justru memblokir orang dari suratnya sendiri.
     */
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('wms.notifications.index');
    Route::get('/notifications/{notification}', [NotificationController::class, 'open'])
        ->name('wms.notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('wms.notifications.read-all');
});

// SALES PORTAL ROUTES
Route::prefix('sales')->middleware(['auth', 'session.track', 'portal:sales'])->group(function () {
    Route::get('/dashboard', [SalesDashboardController::class, 'index'])
        ->name('sales.dashboard');
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

    /*
    | Bukti Surat Jalan bertanda tangan (F-OUT-05). Dikerjakan Sales dari HP
    | di depan toko, jadi formulirnya menempel di halaman detail pesanan —
    | tidak ada halaman unggah tersendiri.
    */
    Route::post('/orders/{order}/proofs', [DeliveryProofController::class, 'store'])
        ->name('sales.proofs.store');
    Route::get('/proofs/{proof}', [DeliveryProofController::class, 'preview'])
        ->name('sales.proofs.preview');
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
    // Keduanya kini tinggal di luar prefix ini supaya Tim Sales ikut
    // kebagian; alamat lamanya dipertahankan sebagai pengalihan karena sudah
    // tersebar di bookmark dan tautan lama.
    Route::get('/notifications', fn () => redirect()->route('wms.notifications.index'));
    // Profil PINDAH ke /profile supaya Tim Sales ikut kebagian (lihat blok
    // di atas grup ini). Alamat lama dipertahankan sebagai pengalihan: ia
    // sudah tersebar di bookmark dan tautan lama.
    Route::get('/profile', fn () => redirect()->route('profile'));

    Route::prefix('inbound')->group(function () {
        Route::middleware('can:'.Permission::INBOUND_HISTORY)->group(function () {
            Route::get('/history', [InboundController::class, 'historyIndex'])->name('wms.inbound.history');
            // Diberi nama karena lonceng selisih qty menautkan ke sini.
            Route::get('/history/{doc_no}', [InboundController::class, 'historyDetail'])
                ->name('wms.inbound.history.detail');
        });

        /*
        | Tim Produksi menyesuaikan qty dokumennya ke hitungan fisik Operator.
        |
        | Dipagari INBOUND_CREATE, bukan INBOUND_HISTORY: yang boleh membetulkan
        | angka adalah yang berwenang menulisnya sejak awal. Manager memegang
        | INBOUND_HISTORY dan bisa MELIHAT selisihnya — itu memang tugasnya —
        | tetapi membetulkan berkas produksi bukan wewenangnya.
        |
        | Selisihnya TIDAK hilang dari layar verifikasi Logistik setelah
        | disesuaikan; lihat InboundDetail::scopeBerselisih().
        */
        Route::post('/history/{doc_no}/adjust-qty', [InboundController::class, 'adjustQty'])
            ->middleware('can:'.Permission::INBOUND_CREATE)
            ->name('wms.inbound.history.adjust');

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

        /*
        | PENOLAKAN CUSTOMER (Fase 7).
        |
        | Daftar dan detailnya dibuka SEMUA yang terlibat — termasuk Operator,
        | yang perlu tahu ada barang menunggu dinaikkan tanpa harus ditelepon.
        | Yang dipagari berbeda adalah tindakannya: menyetujui klaim dan
        | memverifikasi barang milik Logistik, menaikkan ke rak milik Operator.
        | Memisahkannya di sinilah yang membuat orang yang menaikkan barang
        | tidak sekaligus mengesahkan hasil kerjanya sendiri.
        */
        Route::middleware('can:'.Permission::RETURN_VIEW)->group(function () {
            Route::get('/returns', [CustomerRejectionController::class, 'index'])
                ->name('wms.returns.index');
            Route::get('/returns/{retur}', [CustomerRejectionController::class, 'show'])
                ->name('wms.returns.show');
        });

        Route::middleware('can:'.Permission::RETURN_APPROVE)->group(function () {
            Route::post('/returns/{retur}/approve', [CustomerRejectionController::class, 'approve'])
                ->name('wms.returns.approve');
            Route::post('/returns/{retur}/reject', [CustomerRejectionController::class, 'reject'])
                ->name('wms.returns.reject');
            Route::post('/returns/{retur}/verify', [CustomerRejectionController::class, 'verify'])
                ->name('wms.returns.verify');
        });

        Route::post('/returns/detail/{detail}/putaway', [CustomerRejectionController::class, 'putaway'])
            ->middleware('can:'.Permission::RETURN_PUTAWAY)
            ->name('wms.returns.putaway');
    });

    // Produksi & Operator boleh MELIHAT stok, tapi tidak mengubahnya —
    // karena itu adjust/transfer dipagari gate yang berbeda dari index.
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('can:'.Permission::INVENTORY_VIEW)
        ->name('wms.inventory.index');

    /*
    | ITEM LEDGER — buku besar mutasi, hanya baca.
    |
    | Gate-nya SENDIRI, bukan INVENTORY_VIEW. Data Stok menjawab "berapa
    | sisanya sekarang" dan memang dipakai Produksi serta Operator tiap hari;
    | item ledger menjawab "bagaimana ia sampai ke angka itu", bahan
    | rekonsiliasi yang dikerjakan Logistik dan Manager.
    |
    | Didaftarkan SEBELUM rute /inventory ber-parameter apa pun kelak, dengan
    | alasan yang sama seperti di tempat lain: "item-ledger" bukan angka, tetapi
    | urutannya dijaga supaya tidak pernah menjadi jebakan.
    */
    Route::get('/inventory/item-ledger', [StockLedgerController::class, 'index'])
        ->middleware('can:'.Permission::INVENTORY_LEDGER)
        ->name('wms.inventory.item-ledger');
    // TIDAK ADA rute unduhan tersendiri di sini. Tombol Export Excel pada
    // halaman Data Stok mengarah ke pratinjau laporan Posisi Stok /
    // Pergerakan Stok yang sudah ada — alur, tampilan, batas baris, dan
    // pencatatan log-nya jadi persis sama dengan menu Laporan, dan tidak ada
    // definisi "stok" kedua yang suatu hari menyimpang dari yang pertama.
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

    // Penanda batch (Karantina, Quality Issue, Dahulukan Keluar) —
    // permintaan pemilik produk. Gate TERPISAH
    // dari INVENTORY_ADJUST: ini wewenang Logistik sehari-hari (hasil
    // pemeriksaan QC), bukan koreksi qty yang perlu naik ke Manager.
    Route::middleware('can:'.Permission::INVENTORY_QUARANTINE)->group(function () {
        Route::post('/inventory/quarantine', [InventoryController::class, 'quarantine'])
            ->name('wms.inventory.quarantine');
        Route::post('/inventory/quarantine/{stock}/release', [InventoryController::class, 'releaseQuarantine'])
            ->name('wms.inventory.quarantine.release');
        Route::post('/inventory/{stock}/quality-issue', [InventoryController::class, 'toggleQualityIssue'])
            ->name('wms.inventory.quality-issue');
        Route::post('/inventory/prioritize', [InventoryController::class, 'prioritize'])
            ->name('wms.inventory.prioritize');
        Route::post('/inventory/{stock}/prioritize/release', [InventoryController::class, 'releasePriority'])
            ->name('wms.inventory.prioritize.release');
    });

    /*
    | STOK OPNAME (permintaan pemilik produk). DUA GATE, sengaja berbeda:
    |
    |   stocktake.count  — memasukkan hasil hitungan fisik. Terbuka sampai
    |                      Operator Gudang, karena merekalah yang berdiri di
    |                      depan rak. Tidak mengubah satu pun angka stok.
    |   stocktake.manage — membuka sesi dan MENGESAHKAN laporannya. Pengesahan
    |                      itulah yang menggeser stok, kadang ribuan unit
    |                      sekaligus, jadi ia tidak boleh berada di tangan yang
    |                      sama dengan yang menghitung.
    |
    | URUTAN PENTING: '/stocktake/items/...' didaftarkan sebelum
    | '/stocktake/{stocktake}', kalau tidak "items" tertangkap sebagai id sesi.
    */
    Route::middleware('can:'.Permission::STOCKTAKE_COUNT)->group(function () {
        Route::get('/stocktake', [StockTakeController::class, 'index'])
            ->name('wms.stocktake.index');
        Route::post('/stocktake/items/{item}/count', [StockTakeController::class, 'count'])
            ->name('wms.stocktake.count');
        /*
        | Barang yang DITEMUKAN di rak tetapi tidak ada di sistem.
        |
        | Izinnya sama dengan mengisi hitungan biasa, dan itu disengaja:
        | menghitung 50 pada baris yang sistemnya 0 sudah melakukan hal yang
        | persis sama sejak awal. Keduanya baru menyentuh stok saat laporannya
        | disahkan Manager.
        |
        | Didaftarkan SEBELUM '/stocktake/{stocktake}' seperti tetangganya di
        | atas — kalau tidak, "lookup" tertangkap sebagai id sesi.
        */
        Route::get('/stocktake/lookup/products', [StockTakeController::class, 'lookupProducts'])
            ->name('wms.stocktake.lookup.products');
        Route::post('/stocktake/{stocktake}/found', [StockTakeController::class, 'found'])
            ->name('wms.stocktake.found');
        Route::get('/stocktake/{stocktake}', [StockTakeController::class, 'show'])
            ->name('wms.stocktake.show');
        Route::get('/stocktake/{stocktake}/report', [StockTakeController::class, 'report'])
            ->name('wms.stocktake.report');
        Route::get('/stocktake/{stocktake}/report/excel', [StockTakeController::class, 'download'])
            ->name('wms.stocktake.report.download');
    });

    Route::middleware('can:'.Permission::STOCKTAKE_MANAGE)->group(function () {
        Route::post('/stocktake', [StockTakeController::class, 'store'])
            ->name('wms.stocktake.store');
        Route::post('/stocktake/{stocktake}/finalize', [StockTakeController::class, 'finalize'])
            ->name('wms.stocktake.finalize');
        Route::post('/stocktake/{stocktake}/cancel', [StockTakeController::class, 'cancel'])
            ->name('wms.stocktake.cancel');
    });

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

    // Laporan & Ekspor (Fase 11 tahap 4). REPORTS_VIEW menjaga pintunya;
    // izin per laporan diperiksa lagi di dalam ReportController karena
    // kunci laporannya datang dari URL, bukan dari daftar rute.
    Route::middleware('can:'.Permission::REPORTS_VIEW)->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])
            ->name('wms.reports.index');

        // Unduhan didaftarkan LEBIH DULU. Kalau '/reports/{key}' menang
        // duluan, '/reports/finish-order/unduh' tidak akan pernah
        // tercapai — dan yang menekan tombol unduh hanya melihat halaman
        // pratinjau terbuka lagi tanpa penjelasan apa pun.
        Route::get('/reports/{key}/unduh', [ReportController::class, 'download'])
            ->name('wms.reports.download');

        Route::get('/reports/{key}', [ReportController::class, 'show'])
            ->name('wms.reports.show');
    });

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
            // Isi satu titik rak, diambil saat kotaknya diklik di denah.
            // Terpisah dari halamannya supaya denah berisi ~2.264 kotak tidak
            // perlu membawa rincian batch yang 99% tidak pernah dibuka.
            Route::get('/locations/{location}/contents', [LocationController::class, 'contents'])
                ->name('wms.locations.contents');
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

        // BACA-SAJA, dan itu keputusan rancangan. Prefix maupun nomor urut
        // TIDAK bisa diubah dari layar: mengganti prefix memecah riwayat jadi
        // dua bentuk yang tidak bisa dicari sekaligus, dan menggeser nomor
        // mundur menghasilkan nomor kembar yang menghentikan pembuatan
        // pesanan untuk semua orang. Tidak ada rute POST di sini.
        Route::get('/sequence', [AdminController::class, 'sequence'])
            ->middleware('can:'.Permission::ADMIN_SEQUENCE)
            ->name('wms.admin.sequence');

        // Pengaturan Sistem — SUPER ADMIN SAJA. Setelan di sini berlaku untuk
        // seluruh perusahaan, sementara kewenangan Manager dibatasi ke
        // gudangnya sendiri (lihat Permission::ADMIN_SETTINGS).
        Route::middleware('can:'.Permission::ADMIN_SETTINGS)->group(function () {
            Route::get('/settings', [AdminController::class, 'settings'])
                ->name('wms.admin.settings');
            Route::post('/settings', [AdminController::class, 'updateSettings'])
                ->name('wms.admin.settings.update');

            // Tautan permintaan material per divisi. Menumpang halaman
            // Pengaturan, bukan menu sendiri: diatur sekali lalu nyaris tidak
            // disentuh lagi, sama sifatnya dengan setelan di sana.
            Route::post('/settings/tautan-mrf', [AdminController::class, 'storeMrfLink'])
                ->name('wms.admin.mrf-link.store');
            Route::put('/settings/tautan-mrf/{link}', [AdminController::class, 'updateMrfLink'])
                ->name('wms.admin.mrf-link.update');

            // Kapasitas palet: berapa muat di satu palet, menurut ukurannya.
            // Halaman sendiri karena bentuknya DAFTAR yang bisa bertambah,
            // bukan angka tunggal seperti setelan lain — memaksanya masuk ke
            // formulir setelan berarti satu formulir yang isiannya berubah
            // jumlah tiap kali ada ukuran baru.
            Route::get('/pallet-capacity', [PalletCapacityController::class, 'index'])
                ->name('wms.admin.pallet-capacity');
            Route::post('/pallet-capacity', [PalletCapacityController::class, 'store'])
                ->name('wms.admin.pallet-capacity.store');
            Route::put('/pallet-capacity/{rule}', [PalletCapacityController::class, 'update'])
                ->name('wms.admin.pallet-capacity.update');
            Route::delete('/pallet-capacity/{rule}', [PalletCapacityController::class, 'destroy'])
                ->name('wms.admin.pallet-capacity.destroy');
        });

        // Log aktivitas — SUPER ADMIN SAJA, dan HANYA BACA. Tidak ada rute
        // tulis di sini bukan karena belum dibuat: log yang bisa disunting
        // oleh orang yang tercatat di dalamnya bukan log.
        // Unduhan didaftarkan SEBELUM halamannya supaya tetap terbaca
        // berpasangan; keduanya di balik gate yang sama, karena isi berkasnya
        // persis isi layarnya.
        Route::get('/activity-log/unduh', [ActivityLogController::class, 'download'])
            ->middleware('can:'.Permission::ADMIN_AUDIT)
            ->name('wms.admin.activity-log.unduh');

        Route::get('/activity-log', [ActivityLogController::class, 'index'])
            ->middleware('can:'.Permission::ADMIN_AUDIT)
            ->name('wms.admin.activity-log');
    });

    // OUTBOUND — proses picking di tangan Operator; sisanya alur Logistik.
    Route::prefix('outbound')->group(function () {
        /*
        | BUAT PESANAN JALUR INTERNAL — Admin & Manager saja.
        |
        | Portal Sales tetap tertutup rapat untuk peran Warehouse/Admin
        | (PRD §5.2, ditegakkan middleware portal:sales). Ini BUKAN celah ke
        | portal itu melainkan pintu terpisah di sisi WMS: pesanannya tetap
        | tercatat MILIK seorang Sales, dan siapa yang mengetiknya disimpan
        | terpisah di sales_orders.placed_by.
        |
        | Logistik sengaja tidak dapat — merekalah yang menilai pesanan.
        */
        Route::middleware('can:'.Permission::OUTBOUND_ORDER_INTERNAL)->group(function () {
            Route::get('/new-order', [InternalOrderController::class, 'create'])
                ->name('wms.internal-order.create');
            Route::post('/new-order', [InternalOrderController::class, 'store'])
                ->name('wms.internal-order.store');
            Route::get('/new-order/lookup/customers', [InternalOrderController::class, 'lookupCustomers'])
                ->name('wms.internal-order.lookup.customers');
            Route::get('/new-order/lookup/products', [InternalOrderController::class, 'lookupProducts'])
                ->name('wms.internal-order.lookup.products');
        });

        // PENERIMAAN PESANAN (Fase 6 tahap 1). URUTAN PENTING: '/approval/history'
        // harus didaftarkan SEBELUM '/approval/{order}', kalau tidak kata
        // "history" akan tertangkap sebagai id pesanan dan halamannya 404.
        Route::middleware('can:'.Permission::OUTBOUND_APPROVAL)->group(function () {
            Route::get('/approval', [OrderApprovalController::class, 'index'])
                ->name('wms.approval.index');
            Route::get('/approval/history', [OrderApprovalController::class, 'history'])
                ->name('wms.approval.history');
            // Rincian pesanan yang SUDAH dinilai — hanya untuk dibaca.
            // Terpisah dari '/approval/{order}' yang merupakan layar keputusan
            // dan menolak pesanan yang sudah selesai dinilai.
            Route::get('/approval/history/{order}', [OrderApprovalController::class, 'historyShow'])
                ->name('wms.approval.history.show');
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

            // Koreksi nomor SO yang salah ketik (Fase 6 tahap 5). Pintu KECIL:
            // hanya berlaku selama pesanan belum berangkat. Sesudah itu
            // koreksinya lewat wms.delivery.pair, supaya nomornya disalin dari
            // dokumen BC dan bukan diketik ulang.
            Route::post('/approval/{order}/so-number', [OrderApprovalController::class, 'renameSoNumber'])
                ->name('wms.approval.so-number');

            // RIWAYAT OUTSTANDING. Menumpang izin yang sama dengan penerimaan
            // pesanan, bukan izin baru: kekurangan LAHIR dari keputusan
            // penerimaan, dan siapa pun yang berwenang mengambil keputusan itu
            // memang harus bisa melihat akibatnya.
            Route::get('/outstanding', [OutstandingController::class, 'index'])
                ->name('wms.outstanding.index');

            // KIRIM ULANG kekurangan. Gate-nya sama dengan Terima Pesanan
            // (OUTBOUND_APPROVAL) dan itu disengaja: membuka putaran baru
            // mencadangkan stok persis seperti menerima pesanan, jadi
            // wewenangnya harus sebesar itu pula — bukan sekadar wewenang
            // membaca riwayat.
            Route::post('/outstanding/{order}/reship', [OutstandingController::class, 'reship'])
                ->name('wms.outstanding.reship');
        });

        /*
        | BOOKING PRODUK (keputusan pemilik produk). Menahan jatah untuk satu
        | customer SEBELUM pesanannya resmi masuk — kasus nyata di Berger:
        | customer minta jatah dari batch yang belum diproduksi, lalu jatah itu
        | terlupakan dan terjual ke pesanan lain.
        |
        | Wewenang Logistik, bukan Sales: yang ditahan adalah stok gudang, dan
        | tiap unit yang dibooking langsung hilang dari angka yang boleh
        | dijanjikan ke pelanggan lain.
        */
        Route::middleware('can:'.Permission::BOOKING)->group(function () {
            Route::get('/booking', [BookingController::class, 'index'])
                ->name('wms.booking.index');
            Route::post('/booking', [BookingController::class, 'store'])
                ->name('wms.booking.store');
            Route::get('/booking/availability', [BookingController::class, 'availability'])
                ->name('wms.booking.availability');

            // Customer dan produk dicari sambil mengetik, bukan dikirim
            // sebagai dropdown berisi ribuan baris.
            Route::get('/booking/lookup/customers', [BookingController::class, 'lookupCustomers'])
                ->name('wms.booking.lookup.customers');
            Route::get('/booking/lookup/products', [BookingController::class, 'lookupProducts'])
                ->name('wms.booking.lookup.products');
            Route::post('/booking/{booking}/cancel', [BookingController::class, 'cancel'])
                ->name('wms.booking.cancel');
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
        // Unduhan didaftarkan SEBELUM '/picking/list/{list}', kalau tidak
        // "unduh" tertangkap sebagai id daftar. Gate-nya sama dengan rincian:
        // isinya persis isi layar itu, hanya dalam bentuk berkas.
        Route::get('/picking/list/{list}/unduh', [PickingController::class, 'download'])
            ->middleware('can:'.Permission::OUTBOUND_PICKING_VIEW)
            ->name('wms.picking.unduh');

        Route::get('/picking/list/{list}', [PickingController::class, 'show'])
            ->middleware('can:'.Permission::OUTBOUND_PICKING_VIEW)
            ->name('wms.picking.show');

        // MELEPAS TUGAS dipakai KEDUA peran, jadi gate-nya "salah satu boleh"
        // — sama seperti membaca rinciannya. Operator melepas tugasnya
        // sendiri; Logistik/Manager melepas milik siapa pun, dan itu
        // satu-satunya jalan saat operatornya sudah pulang dan daftarnya
        // tertinggal terkunci. Batas siapa-boleh-melepas-milik-siapa
        // ditegakkan di dalam PickingRun::release(), bukan oleh rute ini.
        Route::post('/picking/list/{list}/release', [PickingController::class, 'release'])
            ->middleware('can:'.Permission::OUTBOUND_PICKING_VIEW)
            ->name('wms.picking.release');

        // SURAT JALAN (Fase 6 tahap 4). Dokumen resminya terbit di sistem BC;
        // yang dikerjakan di sini adalah menyalin lalu mencocokkannya.
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

            // Foto bukti sampai yang dijepret supir (Fase 12). Lewat rute
            // berizin, BUKAN dari folder publik: fotonya memperlihatkan
            // alamat dan halaman pelanggan, dan tautan di storage/public bisa
            // dibuka siapa pun yang menebak namanya.
            Route::get('/delivery/{note}/foto-sampai', [DeliveryController::class, 'arrivalPhoto'])
                ->name('wms.delivery.arrival-photo');

            // Memasangkan SJ yatim ke pesanannya sekaligus membetulkan nomor
            // SO yang salah ketik (Fase 6 tahap 5).
            Route::post('/delivery/{note}/pair', [DeliveryController::class, 'pair'])
                ->name('wms.delivery.pair');

            // SKU di Surat Jalan berbeda dari yang dipicking. Pintu TERPISAH
            // dari tombol berangkat: centang yang menempel pada formulir yang
            // sama akan ikut tercentang bersama yang lain.
            Route::post('/delivery/{note}/substitution', [DeliveryController::class, 'confirmSubstitution'])
                ->name('wms.delivery.substitution');
        });

        // VERIFIKASI BUKTI (Fase 6 tahap 5, PRD F-OUT-06).
        Route::middleware('can:'.Permission::OUTBOUND_VERIFICATION)->group(function () {
            Route::get('/verification', [ProofVerificationController::class, 'index'])
                ->name('wms.verification.index');

            // URUTAN PENTING: '/verification/proof/*' harus lebih dulu
            // daripada '/verification/{order}', kalau tidak "proof" tertangkap
            // sebagai id pesanan.
            Route::get('/verification/proof/{proof}', [ProofVerificationController::class, 'preview'])
                ->name('wms.verification.preview');
            Route::get('/verification/proof/{proof}/download', [ProofVerificationController::class, 'download'])
                ->name('wms.verification.download');

            Route::get('/verification/{order}', [ProofVerificationController::class, 'show'])
                ->name('wms.verification.show');
            Route::post('/verification/{order}/complete', [ProofVerificationController::class, 'complete'])
                ->name('wms.verification.complete');
            Route::post('/verification/{order}/reject', [ProofVerificationController::class, 'reject'])
                ->name('wms.verification.reject');
        });
    });

    // BILLING
    Route::middleware('can:'.Permission::BILLING_VIEW)->group(function () {
        Route::get('/billing', [BillingController::class, 'index'])->name('wms.billing.index');

        Route::post('/billing/lunas', [BillingController::class, 'pay'])
            ->middleware('can:'.Permission::BILLING_CONFIRM)
            ->name('wms.billing.pay');

        Route::post('/billing/pembayaran/{payment}/batal', [BillingController::class, 'void'])
            ->middleware('can:'.Permission::BILLING_VOID)
            ->name('wms.billing.void');
    });
});

/*
| MRF — MILIK DUA PORTAL SEKALIGUS, jadi ia di luar grup Portal WMS.
|
| Permintaan material tidak lagi hanya datang dari Produksi: Sales memintanya
| untuk contoh calon pelanggan. Sales dipagari keluar dari Portal WMS oleh
| `portal:wms` (PRD §5.2), sehingga izin MRF-nya tidak akan pernah terpakai
| kalau layarnya berdiri di dalam grup itu — 403 sebelum gate-nya sempat
| dibaca.
|
| Alamatnya tetap /wms/... dan nama rutenya tetap wms.mrf.*: yang berubah
| hanya siapa yang boleh lewat, bukan di mana dokumennya tinggal. Menyalin
| layarnya ke sisi Sales akan berarti dua salinan satu dokumen yang harus
| sepakat selamanya.
|
| Yang DILIHAT tiap divisi dibatasi di controller-nya
| (MaterialRequisition::scopeUntukPembaca), bukan di sini.
*/
Route::prefix('wms')->middleware(['auth', 'session.track', 'portal:wms,sales'])->group(function () {
    /*
    | MRF — permintaan material Produksi ke Logistik.
    |
    | Empat izin yang berbeda dipakai di dalam satu prefix, dan itu memang
    | maksudnya: satu dokumen dikerjakan empat orang yang berlainan. Yang
    | membuat tidak boleh memutus, yang memutus tidak boleh menerima.
    |
    | '/mrf/create' dan '/mrf/lookup/products' WAJIB didaftarkan SEBELUM
    | '/mrf/{mrf}', kalau tidak keduanya tertangkap sebagai id permintaan.
    */
    Route::prefix('mrf')->group(function () {
        Route::get('/', [MaterialRequisitionController::class, 'index'])
            ->middleware('can:'.Permission::MRF_VIEW)
            ->name('wms.mrf.index');

        Route::get('/create', [MaterialRequisitionController::class, 'create'])
            ->middleware('can:'.Permission::MRF_CREATE)
            ->name('wms.mrf.create');
        Route::post('/', [MaterialRequisitionController::class, 'store'])
            ->middleware('can:'.Permission::MRF_CREATE)
            ->name('wms.mrf.store');
        Route::get('/lookup/products', [MaterialRequisitionController::class, 'lookupProducts'])
            ->middleware('can:'.Permission::MRF_CREATE)
            ->name('wms.mrf.lookup.products');
        Route::delete('/contacts/{contact}', [MaterialRequisitionController::class, 'destroyContact'])
            ->middleware('can:'.Permission::MRF_CREATE)
            ->name('wms.mrf.contacts.destroy');

        Route::get('/{mrf}', [MaterialRequisitionController::class, 'show'])
            ->middleware('can:'.Permission::MRF_VIEW)
            ->name('wms.mrf.show');
        // Perbaikan permintaan yang DITOLAK, nomornya tetap — sama seperti
        // pesanan Sales yang ditolak.
        Route::get('/{mrf}/edit', [MaterialRequisitionController::class, 'edit'])
            ->middleware('can:'.Permission::MRF_CREATE)
            ->name('wms.mrf.edit');
        Route::put('/{mrf}', [MaterialRequisitionController::class, 'update'])
            ->middleware('can:'.Permission::MRF_CREATE)
            ->name('wms.mrf.update');
        Route::post('/{mrf}/resend', [MaterialRequisitionController::class, 'resend'])
            ->middleware('can:'.Permission::MRF_CREATE)
            ->name('wms.mrf.resend');

        Route::get('/{mrf}/approve', [MaterialRequisitionController::class, 'approveForm'])
            ->middleware('can:'.Permission::MRF_APPROVE)
            ->name('wms.mrf.approve.form');
        Route::post('/{mrf}/approve', [MaterialRequisitionController::class, 'approve'])
            ->middleware('can:'.Permission::MRF_APPROVE)
            ->name('wms.mrf.approve');
        Route::post('/{mrf}/reject', [MaterialRequisitionController::class, 'reject'])
            ->middleware('can:'.Permission::MRF_APPROVE)
            ->name('wms.mrf.reject');

        // Permintaan lewat tautan divisi ditutup di gudang, saat orangnya
        // datang mengambil — pemohonnya tidak punya akun untuk menekan apa pun.
        Route::post('/{mrf}/collect', [MaterialRequisitionController::class, 'collect'])
            ->middleware('can:'.Permission::MRF_APPROVE)
            ->name('wms.mrf.collect');
        Route::post('/{mrf}/receive', [MaterialRequisitionController::class, 'receive'])
            ->middleware('can:'.Permission::MRF_RECEIVE)
            ->name('wms.mrf.receive');

        /*
        | Pembatalan dibuka untuk DUA izin sekaligus — Produksi yang salah
        | meminta, dan Logistik yang sudah telanjur menyetujui lalu menemukan
        | barangnya ternyata dibutuhkan pesanan pelanggan. Batas sebenarnya
        | ada di keadaan dokumennya (bolehDibatalkan), bukan di peran.
        */
        Route::post('/{mrf}/cancel', [MaterialRequisitionController::class, 'cancel'])
            ->middleware('can:'.Permission::MRF_VIEW)
            ->name('wms.mrf.cancel');
    });

    // Buku material yang sudah di tangan Produksi, berikut pemakaiannya.
    /*
     | RIWAYAT PEMAKAIAN MEMAKAI MRF_VIEW, jadi ia di luar kelompok ini — bukan
     | di dalam lalu dikecualikan, yang membuat izin sebenarnya hanya terbaca
     | setelah menelusuri dua tempat.
     |
     | Halaman lain di bawah adalah TINDAKAN atas material yang sedang
     | dipegang, jadi hanya yang memegangnya yang boleh. Riwayat adalah BACAAN,
     | dan pembacanya lebih luas: Produksi menelusuri pemakaiannya sendiri,
     | Logistik menelusuri seluruhnya — termasuk permintaan divisi lewat
     | tautan, yang selesai saat diambil dan hanya terlacak dari sini.
     |
     | Yang membatasi apa yang terbaca ada di controller (scopeUntukPembaca),
     | bukan di pintu ini: divisi peminta berhenti di divisinya sendiri.
     |
     | Didaftarkan SEBELUM rute ber-{holding}: "riwayat" bukan angka, tetapi
     | urutannya tetap dijaga supaya tidak ada yang tertangkap sebagai id.
     */
    Route::get('material-produksi/riwayat', [ProductionMaterialController::class, 'riwayat'])
        ->middleware('can:'.Permission::MRF_VIEW)
        ->name('wms.material-produksi.riwayat');

    Route::prefix('material-produksi')->middleware('can:'.Permission::MRF_RECEIVE)->group(function () {
        Route::get('/', [ProductionMaterialController::class, 'index'])
            ->name('wms.material-produksi.index');
        Route::post('/{holding}/pakai', [ProductionMaterialController::class, 'consume'])
            ->name('wms.material-produksi.consume');
        // Area yang ditulis operator saat serah terima hanya keterangan awal;
        // Produksi yang tahu di lantai mana barangnya benar-benar dikerjakan.
        Route::post('/{holding}/pindah', [ProductionMaterialController::class, 'move'])
            ->name('wms.material-produksi.move');
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

/*
| MRF — persetujuan atasan lewat tautan WhatsApp.
|
| PUBLIK, DI LUAR SELURUH MIDDLEWARE, dengan alasan yang sama persis seperti
| E-POD: yang menekannya tidak punya akun WMS dan tidak akan dibuatkan. Ia
| atasan di lantai produksi yang dimintai persetujuan lewat HP — satu kata
| sandi lagi yang tidak pernah dipakai hanya akan berakhir di kertas yang
| ditempel di meja.
|
| Dibatasi kecepatan aksesnya: halaman ini terbuka ke internet, dan token 64
| karakter tidak boleh bisa dicari dengan mencoba satu per satu.
*/
Route::middleware('throttle:30,1')->group(function () {
    /*
     | Formulir permintaan untuk divisi tanpa akun (QC, R&D).
     |
     | WAJIB didaftarkan SEBELUM '/mrf/{token}', kalau tidak "minta" tertangkap
     | sebagai token persetujuan dan formulirnya tidak pernah bisa dibuka.
     */
    Route::get('/mrf/minta/{token}', [MrfRequestLinkController::class, 'show'])
        ->name('mrf.minta.show');
    Route::post('/mrf/minta/{token}', [MrfRequestLinkController::class, 'store'])
        ->name('mrf.minta.store');
    Route::get('/mrf/minta/{token}/selesai', [MrfRequestLinkController::class, 'done'])
        ->name('mrf.minta.selesai');
    Route::get('/mrf/minta/{token}/produk', [MrfRequestLinkController::class, 'lookupProducts'])
        ->name('mrf.minta.produk');

    Route::get('/mrf/{token}', [MrfApprovalController::class, 'show'])->name('mrf.approval.show');
    Route::post('/mrf/{token}/approve', [MrfApprovalController::class, 'approve'])->name('mrf.approval.approve');
    Route::post('/mrf/{token}/reject', [MrfApprovalController::class, 'reject'])->name('mrf.approval.reject');
});
