<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head')
    <style>
        /*
          Portal Sales memakai navigasi hibrida (docs/4 A 3.1):
            < 992px  -> bottom navigation (Sales bekerja dari HP di lapangan)
            >= 992px -> sidebar, konsisten dengan Portal WMS

          Sidebar mobile sengaja dimatikan total di bawah lg — bukan sekadar
          disembunyikan lewat transform seperti di WMS — supaya tidak ada dua
          navigasi yang bisa terbuka bersamaan di layar kecil.
        */
        @media (max-width: 991.98px) {
            #sidebar,
            #sidebarOverlay {
                display: none !important;
            }

            /*
              Tombol hamburger DIMATIKAN di sini. Ia hanya membuka #sidebar,
              dan sidebar itu display:none di lebar ini — jadi menekannya
              tidak melakukan apa pun. Tombol mati yang tetap memakan ruang
              paling berharga di layar HP. Partial navbar-top dipakai bersama
              Portal WMS (yang sidebarnya memang bisa dibuka), karena itu
              dimatikan lewat CSS di sini, bukan dihapus dari partial-nya.
            */
            #sidebarToggle {
                display: none !important;
            }

            /* Navbar atas dirampingkan: di layar 360px, tinggi 65px dan
               tombol 40-42px menyisakan sedikit sekali ruang untuk isi. */
            .main-content > .navbar {
                height: 52px;
                padding: 0 0.75rem !important;
            }
            .main-content > .navbar .container-fluid {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
            .main-content > .navbar .btn {
                width: 34px !important;
                height: 34px !important;
                font-size: 0.85rem;
            }
            .main-content > .navbar .ms-auto {
                gap: 0.5rem !important;
            }

            /* Ruang dan padding layar mobile yang pas:
               Mengurangi padding berlebih di ponsel agar konten tidak terpotong. */
            .main-content > .container-fluid {
                padding-top: 0.75rem !important;
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
                padding-bottom: 5.25rem !important;
            }
        }
        @media (min-width: 992px) {
            .bottom-nav {
                display: none !important;
            }
        }
        .bottom-nav {
            z-index: 1030;
            padding-top: 0;
            padding-bottom: 0;
            height: 56px;
        }
        .bottom-nav .nav-link {
            color: #64748b;
            font-size: 0.65rem;
            line-height: 1.15;
            padding: 0.3rem 0.25rem;
            min-width: 54px;
            min-height: 48px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease;
        }
        .bottom-nav .nav-link.active {
            color: #1B4F8A;
            font-weight: 600;
        }
        .bottom-nav .nav-link i {
            font-size: 1.15rem;
            line-height: 1;
            margin-bottom: 2px;
        }
    </style>
