<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SOMS') - Berger Paints</title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Custom CSS based on original SOMS -->
    <link href="/css/soms-style.css?v={{ time() }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
<div class="wrapper">
    <!-- Sidebar -->
    <nav id="sidebar" class="sidebar">
        <!-- Brand -->
        <div class="sidebar-header">
            <a href="/sales/dashboard" class="sidebar-brand">
                <i class="bi bi-droplet-half"></i> Berger SOMS
            </a>
            <button type="button" class="btn-close d-lg-none" id="sidebarClose" aria-label="Close"></button>
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
            <li class="nav-item {{ request()->is('sales/tracking') ? 'active' : '' }}">
                <a href="/sales/tracking" class="nav-link">
                    <i class="bi bi-geo-alt"></i>
                    <span>Order Tracking</span>
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
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm border-0 py-0 no-print">
            <div class="container-fluid px-4">
                <button type="button" id="sidebarToggle" class="btn btn-light d-lg-none me-3 rounded-circle border-0 text-dark">
                    <i class="bi bi-list"></i>
                </button>
                <h5 class="mb-0 fw-bold text-dark" style="letter-spacing: -0.5px;">@yield('page_title', 'Dashboard')</h5>
                
                <div class="ms-auto d-flex align-items-center gap-3">
                    <!-- Notification -->
                    <div class="dropdown">
                        <button class="btn btn-light position-relative rounded-circle d-flex align-items-center justify-content-center border" data-bs-toggle="dropdown" style="width: 40px; height: 40px; background-color: #f8f9fa;">
                            <i class="bi bi-bell text-secondary" style="font-size: 1.1rem;"></i>
                            <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-2 border-white rounded-circle" style="margin-top: 5px; margin-left: -5px;">
                                <span class="visually-hidden">New alerts</span>
                            </span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-3 mt-2" style="width: 320px;">
                            <div class="dropdown-header d-flex justify-content-between align-items-center border-bottom pb-2">
                                <h6 class="mb-0 fw-bold text-dark">Notifikasi</h6>
                                <span class="badge bg-primary rounded-pill">1 Baru</span>
                            </div>
                            <div class="p-2">
                                <a class="dropdown-item rounded px-3 py-2" href="#">
                                    <small class="fw-bold d-block text-primary mb-1">Pesanan Baru</small>
                                    <small class="text-muted text-wrap">PO-00145 sedang menunggu approval.</small>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Role Switcher (Mockup) -->
                    <div class="dropdown">
                        <button class="btn rounded-pill px-3 fw-semibold dropdown-toggle text-dark border bg-light shadow-sm" type="button" data-bs-toggle="dropdown" style="font-size: 0.85rem;">
                            <i class="bi bi-shield-check text-primary me-1"></i> Switch Role
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2 rounded-3">
                            <li class="dropdown-header text-uppercase fw-bold text-muted small">Portal Sales</li>
                            <li><a class="dropdown-item py-2" href="/sales/dashboard"><i class="bi bi-phone me-2 text-secondary"></i>Sales</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header text-uppercase fw-bold text-muted small">Portal WMS</li>
                            <li><a class="dropdown-item py-2" href="/wms/approval"><i class="bi bi-boxes me-2 text-secondary"></i>Production</a></li>
                            <li><a class="dropdown-item py-2" href="/wms/dashboard"><i class="bi bi-box-seam me-2 text-secondary"></i>Operator gudang</a></li>
                            <li><a class="dropdown-item py-2" href="/wms/delivery"><i class="bi bi-truck me-2 text-secondary"></i>Logistic</a></li>
                            <li><a class="dropdown-item py-2" href="/wms/approval"><i class="bi bi-person-workspace me-2 text-secondary"></i>Manager</a></li>
                            <li><a class="dropdown-item py-2" href="/wms/admin/users"><i class="bi bi-cpu me-2 text-secondary"></i>Super Admin</a></li>
                        </ul>
                    </div>

                    <!-- User Account / Logout -->
                    <div class="dropdown">
                        <button class="btn rounded-pill p-1 pe-3 fw-semibold dropdown-toggle text-dark border bg-light shadow-sm d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                SR
                            </div>
                            <span class="d-none d-md-inline small">Sales Rep</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2 rounded-3">
                            <li><h6 class="dropdown-header text-dark fw-bold">Sales Rep</h6></li>
                            <li><a class="dropdown-item py-2" href="#"><i class="bi bi-person me-2 text-secondary"></i>Profil Saya</a></li>
                            <li><a class="dropdown-item py-2" href="#"><i class="bi bi-gear me-2 text-secondary"></i>Pengaturan</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger fw-semibold" href="/"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>

                </div>
            </div>
        </nav>

        <!-- Dynamic Content -->
        <div class="container-fluid p-4">
            @yield('content')
        </div>
    </main>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
        }

        if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
        if (sidebarClose) sidebarClose.addEventListener('click', toggleSidebar);
        if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);
    });
</script>
@stack('scripts')
</body>
</html>









