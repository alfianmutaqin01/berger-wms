<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head')
</head>
{{--
    Sidebar Portal WMS.

    Visibilitas tiap menu memakai @can(...) yang mengacu ke Gate dari
    App\Support\Permission — matriks yang SAMA dipakai middleware `can:` di
    routes/web.php. Menyembunyikan menu di sini bukan pengamanan; penegaknya
    ada di route. Keduanya wajib berubah bersamaan.

    Grup dropdown dibungkus @canany(...) supaya header grup tidak muncul kosong
    untuk role yang tidak punya satu pun anak menu di dalamnya.

    Catatan: dulu berkas ini punya dua salinan menu (dropdown untuk admin, flat
    untuk role lain) yang dipilih lewat `request('role')`. Pola itu sudah
    dihapus — selain menghasilkan ID HTML ganda, penentuan role lewat query
    string sudah tidak berlaku sejak autentikasi nyata aktif (Fase 1).
--}}
<body>
<div class="wrapper">
    <!-- Sidebar -->
    <nav id="sidebar" class="sidebar">
        <!-- Brand -->
        <div class="sidebar-header d-flex justify-content-between align-items-center w-100">
            <a href="/wms/dashboard" class="sidebar-brand text-decoration-none d-flex align-items-center">
                <i class="bi bi-box-seam"></i> <span class="ms-2">Berger WMS</span>
            </a>
            <button type="button" class="btn btn-link text-white p-0 d-none d-lg-block" id="sidebarToggleDesktop">
                <i class="bi bi-list fs-4"></i>
            </button>
            <button type="button" class="btn-close btn-close-white d-lg-none" id="sidebarClose" aria-label="Close"></button>
        </div>

        <ul class="sidebar-nav">
            {{-- Dashboard: tiap role diarahkan ke dashboard-nya masing-masing --}}
            @can(\App\Support\Permission::DASHBOARD_MAIN)
                <li class="nav-item {{ request()->is('wms/dashboard') || request()->is('wms/dashboard/admin') ? 'active' : '' }}">
                    <a href="/wms/dashboard/admin" class="nav-link">
                        <i class="bi bi-speedometer2"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            @endcan

            @can(\App\Support\Permission::DASHBOARD_PRODUKSI)
                <li class="nav-item {{ request()->is('wms/dashboard/produksi') ? 'active' : '' }}">
                    <a href="/wms/dashboard/produksi" class="nav-link">
                        <i class="bi bi-tools"></i>
                        <span>Dashboard Produksi</span>
                    </a>
                </li>
            @endcan

            @can(\App\Support\Permission::DASHBOARD_OPERATOR)
                <li class="nav-item {{ request()->is('wms/dashboard/operator') ? 'active' : '' }}">
                    <a href="/wms/dashboard/operator" class="nav-link">
                        <i class="bi bi-person-badge"></i>
                        <span>Dashboard Operator</span>
                    </a>
                </li>
            @endcan

            @can(\App\Support\Permission::REPORTS_VIEW)
                <li class="nav-item {{ request()->is('wms/reports') ? 'active' : '' }}">
                    <a href="/wms/reports" class="nav-link">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                        <span>Laporan & Analisis</span>
                    </a>
                </li>
            @endcan

            <!-- INBOUND & STOK -->
            @canany([
                \App\Support\Permission::INBOUND_CREATE,
                \App\Support\Permission::INBOUND_HISTORY,
                \App\Support\Permission::INBOUND_PUTAWAY,
                \App\Support\Permission::INBOUND_RETURNS,
                \App\Support\Permission::INBOUND_VERIFY,
                \App\Support\Permission::INVENTORY_VIEW,
            ])
                @php
                    $inboundOpen = request()->is('wms/inbound*') || request()->is('wms/inventory*');

                    // Produksi hanya ada di Karawang. Bagi staff Pekanbaru dan
                    // Surabaya, dua menu produksi di bawah ini tidak pernah
                    // bisa dipakai — barang sampai ke sana lewat transfer,
                    // bukan lini produksi. Put-away dan verifikasi TETAP ada:
                    // barang kiriman pun harus dinaikkan ke rak.
                    //
                    // Akun tanpa gudang (Super Admin) melihat semuanya.
                    $punyaProduksi = auth()->user()?->warehouse?->has_production ?? true;
                @endphp
                <li class="nav-section mt-2">Inventory Management</li>
                <li class="nav-item">
                    <a class="nav-link {{ $inboundOpen ? '' : 'collapsed' }}" href="#inboundMenu" data-bs-toggle="collapse" aria-expanded="{{ $inboundOpen ? 'true' : 'false' }}">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span>Inbound & Stok</span>
                        <i class="bi bi-chevron-down ms-auto" style="font-size: 0.8rem; margin-right: 0 !important; transition: transform 0.3s;"></i>
                    </a>
                    <ul class="collapse list-unstyled ps-4 {{ $inboundOpen ? 'show' : '' }}" id="inboundMenu" data-bs-parent=".sidebar-nav">
                        @can(\App\Support\Permission::INBOUND_CREATE)
                            @if($punyaProduksi)
                                <li class="nav-item {{ request()->is('wms/inbound/create') ? 'active' : '' }}">
                                    <a href="/wms/inbound/create" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Input Produksi</span></a>
                                </li>
                            @endif
                        @endcan
                        @can(\App\Support\Permission::INBOUND_HISTORY)
                            @if($punyaProduksi)
                                <li class="nav-item {{ request()->is('wms/inbound/history*') ? 'active' : '' }}">
                                    <a href="/wms/inbound/history" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Riwayat Produksi</span></a>
                                </li>
                            @endif
                        @endcan
                        @can(\App\Support\Permission::INBOUND_PUTAWAY)
                            <li class="nav-item {{ request()->is('wms/inbound/putaway*') ? 'active' : '' }}">
                                <a href="/wms/inbound/putaway" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Proses Put-away</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::INBOUND_RETURNS)
                            <li class="nav-item {{ request()->is('wms/inbound/returns*') ? 'active' : '' }}">
                                <a href="/wms/inbound/returns" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Penerimaan Retur</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::INBOUND_VERIFY)
                            <li class="nav-item {{ request()->is('wms/inbound/verify*') ? 'active' : '' }}">
                                <a href="/wms/inbound/verify" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Verifikasi Logistik</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::INVENTORY_VIEW)
                            <li class="nav-item {{ request()->is('wms/inventory*') ? 'active' : '' }}">
                                <a href="/wms/inventory" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Data Stok (Inventory)</span></a>
                            </li>
                        @endcan
                    </ul>
                </li>
            @endcanany

            <!-- OUTBOUND -->
            @canany([
                \App\Support\Permission::OUTBOUND_APPROVAL,
                \App\Support\Permission::OUTBOUND_PICKING_LIST,
                \App\Support\Permission::OUTBOUND_PICKING_PROCESS,
                \App\Support\Permission::OUTBOUND_DELIVERY,
                \App\Support\Permission::OUTBOUND_VERIFICATION,
            ])
                @php $outboundOpen = request()->is('wms/outbound*'); @endphp
                <li class="nav-item">
                    <a class="nav-link {{ $outboundOpen ? '' : 'collapsed' }}" href="#outboundMenu" data-bs-toggle="collapse" aria-expanded="{{ $outboundOpen ? 'true' : 'false' }}">
                        <i class="bi bi-truck"></i>
                        <span>Outbound (Kirim)</span>
                        <i class="bi bi-chevron-down ms-auto" style="font-size: 0.8rem; margin-right: 0 !important; transition: transform 0.3s;"></i>
                    </a>
                    <ul class="collapse list-unstyled ps-4 {{ $outboundOpen ? 'show' : '' }}" id="outboundMenu" data-bs-parent=".sidebar-nav">
                        @can(\App\Support\Permission::OUTBOUND_APPROVAL)
                            {{-- is() dengan pola eksplisit, BUKAN 'wms/outbound/approval*':
                                 pola berbintang membuat kedua menu ini menyala
                                 bersamaan saat halaman riwayat dibuka. --}}
                            <li class="nav-item {{ request()->is('wms/outbound/approval') || request()->is('wms/outbound/approval/*') && ! request()->is('wms/outbound/approval/history') ? 'active' : '' }}">
                                <a href="/wms/outbound/approval" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Terima Pesanan</span></a>
                            </li>
                            <li class="nav-item {{ request()->is('wms/outbound/approval/history') ? 'active' : '' }}">
                                <a href="/wms/outbound/approval/history" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Riwayat Penerimaan</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::OUTBOUND_PICKING_LIST)
                            <li class="nav-item {{ request()->is('wms/outbound/picking/batching') ? 'active' : '' }}">
                                <a href="/wms/outbound/picking/batching" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Daftar Picking</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::OUTBOUND_PICKING_PROCESS)
                            <li class="nav-item {{ request()->is('wms/outbound/picking') ? 'active' : '' }}">
                                <a href="/wms/outbound/picking" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Proses Picking</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::OUTBOUND_DELIVERY)
                            <li class="nav-item {{ request()->is('wms/outbound/delivery') ? 'active' : '' }}">
                                <a href="/wms/outbound/delivery" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Cetak Surat Jalan</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::OUTBOUND_VERIFICATION)
                            <li class="nav-item {{ request()->is('wms/outbound/verification') ? 'active' : '' }}">
                                <a href="/wms/outbound/verification" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Verifikasi Bukti SJ</span></a>
                            </li>
                        @endcan
                    </ul>
                </li>
            @endcanany

            <!-- KEUANGAN & SISTEM -->
            @canany([
                \App\Support\Permission::BILLING_VIEW,
                \App\Support\Permission::MASTER_CUSTOMERS,
                \App\Support\Permission::MASTER_PRODUCTS,
                \App\Support\Permission::MASTER_LOCATIONS,
                \App\Support\Permission::ADMIN_USERS,
                \App\Support\Permission::ADMIN_SEQUENCE,
            ])
                <li class="nav-section mt-2">Keuangan & Sistem</li>
            @endcanany

            @can(\App\Support\Permission::BILLING_VIEW)
                <li class="nav-item {{ request()->is('wms/billing') ? 'active' : '' }}">
                    <a href="/wms/billing" class="nav-link">
                        <i class="bi bi-receipt"></i>
                        <span>Billing & Piutang</span>
                    </a>
                </li>
            @endcan

            @canany([
                \App\Support\Permission::MASTER_CUSTOMERS,
                \App\Support\Permission::MASTER_PRODUCTS,
                \App\Support\Permission::MASTER_LOCATIONS,
                \App\Support\Permission::ADMIN_USERS,
                \App\Support\Permission::ADMIN_SEQUENCE,
            ])
                @php $systemOpen = request()->is('wms/master*') || request()->is('wms/admin*'); @endphp
                <li class="nav-item">
                    <a class="nav-link {{ $systemOpen ? '' : 'collapsed' }}" href="#systemMenu" data-bs-toggle="collapse" aria-expanded="{{ $systemOpen ? 'true' : 'false' }}">
                        <i class="bi bi-gear"></i>
                        <span>Pengaturan Sistem</span>
                        <i class="bi bi-chevron-down ms-auto" style="font-size: 0.8rem; margin-right: 0 !important; transition: transform 0.3s;"></i>
                    </a>
                    <ul class="collapse list-unstyled ps-4 {{ $systemOpen ? 'show' : '' }}" id="systemMenu" data-bs-parent=".sidebar-nav">
                        @can(\App\Support\Permission::MASTER_CUSTOMERS)
                            <li class="nav-item {{ request()->is('wms/master/customers') ? 'active' : '' }}">
                                <a href="/wms/master/customers" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Master Customers</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::MASTER_PRODUCTS)
                            <li class="nav-item {{ request()->is('wms/master/products') ? 'active' : '' }}">
                                <a href="/wms/master/products" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Master Products</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::MASTER_LOCATIONS)
                            <li class="nav-item {{ request()->is('wms/master/locations') ? 'active' : '' }}">
                                <a href="/wms/master/locations" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Master Lokasi Rak</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::ADMIN_USERS)
                            <li class="nav-item {{ request()->is('wms/admin/users') ? 'active' : '' }}">
                                <a href="/wms/admin/users" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Manajemen User</span></a>
                            </li>
                        @endcan
                        @can(\App\Support\Permission::ADMIN_SEQUENCE)
                            <li class="nav-item {{ request()->is('wms/admin/sequence') ? 'active' : '' }}">
                                <a href="/wms/admin/sequence" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Pengaturan Dokumen</span></a>
                            </li>
                        @endcan
                    </ul>
                </li>
            @endcanany
        </ul>

        <!-- User Profile - Fixed Bottom -->
        <div class="sidebar-footer">

        </div>
    </nav>

    <!-- Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay d-lg-none"></div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Navbar -->
        @include('partials.navbar-top')

        <!-- Dynamic Content -->
        <div class="container-fluid p-4">
            @yield('content')
        </div>
    </main>
</div>

<!-- Bootstrap 5 JS Bundle -->
@include('partials.scripts')
</body>
</html>
