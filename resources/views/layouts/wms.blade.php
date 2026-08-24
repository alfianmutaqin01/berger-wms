<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head')
</head>
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
            <li class="nav-section">Inbound & Stok</li>
            <li class="nav-item {{ request()->is('wms/inbound/create') ? 'active' : '' }}">
                <a href="/wms/inbound/create" class="nav-link">
                    <i class="bi bi-box-arrow-in-right"></i>
                    <span>Input Produksi</span>
                </a>
            </li>
              <li class="nav-item {{ request()->is('wms/inbound/history') ? 'active' : '' }}">
                  <a href="/wms/inbound/history" class="nav-link">
                      <i class="bi bi-clock-history"></i>
                      <span>Riwayat Produksi</span>
                  </a>
              </li>
                        <li class="nav-item {{ request()->is('wms/inbound/putaway*') ? 'active' : '' }}">
                <a href="/wms/inbound/putaway" class="nav-link">
                    <i class="bi bi-box-seam"></i>
                    <span>Proses Put-away</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/inbound/returns*') ? 'active' : '' }}">
                <a href="/wms/inbound/returns" class="nav-link">
                    <i class="bi bi-arrow-return-left"></i>
                    <span>Penerimaan Retur</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/inbound/verify*') ? 'active' : '' }}">
                <a href="/wms/inbound/verify" class="nav-link">
                    <i class="bi bi-shield-check"></i>
                    <span>Verifikasi Logistik</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/inventory*') ? 'active' : '' }}">
                <a href="/wms/inventory" class="nav-link">
                    <i class="bi bi-boxes"></i>
                    <span>Data Stok (Inventory)</span>
                </a>
            </li>

            <li class="nav-section">Outbound (Pengiriman)</li>
            <li class="nav-item {{ request()->is('wms/outbound/approval') ? 'active' : '' }}">
                <a href="/wms/outbound/approval" class="nav-link">
                    <i class="bi bi-ui-checks-grid"></i>
                    <span>Penerimaan Pesanan</span>
                </a>
            </li>
                        <li class="nav-item {{ request()->is('wms/outbound/picking/batching') ? 'active' : '' }}">
                <a href="/wms/outbound/picking/batching" class="nav-link">
                    <i class="bi bi-collection"></i>
                    <span>Daftar Picking</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/outbound/picking') ? 'active' : '' }}">
                <a href="/wms/outbound/picking" class="nav-link">
                    <i class="bi bi-box-seam"></i>
                    <span>Proses Picking</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/outbound/delivery') ? 'active' : '' }}">
                <a href="/wms/outbound/delivery" class="nav-link">
                    <i class="bi bi-printer"></i>
                    <span>Cetak Surat Jalan</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/outbound/verification') ? 'active' : '' }}">
                <a href="/wms/outbound/verification" class="nav-link">
                    <i class="bi bi-shield-check"></i>
                    <span>Verifikasi Bukti SJ</span>
                </a>
            </li>
            
            <!-- BILLING -->
            <li class="nav-section mt-3">Finance & Penagihan</li>
            <li class="nav-item {{ request()->is('wms/billing') ? 'active' : '' }}">
                <a href="/wms/billing" class="nav-link">
                    <i class="bi bi-receipt"></i>
                    <span>Billing & Piutang</span>
                </a>
            </li>

            <li class="nav-section">Master Data</li>
            <li class="nav-item {{ request()->is('wms/master/customers') ? 'active' : '' }}">
                <a href="/wms/master/customers" class="nav-link">
                    <i class="bi bi-people"></i>
                    <span>Customers</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('wms/master/products') ? 'active' : '' }}">
                <a href="/wms/master/products" class="nav-link">
                    <i class="bi bi-box-seam"></i>
                    <span>Products</span>
                </a>
            </li>
            
            <li class="nav-section">Sistem</li>
            <li class="nav-item {{ request()->is('wms/admin/users') ? 'active' : '' }}">
                <a href="/wms/admin/users" class="nav-link">
                    <i class="bi bi-person-gear"></i>
                    <span>Manajemen User</span>
                  </a>
              </li>
              <li class="nav-item {{ request()->is('wms/admin/sequence') ? 'active' : '' }}">
                  <a href="/wms/admin/sequence" class="nav-link">
                      <i class="bi bi-file-earmark-code"></i>
                      <span>Pengaturan Dokumen</span>
                  </a>
                </a>
            </li>
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






