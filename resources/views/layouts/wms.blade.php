<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head')
</head>
@php
    $role = request('role', 'admin');
    $isAdmin = ($role === 'admin');
@endphp
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
            <li class="nav-item {{ request()->is('wms/dashboard') ? 'active' : '' }}">
                <a href="/wms/dashboard" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/reports') ? 'active' : '' }}">
                <a href="/wms/reports" class="nav-link">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>Laporan & Analisis</span>
                </a>
            </li>

            <!-- INBOUND & STOK -->
            @if($isAdmin)
                <li class="nav-section mt-2">Inventory Management</li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('wms/inbound*') || request()->is('wms/inventory*') ? '' : 'collapsed' }}" href="#inboundMenu" data-bs-toggle="collapse" aria-expanded="{{ request()->is('wms/inbound*') || request()->is('wms/inventory*') ? 'true' : 'false' }}">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span>Inbound & Stok</span>
                        <i class="bi bi-chevron-down ms-auto" style="font-size: 0.8rem; margin-right: 0 !important; transition: transform 0.3s;"></i>
                    </a>
                    <ul class="collapse list-unstyled ps-4 {{ request()->is('wms/inbound*') || request()->is('wms/inventory*') ? 'show' : '' }}" id="inboundMenu" data-bs-parent=".sidebar-nav">
                        <li class="nav-item {{ request()->is('wms/inbound/create') ? 'active' : '' }}">
                            <a href="/wms/inbound/create" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Input Produksi</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/inbound/history') ? 'active' : '' }}">
                            <a href="/wms/inbound/history" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Riwayat Produksi</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/inbound/putaway*') ? 'active' : '' }}">
                            <a href="/wms/inbound/putaway" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Proses Put-away</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/inbound/returns*') ? 'active' : '' }}">
                            <a href="/wms/inbound/returns" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Penerimaan Retur</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/inbound/verify*') ? 'active' : '' }}">
                            <a href="/wms/inbound/verify" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Verifikasi Logistik</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/inventory*') ? 'active' : '' }}">
                            <a href="/wms/inventory" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Data Stok (Inventory)</span></a>
                        </li>
                    </ul>
                </li>
            @else
                <li class="nav-section mt-2">Inbound & Stok</li>
                <li class="nav-item {{ request()->is('wms/inbound/create') ? 'active' : '' }}">
                    <a href="/wms/inbound/create" class="nav-link"><i class="bi bi-box-arrow-in-right"></i><span>Input Produksi</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/inbound/history') ? 'active' : '' }}">
                    <a href="/wms/inbound/history" class="nav-link"><i class="bi bi-clock-history"></i><span>Riwayat Produksi</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/inbound/putaway*') ? 'active' : '' }}">
                    <a href="/wms/inbound/putaway" class="nav-link"><i class="bi bi-box-seam"></i><span>Proses Put-away</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/inbound/returns*') ? 'active' : '' }}">
                    <a href="/wms/inbound/returns" class="nav-link"><i class="bi bi-arrow-return-left"></i><span>Penerimaan Retur</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/inbound/verify*') ? 'active' : '' }}">
                    <a href="/wms/inbound/verify" class="nav-link"><i class="bi bi-shield-check"></i><span>Verifikasi Logistik</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/inventory*') ? 'active' : '' }}">
                    <a href="/wms/inventory" class="nav-link"><i class="bi bi-boxes"></i><span>Data Stok (Inventory)</span></a>
                </li>
            @endif

            <!-- OUTBOUND DROPDOWN -->
            @if($isAdmin)
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('wms/outbound*') ? '' : 'collapsed' }}" href="#outboundMenu" data-bs-toggle="collapse" aria-expanded="{{ request()->is('wms/outbound*') ? 'true' : 'false' }}">
                        <i class="bi bi-truck"></i>
                        <span>Outbound (Kirim)</span>
                        <i class="bi bi-chevron-down ms-auto" style="font-size: 0.8rem; margin-right: 0 !important; transition: transform 0.3s;"></i>
                    </a>
                    <ul class="collapse list-unstyled ps-4 {{ request()->is('wms/outbound*') ? 'show' : '' }}" id="outboundMenu" data-bs-parent=".sidebar-nav">
                        <li class="nav-item {{ request()->is('wms/outbound/approval') ? 'active' : '' }}">
                            <a href="/wms/outbound/approval" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Terima Pesanan</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/outbound/picking/batching') ? 'active' : '' }}">
                            <a href="/wms/outbound/picking/batching" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Daftar Picking</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/outbound/picking') ? 'active' : '' }}">
                            <a href="/wms/outbound/picking" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Proses Picking</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/outbound/delivery') ? 'active' : '' }}">
                            <a href="/wms/outbound/delivery" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Cetak Surat Jalan</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/outbound/verification') ? 'active' : '' }}">
                            <a href="/wms/outbound/verification" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Verifikasi Bukti SJ</span></a>
                        </li>
                    </ul>
                </li>
            @else
                <li class="nav-section mt-2">Outbound (Kirim)</li>
                <li class="nav-item {{ request()->is('wms/outbound/approval') ? 'active' : '' }}">
                    <a href="/wms/outbound/approval" class="nav-link"><i class="bi bi-ui-checks-grid"></i><span>Terima Pesanan</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/outbound/picking/batching') ? 'active' : '' }}">
                    <a href="/wms/outbound/picking/batching" class="nav-link"><i class="bi bi-collection"></i><span>Daftar Picking</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/outbound/picking') ? 'active' : '' }}">
                    <a href="/wms/outbound/picking" class="nav-link"><i class="bi bi-box-seam"></i><span>Proses Picking</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/outbound/delivery') ? 'active' : '' }}">
                    <a href="/wms/outbound/delivery" class="nav-link"><i class="bi bi-printer"></i><span>Cetak Surat Jalan</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/outbound/verification') ? 'active' : '' }}">
                    <a href="/wms/outbound/verification" class="nav-link"><i class="bi bi-shield-check"></i><span>Verifikasi Bukti SJ</span></a>
                </li>
            @endif

            <!-- FINANCE (Single Item) -->
            <li class="nav-section mt-2">Keuangan & Sistem</li>
            <li class="nav-item {{ request()->is('wms/billing') ? 'active' : '' }}">
                <a href="/wms/billing" class="nav-link">
                    <i class="bi bi-receipt"></i>
                    <span>Billing & Piutang</span>
                </a>
            </li>

            <!-- SYSTEM DROPDOWN (Master Data & Settings) -->
            @if($isAdmin)
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('wms/master*') || request()->is('wms/admin*') ? '' : 'collapsed' }}" href="#systemMenu" data-bs-toggle="collapse" aria-expanded="{{ request()->is('wms/master*') || request()->is('wms/admin*') ? 'true' : 'false' }}">
                        <i class="bi bi-gear"></i>
                        <span>Pengaturan Sistem</span>
                        <i class="bi bi-chevron-down ms-auto" style="font-size: 0.8rem; margin-right: 0 !important; transition: transform 0.3s;"></i>
                    </a>
                    <ul class="collapse list-unstyled ps-4 {{ request()->is('wms/master*') || request()->is('wms/admin*') ? 'show' : '' }}" id="systemMenu" data-bs-parent=".sidebar-nav">
                        <li class="nav-item {{ request()->is('wms/master/customers') ? 'active' : '' }}">
                            <a href="/wms/master/customers" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Master Customers</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/master/products') ? 'active' : '' }}">
                            <a href="/wms/master/products" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Master Products</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/admin/users') ? 'active' : '' }}">
                            <a href="/wms/admin/users" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Manajemen User</span></a>
                        </li>
                        <li class="nav-item {{ request()->is('wms/admin/sequence') ? 'active' : '' }}">
                            <a href="/wms/admin/sequence" class="nav-link py-2"><i class="bi bi-dot fs-4" style="margin-left:-8px"></i><span>Pengaturan Dokumen</span></a>
                        </li>
                    </ul>
                </li>
            @else
                <li class="nav-section mt-2">Pengaturan Sistem</li>
                <li class="nav-item {{ request()->is('wms/master/customers') ? 'active' : '' }}">
                    <a href="/wms/master/customers" class="nav-link"><i class="bi bi-people"></i><span>Master Customers</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/master/products') ? 'active' : '' }}">
                    <a href="/wms/master/products" class="nav-link"><i class="bi bi-box-seam"></i><span>Master Products</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/admin/users') ? 'active' : '' }}">
                    <a href="/wms/admin/users" class="nav-link"><i class="bi bi-person-gear"></i><span>Manajemen User</span></a>
                </li>
                <li class="nav-item {{ request()->is('wms/admin/sequence') ? 'active' : '' }}">
                    <a href="/wms/admin/sequence" class="nav-link"><i class="bi bi-file-earmark-code"></i><span>Pengaturan Dokumen</span></a>
                </li>
            @endif
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
        @include('partials.navbar-top', ['userName' => 'Khoirun Nisa', 'userLabel' => 'Admin Gudang', 'userInitials' => 'KN'])

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






