<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head')
    <style>
        /*
          Portal Sales memakai navigasi hibrida (docs/4 §3.1):
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

            /* Ruang untuk bottom nav agar konten terakhir tidak tertutup.
               Angkanya mengikuti tinggi bottom nav yang sudah dirampingkan
               di bawah; kalau salah satu diubah, ubah keduanya. */
            .main-content > .container-fluid {
                padding-top: 1rem !important;
                padding-bottom: 4.5rem !important;
            }
        }
        @media (min-width: 992px) {
            .bottom-nav {
                display: none !important;
            }
        }
        .bottom-nav {
            z-index: 1030;
            /* Bootstrap memberi .navbar padding tegak bawaan; di bar bawah
               itu menambah tinggi tanpa menambah apa pun yang bisa disentuh. */
            padding-top: 0;
            padding-bottom: 0;
        }
        .bottom-nav .nav-link {
            color: #64748b;
            font-size: 0.65rem;
            line-height: 1.15;
            padding: 0.35rem 0.5rem;
            /* Target sentuh minimal 44px sesuai docs/4 §3.1 — INI BATAS
               BAWAH, jangan dikecilkan lagi demi menghemat ruang. Jari
               yang meleset lebih mahal daripada beberapa piksel. */
            min-width: 60px;
            min-height: 44px;
        }
        .bottom-nav .nav-link.active {
            color: #1B4F8A;
            font-weight: 600;
        }
        .bottom-nav .nav-link i {
            font-size: 1.1rem;
        }
        /* Tombol tengah "Pesanan Baru" sengaja tetap menonjol — itu aksi
           utama Sales — tapi lingkarannya dikecilkan dan tidak lagi
           menambah tinggi bar. */
        .bottom-nav .nav-aksi {
            width: 38px;
            height: 38px;
        }
    </style>
</head>
<body>
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
            <li class="nav-section">Sales Order</li>
            <li class="nav-item {{ request()->is('sales/dashboard') ? 'active' : '' }}">
                <a href="/sales/dashboard" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('sales/new-order') ? 'active' : '' }}">
                <a href="/sales/new-order" class="nav-link">
                    <i class="bi bi-plus-square"></i>
                    <span>New Order</span>
                </a>
            </li>
            <li class="nav-item {{ request()->is('sales/my-orders', 'sales/orders/*') ? 'active' : '' }}">
                <a href="/sales/my-orders" class="nav-link">
                    <i class="bi bi-list-check"></i>
                    <span>My Orders</span>
                </a>
            </li>
            {{-- Menu "My Customers" dihapus pada PRD v1.1: pelanggan didaftarkan
                 langsung oleh Manager/Super Admin lewat Master Customer di Portal WMS.
                 Lihat docs/1_prd.md §6.2 F-MASTER-06. --}}
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

{{-- Bottom navigation — hanya tampil di bawah lg (docs/4 §3.2).
     Menu Sales dibatasi 3 sesuai kapasitas rolenya: Dashboard, New Order,
     My Orders. Tidak perlu @can di sini karena seluruh rute /sales sudah
     dipagari middleware portal:sales — hanya Tim Sales yang bisa sampai
     ke layout ini sama sekali. --}}
<nav class="navbar fixed-bottom bg-white border-top shadow-sm bottom-nav no-print">
    <div class="container-fluid d-flex justify-content-around align-items-center px-2">
        <a href="/sales/dashboard" class="nav-link text-center text-decoration-none {{ request()->is('sales/dashboard') ? 'active' : '' }}">
            <i class="bi {{ request()->is('sales/dashboard') ? 'bi-house-fill' : 'bi-house' }}"></i>
            <span class="d-block">Home</span>
        </a>
        <a href="/sales/new-order" class="nav-link text-center text-decoration-none {{ request()->is('sales/new-order') ? 'active' : '' }}">
            <span class="nav-aksi d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" style="background-color: #1B4F8A;">
                <i class="bi bi-plus-lg text-white"></i>
            </span>
            <span class="d-block">Pesanan Baru</span>
        </a>
        <a href="/sales/my-orders" class="nav-link text-center text-decoration-none {{ request()->is('sales/my-orders', 'sales/orders/*') ? 'active' : '' }}">
            <i class="bi {{ request()->is('sales/my-orders', 'sales/orders/*') ? 'bi-clipboard-data-fill' : 'bi-clipboard-data' }}"></i>
            <span class="d-block">Pesanan Saya</span>
        </a>
    </div>
</nav>

<!-- Bootstrap 5 JS Bundle -->
@include('partials.scripts')
</body>
</html>
