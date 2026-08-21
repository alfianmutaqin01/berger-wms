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
    <style>
        /* Global Animations & Premium Hover Effects */
        @keyframes fadeInUp {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes pulseSoft {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        /* Auto-animate all main content */
        main {
            animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* Card Hover Lift */
        .card {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important;
        }

        /* Interactive Buttons */
        .btn {
            transition: all 0.2s ease-in-out;
        }
        .btn:active {
            transform: scale(0.95);
        }

        /* Smooth Table Rows */
        tbody tr {
            transition: background-color 0.2s ease;
        }
        tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.03) !important;
        }

        /* Sidebar Link Hover */
        .nav-link {
            transition: all 0.3s ease;
        }
        .nav-link:hover {
            transform: translateX(5px);
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
        }
        
        /* Alert Pulse */
        .alert-danger, .alert-warning {
            animation: pulseSoft 2s infinite;
        }
    </style>
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
              <li class="nav-item {{ request()->is('sales/customers') ? 'active' : '' }}">
                  <a href="/sales/customers" class="nav-link">
                      <i class="bi bi-people"></i>
                      <span>My Customers</span>
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
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm border-0 py-0 no-print">
            <div class="container-fluid px-4">
                <button type="button" id="sidebarToggle" class="btn btn-light d-lg-none me-3 rounded-circle border-0 text-dark">
                    <i class="bi bi-list"></i>
                </button>
                
                @php
                    $hour = now()->format('H');
                    if ($hour < 11) {
                        $greeting = 'Selamat Pagi';
                        $icon = 'bi-brightness-alt-high text-warning';
                    } elseif ($hour < 15) {
                        $greeting = 'Selamat Siang';
                        $icon = 'bi-brightness-high text-warning';
                    } elseif ($hour < 18) {
                        $greeting = 'Selamat Sore';
                        $icon = 'bi-sunset text-danger';
                    } else {
                        $greeting = 'Selamat Malam';
                        $icon = 'bi-moon-stars text-primary';
                    }
                    $userName = "Sales Representative";
                @endphp
                <h5 class="mb-0 fw-bold text-dark d-flex align-items-center text-truncate" style="letter-spacing: -0.5px; max-width: 50%;">
                    <i class="bi {{ $icon }} me-2 fs-4"></i> <span class="d-none d-md-inline">{{ $greeting }}, {{ $userName }}</span>
                </h5>
                
                <div class="ms-auto d-flex align-items-center gap-2 gap-md-3 flex-nowrap">
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
                    <div class="dropdown d-none d-md-block">
                        <button class="btn rounded-pill px-3 fw-semibold dropdown-toggle text-dark border bg-light shadow-sm" type="button" data-bs-toggle="dropdown" style="font-size: 0.85rem;">
                            <i class="bi bi-shield-check text-primary me-1"></i> Switch Role
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2 rounded-3">
                            <li class="dropdown-header text-uppercase fw-bold text-muted small">Portal Sales</li>
                            <li><a class="dropdown-item py-2" href="/sales/dashboard"><i class="bi bi-phone me-2 text-secondary"></i>Sales</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header text-uppercase fw-bold text-muted small">Portal WMS</li>
                            <li><a class="dropdown-item py-2" href="#" onclick="Swal.fire('Informasi', 'Peran ini akan diarahkan ke Dashboard khusus di versi penuh.', 'info')"><i class="bi bi-boxes me-2 text-secondary"></i>Production</a></li>
                            <li><a class="dropdown-item py-2" href="/wms/dashboard"><i class="bi bi-box-seam me-2 text-secondary"></i>Operator gudang</a></li>
                            <li><a class="dropdown-item py-2" href="/wms/delivery"><i class="bi bi-truck me-2 text-secondary"></i>Logistic</a></li>
                            <li><a class="dropdown-item py-2" href="#" onclick="Swal.fire('Informasi', 'Peran ini akan diarahkan ke Dashboard khusus di versi penuh.', 'info')"><i class="bi bi-person-workspace me-2 text-secondary"></i>Manager</a></li>
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
                            <li><a class="dropdown-item py-2" href="/wms/profile"><i class="bi bi-person-gear me-2 text-secondary"></i>Pengaturan Akun</a></li>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
@stack('scripts')
</body>
</html>








