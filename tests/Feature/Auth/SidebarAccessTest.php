<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Batas menu & akses per role — PRD §5.2.
 *
 * Fokus utama: memastikan sidebar dan middleware SEPAKAT. Menu yang
 * disembunyikan tapi route-nya terbuka adalah celah nyata (siapa pun yang tahu
 * URL-nya bisa masuk); menu yang tampil tapi route-nya tertutup membuat
 * pengguna menabrak 403. Karena itu setiap role diuji dua sisi sekaligus.
 */
class SidebarAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Halaman (GET) yang dilindungi, dipetakan ke fitur di Permission::MATRIX. */
    private const GUARDED_PAGES = [
        '/wms/dashboard/admin' => Permission::DASHBOARD_MAIN,
        '/wms/dashboard/produksi' => Permission::DASHBOARD_PRODUKSI,
        '/wms/dashboard/operator' => Permission::DASHBOARD_OPERATOR,
        '/wms/reports' => Permission::REPORTS_VIEW,
        '/wms/inbound/create' => Permission::INBOUND_CREATE,
        '/wms/inbound/history' => Permission::INBOUND_HISTORY,
        '/wms/inbound/putaway' => Permission::INBOUND_PUTAWAY,
        '/wms/inbound/returns' => Permission::INBOUND_RETURNS,
        '/wms/inbound/verify' => Permission::INBOUND_VERIFY,
        '/wms/inventory' => Permission::INVENTORY_VIEW,
        '/wms/outbound/approval' => Permission::OUTBOUND_APPROVAL,
        '/wms/outbound/picking/batching' => Permission::OUTBOUND_PICKING_LIST,
        '/wms/outbound/picking' => Permission::OUTBOUND_PICKING_PROCESS,
        '/wms/outbound/delivery' => Permission::OUTBOUND_DELIVERY,
        '/wms/outbound/verification' => Permission::OUTBOUND_VERIFICATION,
        '/wms/billing' => Permission::BILLING_VIEW,
        '/wms/master/customers' => Permission::MASTER_CUSTOMERS,
        '/wms/master/products' => Permission::MASTER_PRODUCTS,
        '/wms/master/locations' => Permission::MASTER_LOCATIONS,
        '/wms/admin/users' => Permission::ADMIN_USERS,
        '/wms/admin/sequence' => Permission::ADMIN_SEQUENCE,
    ];

    /** Label menu di sidebar, dipetakan ke fitur yang menentukan tampil/tidaknya. */
    private const MENU_LABELS = [
        Permission::INBOUND_CREATE => 'Input Produksi',
        Permission::INBOUND_HISTORY => 'Riwayat Produksi',
        Permission::INBOUND_PUTAWAY => 'Proses Put-away',
        Permission::INBOUND_RETURNS => 'Penerimaan Retur',
        Permission::INBOUND_VERIFY => 'Verifikasi Logistik',
        Permission::INVENTORY_VIEW => 'Data Stok (Inventory)',
        Permission::OUTBOUND_APPROVAL => 'Terima Pesanan',
        Permission::OUTBOUND_PICKING_LIST => 'Daftar Picking',
        Permission::OUTBOUND_PICKING_PROCESS => 'Proses Picking',
        Permission::OUTBOUND_DELIVERY => 'Cetak Surat Jalan',
        Permission::OUTBOUND_VERIFICATION => 'Verifikasi Bukti SJ',
        Permission::BILLING_VIEW => 'Billing & Piutang',
        Permission::MASTER_CUSTOMERS => 'Master Customers',
        Permission::MASTER_PRODUCTS => 'Master Products',
        Permission::MASTER_LOCATIONS => 'Master Lokasi Rak',
        Permission::ADMIN_USERS => 'Manajemen User',
        Permission::ADMIN_SEQUENCE => 'Pengaturan Dokumen',
        Permission::REPORTS_VIEW => 'Laporan & Analisis',
    ];

    private function loginAs(string $roleSlug): User
    {
        $user = User::factory()->withRole($roleSlug)->create();
        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $token,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'last_activity_at' => now(),
            'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->actingAs($user);

        return $user;
    }

    public static function roleProvider(): array
    {
        return [
            'Super Admin' => [Role::SUPER_ADMIN],
            'Manager' => [Role::MANAGER],
            'Logistik' => [Role::LOGISTICS],
            'Produksi' => [Role::PRODUCTION],
            'Operator Gudang' => [Role::WAREHOUSE_OPERATOR],
        ];
    }

    /** Setiap halaman WMS: role yang berhak dapat 200, yang tidak berhak dapat 403. */
    #[DataProvider('roleProvider')]
    public function test_akses_halaman_sesuai_matriks_permission(string $roleSlug): void
    {
        $user = $this->loginAs($roleSlug);

        foreach (self::GUARDED_PAGES as $url => $feature) {
            $boleh = Permission::allows($user, $feature);
            $status = $this->get($url)->getStatusCode();

            $this->assertSame(
                $boleh ? 200 : 403,
                $status,
                "Role [{$roleSlug}] mengakses [{$url}] (fitur {$feature}): ".
                'diharapkan '.($boleh ? '200' : '403')." tapi dapat {$status}."
            );
        }
    }

    /** Sidebar hanya menampilkan menu yang rolenya memang berhak membukanya. */
    #[DataProvider('roleProvider')]
    public function test_sidebar_hanya_menampilkan_menu_yang_berhak(string $roleSlug): void
    {
        $user = $this->loginAs($roleSlug);

        // Buka halaman yang pasti bisa diakses role ini supaya sidebar terender.
        $landing = match ($roleSlug) {
            Role::PRODUCTION => '/wms/dashboard/produksi',
            Role::WAREHOUSE_OPERATOR => '/wms/dashboard/operator',
            default => '/wms/dashboard/admin',
        };

        $html = $this->get($landing)->assertOk()->getContent();

        foreach (self::MENU_LABELS as $feature => $label) {
            if (Permission::allows($user, $feature)) {
                $this->assertStringContainsString(
                    $label,
                    $html,
                    "Role [{$roleSlug}] berhak atas [{$feature}] tapi menu \"{$label}\" tidak muncul di sidebar."
                );
            } else {
                $this->assertStringNotContainsString(
                    $label,
                    $html,
                    "Role [{$roleSlug}] TIDAK berhak atas [{$feature}] tapi menu \"{$label}\" tetap muncul di sidebar."
                );
            }
        }
    }

    /* ------------------------------------------------- Aturan spesifik PRD */

    public function test_produksi_hanya_melihat_tiga_menu_utamanya(): void
    {
        $this->loginAs(Role::PRODUCTION);

        $html = $this->get('/wms/dashboard/produksi')->assertOk()->getContent();

        $this->assertStringContainsString('Input Produksi', $html);
        $this->assertStringContainsString('Riwayat Produksi', $html);
        $this->assertStringContainsString('Data Stok (Inventory)', $html);

        // Bukan wewenangnya: put-away, picking, retur, billing, master data.
        $this->assertStringNotContainsString('Proses Put-away', $html);
        $this->assertStringNotContainsString('Proses Picking', $html);
        $this->assertStringNotContainsString('Penerimaan Retur', $html);
        $this->assertStringNotContainsString('Billing & Piutang', $html);
        $this->assertStringNotContainsString('Pengaturan Sistem', $html);
    }

    public function test_operator_hanya_melihat_menu_operasional_gudang(): void
    {
        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $html = $this->get('/wms/dashboard/operator')->assertOk()->getContent();

        $this->assertStringContainsString('Proses Put-away', $html);
        $this->assertStringContainsString('Proses Picking', $html);
        $this->assertStringContainsString('Penerimaan Retur', $html);
        $this->assertStringContainsString('Data Stok (Inventory)', $html);

        $this->assertStringNotContainsString('Input Produksi', $html);
        $this->assertStringNotContainsString('Terima Pesanan', $html);
        $this->assertStringNotContainsString('Billing & Piutang', $html);
    }

    /** Manager mengawasi, tidak ikut mengerjakan tugas operasional harian. */
    public function test_manager_tidak_dapat_tugas_operasional_harian(): void
    {
        $user = $this->loginAs(Role::MANAGER);

        foreach ([
            Permission::INBOUND_CREATE,
            Permission::INBOUND_PUTAWAY,
            Permission::INBOUND_RETURNS,
            Permission::OUTBOUND_PICKING_PROCESS,
        ] as $feature) {
            $this->assertFalse(
                Permission::allows($user, $feature),
                "Manager seharusnya TIDAK berhak atas [{$feature}]."
            );
        }

        $this->get('/wms/inbound/create')->assertForbidden();
        $this->get('/wms/inbound/putaway')->assertForbidden();
        $this->get('/wms/inbound/returns')->assertForbidden();
        $this->get('/wms/outbound/picking')->assertForbidden();
    }

    public function test_super_admin_dapat_mengakses_seluruh_halaman_wms(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        foreach (array_keys(self::GUARDED_PAGES) as $url) {
            $this->get($url)->assertOk();
        }
    }

    /* --------------------------------------------------- Stok: lihat vs ubah */

    /** Produksi & Operator boleh MELIHAT stok, tapi tidak boleh mengubahnya. */
    public function test_produksi_dan_operator_tidak_dapat_mengubah_stok(): void
    {
        foreach ([Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);

            $this->get('/wms/inventory')->assertOk();
            $this->post('/wms/inventory/adjust')->assertForbidden();
            $this->post('/wms/inventory/transfer')->assertForbidden();
        }
    }

    /** Logistik boleh transfer stok antar lokasi, tapi tidak boleh adjustment. */
    public function test_logistik_boleh_transfer_tapi_tidak_adjustment(): void
    {
        $user = $this->loginAs(Role::LOGISTICS);

        $this->assertTrue(Permission::allows($user, Permission::INVENTORY_TRANSFER));
        $this->assertFalse(Permission::allows($user, Permission::INVENTORY_ADJUST));

        $this->post('/wms/inventory/adjust')->assertForbidden();
    }

    /* -------------------------------------------------- Redirect dashboard */

    /** /wms/dashboard harus mengarah ke dashboard milik role, bukan selalu admin. */
    public function test_redirect_dashboard_mengikuti_role(): void
    {
        $cases = [
            Role::SUPER_ADMIN => '/wms/dashboard/admin',
            Role::MANAGER => '/wms/dashboard/admin',
            Role::LOGISTICS => '/wms/dashboard/admin',
            Role::PRODUCTION => '/wms/dashboard/produksi',
            Role::WAREHOUSE_OPERATOR => '/wms/dashboard/operator',
        ];

        foreach ($cases as $slug => $expected) {
            $this->loginAs($slug);
            $this->get('/wms/dashboard')->assertRedirect($expected);
        }
    }

    /* ------------------------------------------------------- Portal Sales */

    public function test_sales_melihat_bottom_nav_dan_hanya_tiga_menu(): void
    {
        $this->loginAs(Role::SALES);

        $html = $this->get('/sales/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('bottom-nav', $html);
        $this->assertStringContainsString('/sales/new-order', $html);
        $this->assertStringContainsString('/sales/my-orders', $html);

        // Tidak boleh ada jejak menu Portal WMS di layout Sales.
        $this->assertStringNotContainsString('Berger WMS', $html);
        $this->assertStringNotContainsString('Data Stok (Inventory)', $html);
        $this->assertStringNotContainsString('My Customers', $html);
    }
}
