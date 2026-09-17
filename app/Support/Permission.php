<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Matriks hak akses per fitur — SATU-SATUNYA sumber kebenaran RBAC.
 *
 * Dipakai bersama oleh dua jalur yang harus selalu sepakat:
 *   1. Sidebar (Blade `@can`) — menentukan menu mana yang tampil.
 *   2. Route middleware (`->middleware('can:<fitur>')`) — penegakan sebenarnya.
 *
 * Menyembunyikan menu BUKAN pengamanan: tanpa middleware, siapa pun yang tahu
 * URL-nya tetap bisa masuk. Karena itu setiap fitur di sini wajib dipasang di
 * kedua tempat, dan test di tests/Feature/Auth/SidebarAccessTest.php menjaga
 * keduanya tidak berpisah jalan.
 *
 * Acuan: PRD §5.2 (Matriks Hak Akses Detail) v1.3.
 */
class Permission
{
    /* ------------------------------------------------------------ Dashboard */

    public const DASHBOARD_MAIN = 'dashboard.main';

    public const DASHBOARD_PRODUKSI = 'dashboard.produksi';

    public const DASHBOARD_OPERATOR = 'dashboard.operator';

    public const REPORTS_VIEW = 'reports.view';

    /* -------------------------------------------------------------- Inbound */

    public const INBOUND_CREATE = 'inbound.create';

    public const INBOUND_HISTORY = 'inbound.history';

    public const INBOUND_PUTAWAY = 'inbound.putaway';

    /**
     * PENOLAKAN CUSTOMER — barang yang ditolak saat pengiriman lalu kembali.
     *
     * DIPECAH TIGA, karena tiga peran berbeda mengerjakan tiga hal berbeda
     * pada dokumen yang sama. Menyatukannya jadi satu izin berarti Operator
     * yang menaikkan barang ke rak juga boleh mengesahkan hasil kerjanya
     * sendiri — persis yang dihindari di STOCKTAKE_COUNT vs STOCKTAKE_MANAGE.
     */
    public const RETURN_VIEW = 'return.view';

    /** Menyetujui klaim penolakan DAN memverifikasi barangnya di rak. */
    public const RETURN_APPROVE = 'return.approve';

    /** Menaikkan barang tolakan ke rak, memisah yang bagus dari yang DDP. */
    public const RETURN_PUTAWAY = 'return.putaway';

    public const INBOUND_VERIFY = 'inbound.verify';

    /* ------------------------------------------------------------ Inventory */

    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_ADJUST = 'inventory.adjust';

    /**
     * Pemindahan antar RAK di dalam satu gudang (F-INV-02).
     *
     * TERBUKA SAMPAI OPERATOR GUDANG — keputusan pemilik produk. Merekalah
     * yang benar-benar mengangkat barangnya; memaksa mereka memanggil Logistik
     * hanya untuk mencatat perpindahan yang sudah terjadi membuat sistem
     * tertinggal dari kenyataan di rak. Memindahkan TIDAK mengubah jumlah stok
     * sama sekali, jadi wewenang ini tidak bisa dipakai untuk menambah atau
     * mengurangi apa pun — itu tetap INVENTORY_ADJUST.
     */
    public const INVENTORY_TRANSFER = 'inventory.transfer';

    /**
     * Penanda batch: Karantina, Quality Issue, dan Dahulukan Keluar —
     * permintaan pemilik produk, bukan PRD.
     *
     * SENGAJA DIPISAH dari INVENTORY_ADJUST. Koreksi qty dan penandaan DDP
     * permanen tetap wewenang Manager/Super Admin saja; tapi karantina adalah
     * hasil pemeriksaan QC yang dilakukan begitu barang naik rak — pekerjaan
     * sehari-hari Logistik, bukan keputusan yang perlu naik ke Manager.
     */
    public const INVENTORY_QUARANTINE = 'inventory.quarantine';

