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

    public const INBOUND_RETURNS = 'inbound.returns';

    public const INBOUND_VERIFY = 'inbound.verify';

    /* ------------------------------------------------------------ Inventory */

    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_ADJUST = 'inventory.adjust';

    /** Pemindahan antar RAK di dalam satu gudang (F-INV-02). */
    public const INVENTORY_TRANSFER = 'inventory.transfer';

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

    public const OUTBOUND_PICKING_LIST = 'outbound.picking.list';

    public const OUTBOUND_PICKING_PROCESS = 'outbound.picking.process';

    public const OUTBOUND_DELIVERY = 'outbound.delivery';

    public const OUTBOUND_VERIFICATION = 'outbound.verification';

    /* -------------------------------------------------------------- Billing */

    public const BILLING_VIEW = 'billing.view';

    /* ---------------------------------------------------- Master data & admin */

    public const MASTER_CUSTOMERS = 'master.customers';

    public const MASTER_PRODUCTS = 'master.products';

    public const MASTER_LOCATIONS = 'master.locations';

    public const ADMIN_USERS = 'admin.users';

    public const ADMIN_SEQUENCE = 'admin.sequence';

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
        self::INBOUND_RETURNS => [Role::SUPER_ADMIN, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR],
        self::INBOUND_VERIFY => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        // Stok: Produksi & Operator hanya MELIHAT (butuh cek lokasi saat
        // put-away/picking); yang boleh mengubah hanya Super Admin & Manager.
        self::INVENTORY_VIEW => [
            Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS,
            Role::PRODUCTION, Role::WAREHOUSE_OPERATOR,
        ],
        self::INVENTORY_ADJUST => [Role::SUPER_ADMIN, Role::MANAGER],
        self::INVENTORY_TRANSFER => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

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
        self::OUTBOUND_PICKING_LIST => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::OUTBOUND_PICKING_PROCESS => [Role::SUPER_ADMIN, Role::WAREHOUSE_OPERATOR],
        self::OUTBOUND_DELIVERY => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],
        self::OUTBOUND_VERIFICATION => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        self::BILLING_VIEW => [Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS],

        self::MASTER_CUSTOMERS => [Role::SUPER_ADMIN, Role::MANAGER],
        self::MASTER_PRODUCTS => [Role::SUPER_ADMIN, Role::MANAGER],

        // Mengikuti PRD §5.2 "Master Lokasi Rak (CRUD)". Operator & Logistik
        // tetap melihat lokasi saat put-away/picking, tapi lewat layar
        // prosesnya masing-masing — bukan lewat halaman master ini.
        self::MASTER_LOCATIONS => [Role::SUPER_ADMIN, Role::MANAGER],
        self::ADMIN_USERS => [Role::SUPER_ADMIN, Role::MANAGER],
        self::ADMIN_SEQUENCE => [Role::SUPER_ADMIN, Role::MANAGER],
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
