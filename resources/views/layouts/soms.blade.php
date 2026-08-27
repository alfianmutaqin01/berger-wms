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
            <li class="nav-item {{ request()->is('sales/my-orders') ? 'active' : '' }}">
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
        @include('partials.navbar-top', ['userName' => 'Budi Santoso', 'userLabel' => 'Sales Representative', 'userInitials' => 'BS'])

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