    /**
     * Membaca buku besar mutasi stok — kartu stok.
     *
     * DIPISAH dari INVENTORY_VIEW, yang menjawab "berapa sisa barang ini
     * sekarang" dan memang dibutuhkan Produksi serta Operator tiap hari untuk
     * mencari rak. Kartu stok menjawab pertanyaan yang lain sama sekali:
     * SETIAP pertambahan dan pengurangan sejak hari pertama, lengkap dengan
     * dokumen penyebabnya. Itu bahan rekonsiliasi, bukan bahan kerja harian,
     * dan yang mengerjakannya Logistik bersama Manager.
     */
    public const INVENTORY_LEDGER = 'inventory.ledger';

    /**
     * MEMASUKKAN hasil hitungan fisik saat stocktake.
     *
     * Terbuka sampai Operator Gudang: merekalah yang berdiri di depan rak dan
     * menghitung. Memasukkan hitungan TIDAK mengubah stok sama sekali — ia
     * hanya menumpuk sebagai catatan sampai laporannya disahkan.
     */
    public const STOCKTAKE_COUNT = 'stocktake.count';

    /**
     * Membuka sesi stocktake dan MENGESAHKAN laporannya.
     *
     * SENGAJA DIPISAH dari yang menghitung. Pengesahan itulah yang benar-benar
     * menggeser angka stok — kadang ribuan unit sekaligus — dan orang yang
     * salah menghitung tidak boleh sekaligus menjadi orang yang mengesahkan
     * koreksi atas kesalahannya sendiri.
     */
    public const STOCKTAKE_MANAGE = 'stocktake.manage';

    /**
     * Booking produk: menahan jatah untuk customer sebelum pesanannya masuk.
     *
     * Wewenang Logistik, bukan Sales. Yang ditahan adalah stok gudang — dan
     * setiap unit yang dibooking langsung hilang dari angka yang boleh
     * dijanjikan ke pelanggan lain. Membuka pintu itu ke Sales berarti siapa
     * pun bisa mengunci stok untuk pelanggannya sendiri tanpa gudang tahu.
     */
    public const BOOKING = 'booking.manage';

    /*
     | Transfer antar GUDANG (F-INV-05) — sengaja dipisah dari yang di atas.
     | Memindahkan palet ke rak sebelah dan mengirim satu truk ke Pekanbaru
     | bukan wewenang yang sama besarnya, dan menyatukannya berarti siapa pun
     | yang boleh merapikan rak juga boleh mengosongkan gudang.
     */

    public const TRANSFER_SEND = 'transfer.send';

    public const TRANSFER_RECEIVE = 'transfer.receive';

    public const TRANSFER_HISTORY = 'transfer.history';

    /* ------------------------------------------------------------- Outbound */

    public const OUTBOUND_APPROVAL = 'outbound.approval';

    /**
     * Membuat pesanan dari sisi WMS, atas nama seorang Sales.
     *
     * SENGAJA IZIN TERSENDIRI, bukan menumpang OUTBOUND_APPROVAL. Kalau
     * menumpang, Logistik ikut mendapatkannya — dan Logistik adalah pihak
     * yang menilai pesanan. Yang membuat sekaligus menilai tanpa seorang pun
     * di luar rantai itu adalah keadaan yang justru dihindari.
     *
     * Pemilik produk memutuskan pembuat BOLEH menyetujui pesanannya sendiri,
     * jadi pemisahan itu memang sudah dilepas untuk Admin dan Manager. Yang
     * tersisa sebagai kontrol adalah jejaknya (sales_orders.placed_by) dan
     * kabar ke Sales yang namanya dipakai — keduanya wajib, dan keduanya ada.
     */
    public const OUTBOUND_ORDER_INTERNAL = 'outbound.order_internal';

    public const OUTBOUND_PICKING_LIST = 'outbound.picking.list';

    public const OUTBOUND_PICKING_PROCESS = 'outbound.picking.process';