</head>
<body>
@include('partials.pemuat')
<div class="wrapper">
    <!-- Sidebar (desktop >= 992px) -->
    <nav id="sidebar" class="sidebar">
        <!-- Brand -->
        <div class="sidebar-header d-flex justify-content-between align-items-center w-100">
            <a href="/sales/dashboard" class="sidebar-brand text-decoration-none d-flex align-items-center">
                <i class="bi bi-droplet-half"></i> <span class="ms-2">Berger SOMS</span>
            </a>
            <button type="button" class="btn btn-link text-white p-0 d-none d-lg-block" id="sidebarToggleDesktop">
                <i class="bi bi-list fs-4"></i>
            </button>
            <button type="button" class="btn-close btn-close-white d-lg-none" id="sidebarClose" aria-label="Close"></button>
        </div>

        <ul class="sidebar-nav">
            <li class="nav-section">{{ __('Sales Order') }}</li>
            <li class="nav-item {{ request()->is('sales/dashboard') ? 'active' : '' }}">
                <a href="/sales/dashboard" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    <span>{{ __('Dashboard') }}</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('sales/new-order') ? 'active' : '' }}">
                <a href="/sales/new-order" class="nav-link">
                    <i class="bi bi-plus-square"></i>
                    <span>{{ __('New Order') }}</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('sales/my-orders', 'sales/orders/*') ? 'active' : '' }}">
                <a href="/sales/my-orders" class="nav-link">
                    <i class="bi bi-list-check"></i>
                    <span>{{ __('My Orders') }}</span>
                </a>
            </li>
            {{-- Menu "My Customers" dihapus pada PRD v1.1: pelanggan didaftarkan
                 langsung oleh Manager/Super Admin lewat Master Customer di Portal WMS.
                 Lihat docs/1_prd.md A 6.2 F-MASTER-06. --}}

            {{-- PERMINTAAN MATERIAL, satu-satunya layar sisi WMS yang dibuka
                 untuk Sales. Contoh untuk calon pelanggan diminta Sales, bukan
                 Produksi, dan tanpa pintu ini izin MRF-nya tidak punya jalan
                 masuk sama sekali. Yang dilihatnya berhenti di divisinya. --}}
            @can(\App\Support\Permission::MRF_VIEW)
                <li class="nav-section">{{ __('Permintaan Material') }}</li>
                <li class="nav-item {{ request()->is('wms/mrf*') ? 'active' : '' }}">
                    <a href="/wms/mrf" class="nav-link">
                        <i class="bi bi-clipboard2-check"></i>
                        <span>{{ __('MRF') }}</span>
                    </a>
                </li>
                @can(\App\Support\Permission::MRF_RECEIVE)
                    <li class="nav-item {{ request()->is('wms/material-produksi*') ? 'active' : '' }}">
                        <a href="/wms/material-produksi" class="nav-link">
                            <i class="bi bi-box-seam"></i>
                            <span>{{ __('MRF Picked') }}</span>
                        </a>
                    </li>
                @endcan
            @endcan
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

{{-- Bottom navigation — hanya tampil di layar smartphone/tablet (< 992px).
     Menyediakan akses instan ke Dashboard, Pesanan Baru, Pesanan Saya, dan MRF (Permintaan Material). --}}
<nav class="navbar fixed-bottom bg-white border-top shadow-sm bottom-nav">
    <div class="container-fluid d-flex justify-content-around align-items-center px-1">
        <a href="/sales/dashboard" class="nav-link text-center text-decoration-none {{ request()->is('sales/dashboard') ? 'active' : '' }}">
            <i class="bi {{ request()->is('sales/dashboard') ? 'bi-house-fill' : 'bi-house' }}"></i>
            <span class="d-block">{{ __('Home') }}</span>
        </a>
        <a href="/sales/new-order" class="nav-link text-center text-decoration-none {{ request()->is('sales/new-order') ? 'active' : '' }}">
            <i class="bi {{ request()->is('sales/new-order') ? 'bi-plus-circle-fill' : 'bi-plus-circle' }}" style="{{ request()->is('sales/new-order') ? 'color: #1B4F8A;' : 'color: #0284c7;' }}; font-size: 1.22rem;"></i>
            <span class="d-block">{{ __('Pesanan Baru') }}</span>
        </a>
        <a href="/sales/my-orders" class="nav-link text-center text-decoration-none {{ request()->is('sales/my-orders', 'sales/orders/*') ? 'active' : '' }}">
            <i class="bi {{ request()->is('sales/my-orders', 'sales/orders/*') ? 'bi-clipboard-data-fill' : 'bi-clipboard-data' }}"></i>
            <span class="d-block">{{ __('Pesanan Saya') }}</span>
        </a>
        @can(\App\Support\Permission::MRF_VIEW)
        <a href="/wms/mrf" class="nav-link text-center text-decoration-none {{ request()->is('wms/mrf*') ? 'active' : '' }}">
            <i class="bi {{ request()->is('wms/mrf*') ? 'bi-clipboard2-check-fill' : 'bi-clipboard2-check' }}"></i>
            <span class="d-block">{{ __('MRF') }}</span>
        </a>
        @endcan
    </div>
</nav>

<!-- Bootstrap 5 JS Bundle -->
@include('partials.scripts')
</body>
</html>