    /**
     * MEMBACA rincian satu daftar picking.
     *
     * Fitur tersendiri karena dibaca dua peran dengan pekerjaan berbeda:
     * Logistik memeriksa hasil susunannya, Operator mengerjakannya. Menumpang
     * salah satu dari dua fitur di atas berarti salah satu peran itu ditolak
     * membuka halaman yang justru jadi bagian pekerjaannya.
     */
    public const OUTBOUND_PICKING_VIEW = 'outbound.picking.view';

    public const OUTBOUND_DELIVERY = 'outbound.delivery';

    public const OUTBOUND_VERIFICATION = 'outbound.verification';

    /* ------------------------------------------------------------------ MRF */

    /*
     | PERMINTAAN MATERIAL PRODUKSI (MRF) — empat izin, empat pekerjaan.
     |
     | Dipecah sebanyak ini bukan karena senang memecah, melainkan karena
     | empat orang yang berbeda mengerjakan empat hal yang berbeda pada satu
     | dokumen: Produksi meminta, Logistik memutuskan, Operator mengambilkan,
     | Produksi menerima dan memakainya. Menyatukan MRF_CREATE dengan
     | MRF_APPROVE berarti Produksi menyetujui permintaannya sendiri — dan
     | seluruh gunanya persetujuan hilang di baris itu juga.
     */

    /** Menyusun dan mengirim permintaan material. */
    public const MRF_CREATE = 'mrf.create';

    /** Membaca daftar dan rincian MRF. */
    public const MRF_VIEW = 'mrf.view';

    /** Menyetujui/menolak dari sisi gudang DAN memilih batch sungguhannya. */
    public const MRF_APPROVE = 'mrf.approve';

    /**
     * Menerima barangnya dan mencatat pemakaiannya.
     *
     * Milik Produksi, bukan Logistik. Sesudah diterima, barang itu ada di
     * lantai produksi dan hanya orang di sana yang tahu berapa yang benar-
     * benar masuk mixer hari ini.
     */
    public const MRF_RECEIVE = 'mrf.receive';

    /**
     * Menelusuri pemakaian material yang sudah lewat, lintas divisi.
     *
     * Dipisah dari MRF_VIEW karena pembacanya berbeda. MRF_VIEW menjawab
     * "permintaan saya sampai mana" — pertanyaan divisi peminta, dan
     * jawabannya sengaja dibatasi ke divisinya sendiri. Riwayat menjawab
     * "ke mana barang ini pergi setahun lalu" — pertanyaan gudang, dan
     * jawabannya harus melintasi semua divisi sekaligus untuk ada gunanya.
     *
     * Riwayat yang tidak bisa dipenggal per divisi itulah alasan Produksi
     * dan Sales tidak ada di sini: membukanya untuk mereka berarti membuka
     * pemakaian divisi lain juga.
     */
    public const MRF_HISTORY = 'mrf.history';

    /* -------------------------------------------------------------- Billing */

    public const BILLING_VIEW = 'billing.view';

    /** Konfirmasi lunas — PRD F-BILL-02: Logistik yang menerima kabar pembayaran. */
    public const BILLING_CONFIRM = 'billing.confirm';

    /** Membatalkan konfirmasi lunas yang keliru. Sengaja BUKAN yang mengonfirmasi. */
    public const BILLING_VOID = 'billing.void';

    /**
     * Penerima lonceng pengingat jatuh tempo — keputusan pemilik produk:
     * Manager saja, bukan Sales dan bukan Logistik.
     */
    public const BILLING_REMINDER = 'billing.reminder';

    /* ---------------------------------------------------- Master data & admin */

    public const MASTER_CUSTOMERS = 'master.customers';

    public const MASTER_PRODUCTS = 'master.products';

    public const MASTER_LOCATIONS = 'master.locations';

    public const ADMIN_USERS = 'admin.users';

    public const ADMIN_SEQUENCE = 'admin.sequence';

    /**
     * Pengaturan Sistem — SUPER ADMIN SAJA (Fase 10).
     *
     * Manager sengaja tidak ikut, walau ia ikut di ADMIN_USERS dan
     * ADMIN_SEQUENCE. Alasannya bukan soal kepercayaan melainkan CAKUPAN:
     * setelan di sini berlaku untuk SELURUH perusahaan, sementara seluruh
     * kewenangan Manager dibatasi ke gudangnya sendiri. Manager Karawang yang
     * menggeser jam cutoff akan mengubah jam kerja Sales Pekanbaru yang tidak
     * pernah ia temui.
     */
    public const ADMIN_SETTINGS = 'admin.settings';

    /**
     * Log aktivitas: siapa melakukan apa, kapan — SUPER ADMIN SAJA.
     *
     * Manager sengaja TIDAK ikut, walau ia ikut di hampir semua gate admin
     * lainnya. Log ini merekam tindakan Manager juga; memberi Manager akses
     * membaca log berarti orang yang diawasi memegang jendela pengawasnya
     * sendiri. Log yang bisa dibaca pelakunya masih berguna, tetapi bukan lagi
     * alat pemeriksaan.
     */
    public const ADMIN_AUDIT = 'admin.audit';

    /**
     * Fitur => daftar slug role yang diizinkan.
     *
     * Super Admin sengaja ditulis eksplisit di setiap baris, bukan lewat
     * pintasan "kalau super admin, izinkan semua". Alasannya: PRD §5.2 memberi
     * Super Admin akses penuh Portal Warehouse TAPI melarangnya membuat PO di
     * Portal Sales — pintasan semacam itu akan diam-diam membocorkan larangan
     * tersebut begitu ada fitur Sales yang ikut lewat gate ini.
     *
     * @var array<string, list<string>>
     */
    public const MATRIX = [
        // Dashboard utama (data seluruh gudang) — pengawas & alur outbound harian.
        self::DASHBOARD_MAIN => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::DASHBOARD_PRODUKSI => [Role::SUPER_ADMIN, Role::MANAGER, Role::PRODUCTION],
        self::DASHBOARD_OPERATOR => [Role::SUPER_ADMIN, Role::MANAGER, Role::WAREHOUSE_OPERATOR],
        self::REPORTS_VIEW => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        // Inbound: input & riwayat milik Produksi, put-away milik Operator,
        // verifikasi milik Logistik (Maker-Checker, PRD §6.3).
        self::INBOUND_CREATE => [Role::SUPER_ADMIN, Role::PRODUCTION],
        self::INBOUND_HISTORY => [Role::SUPER_ADMIN, Role::MANAGER, Role::PRODUCTION],
        self::INBOUND_PUTAWAY => [Role::SUPER_ADMIN, Role::WAREHOUSE_OPERATOR],
        /*
         | PENOLAKAN CUSTOMER. Semua yang terlibat boleh MELIHAT antreannya —
         | Operator perlu tahu ada barang menunggu dinaikkan, dan tanpa itu ia
         | harus ditelepon setiap kali. Yang dipisah adalah tindakannya.
         */
        self::RETURN_VIEW => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR,
        ],
        self::RETURN_APPROVE => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::RETURN_PUTAWAY => [Role::SUPER_ADMIN, Role::WAREHOUSE_OPERATOR],
        self::INBOUND_VERIFY => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        // Stok: Produksi & Operator hanya MELIHAT (butuh cek lokasi saat
        // put-away/picking); yang boleh mengubah hanya Super Admin & Manager.
        self::INVENTORY_VIEW => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS,
            Role::PRODUCTION, Role::WAREHOUSE_OPERATOR,
        ],
        self::INVENTORY_ADJUST => [Role::SUPER_ADMIN, Role::MANAGER],
        self::INVENTORY_TRANSFER => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR,
        ],
        self::INVENTORY_QUARANTINE => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::INVENTORY_LEDGER => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::STOCKTAKE_COUNT => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR,
        ],
        self::STOCKTAKE_MANAGE => [Role::SUPER_ADMIN, Role::MANAGER],
        self::BOOKING => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        // Penerimaan transfer memutuskan angka stok final di gudang tujuan —
        // wewenang yang sama dengan Verifikasi Logistik pada jalur inbound,
        // jadi daftar role-nya pun disamakan.
        self::TRANSFER_SEND => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::TRANSFER_RECEIVE => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::TRANSFER_HISTORY => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR,
        ],

        // Outbound: proses picking di tangan Operator; sisanya Logistik.
        self::OUTBOUND_APPROVAL => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        // Logistik TIDAK ikut — lihat alasannya di konstantanya.
        self::OUTBOUND_ORDER_INTERNAL => [Role::SUPER_ADMIN, Role::MANAGER],
        self::OUTBOUND_PICKING_LIST => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::OUTBOUND_PICKING_PROCESS => [Role::SUPER_ADMIN, Role::WAREHOUSE_OPERATOR],
        self::OUTBOUND_PICKING_VIEW => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR,
        ],
        self::OUTBOUND_DELIVERY => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::OUTBOUND_VERIFICATION => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        /*
         | MRF. Operator ikut MELIHAT: daftar picking yang ia kerjakan bisa
         | berisi permintaan material, dan tanpa akses membaca dokumennya ia
         | mengambil barang tanpa tahu untuk siapa dan ke rak mana harus
         | ditaruh. Yang dipisah adalah tindakannya, bukan bacaannya.
         */
        /*
         | SALES IKUT MEMINTA, dan itu memang terjadi di lapangan: contoh untuk
         | calon pelanggan baru diminta Sales, bukan Produksi. Ia sudah punya
         | akun dan memakai WMS tiap hari, jadi jalurnya akun biasa — bukan
         | tautan divisi, yang disediakan justru untuk divisi yang TIDAK punya
         | akun (QC, R&D — lihat MrfRequestLink).
         |
         | Yang DILIHAT Sales dan Produksi dibatasi ke permintaannya sendiri di
         | controller; izin ini hanya membuka pintunya.
         */
        self::MRF_CREATE => [Role::SUPER_ADMIN, Role::PRODUCTION, Role::SALES],
        self::MRF_VIEW => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS,
            Role::PRODUCTION, Role::WAREHOUSE_OPERATOR, Role::SALES,
        ],
        self::MRF_APPROVE => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::MRF_RECEIVE => [Role::SUPER_ADMIN, Role::PRODUCTION, Role::SALES],
        self::MRF_HISTORY => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        self::BILLING_VIEW => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::BILLING_CONFIRM => [Role::SUPER_ADMIN, Role::LOGISTICS],
        self::BILLING_VOID => [Role::SUPER_ADMIN, Role::MANAGER],
        self::BILLING_REMINDER => [Role::MANAGER],

        self::MASTER_CUSTOMERS => [Role::SUPER_ADMIN, Role::MANAGER],
        self::MASTER_PRODUCTS => [Role::SUPER_ADMIN, Role::MANAGER],

        // Mengikuti PRD §5.2 "Master Lokasi Rak (CRUD)". Operator & Logistik
        // tetap melihat lokasi saat put-away/picking, tapi lewat layar
        // prosesnya masing-masing — bukan lewat halaman master ini.
        self::MASTER_LOCATIONS => [Role::SUPER_ADMIN, Role::MANAGER],
        self::ADMIN_USERS => [Role::SUPER_ADMIN, Role::MANAGER],
        self::ADMIN_SEQUENCE => [Role::SUPER_ADMIN, Role::MANAGER],
        self::ADMIN_SETTINGS => [Role::SUPER_ADMIN],
        self::ADMIN_AUDIT => [Role::SUPER_ADMIN],
    ];

    /** Seluruh nama fitur, dipakai AppServiceProvider untuk mendaftarkan Gate. */
    public static function features(): array
    {
        return array_keys(self::MATRIX);
    }

    public static function allows(?User $user, string $feature): bool
    {
        $slug = $user?->role?->slug;

        if ($slug === null) {
            return false;
        }

        return in_array($slug, self::MATRIX[$feature] ?? [], true);
    }
}
