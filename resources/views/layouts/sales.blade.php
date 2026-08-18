<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1B4F8A">
    <title>@yield('title', 'Sales Portal') - Berger Paints</title>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
        <!-- Anti-FOUC Script -->
    <script>
        const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
    </script>

    <style>
        :root {
            --color-primary: #1B4F8A;
            --color-secondary: #E8871E;
            --bg-sidebar: #71410f;
            --bg-navbar: #E8871E;
            --font-family: 'Inter', sans-serif;
        }

        body {
            font-family: var(--font-family);
            background-color: var(--bs-body-bg);
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        /* Logo Handling */
        .logo-wrapper img {
            transition: transform 0.3s ease;
        }
        .logo-wrapper:hover img {
            transform: scale(1.05);
        }
        
        [data-bs-theme="dark"] .logo-wrapper {
            background: rgba(255, 255, 255, 0.85);
            padding: 8px 15px;
            border-radius: 8px;
            display: inline-block;
            backdrop-filter: blur(4px);
        }

        /* Sidebar Styling */
        #sidebar {
            width: 250px;
            background-color: var(--bg-sidebar);
            color: #fff;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            z-index: 1040;
        }

        #sidebar.collapsed {
            width: 0;
            overflow: hidden;
        }

        #sidebar .sidebar-header {
            padding: 1.5rem 1rem;
            text-align: center;
            background-color: rgba(0,0,0,0.1);
        }

        #sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.8rem 1.5rem;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        #sidebar .nav-link:hover, #sidebar .nav-link.active {
            color: #fff;
            background-color: rgba(255,255,255,0.05);
            border-left: 3px solid var(--color-secondary); transform: translateX(5px);
        }

        #sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* Main Content Styling */
        #content-wrapper {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            transition: all 0.3s;
        }

        /* Top Navbar */
        .top-navbar {
            background-color: var(--bg-navbar);
            color: white;
            padding: 0.5rem 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1030;
        }
        
        .top-navbar .btn-link {
            color: white;
            text-decoration: none;
        }
        
        .top-navbar .dropdown-menu {
            position: absolute;
        }

        /* Content Area */
        .main-content {
            padding: 1.5rem;
            flex-grow: 1;
        }

        /* Dark Mode Toggle inside Navbar */
        .theme-toggle-nav {
            background: transparent;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
        }

        @media (max-width: 991.98px) {
            #sidebar {
                position: fixed;
                height: 100vh;
                left: -250px;
            }
            #sidebar.show {
                left: 0;
            }
        }
        
        /* Dark Mode Body Overrides */
        [data-bs-theme="dark"] .bg-white {
            background-color: #212529 !important;
        }
        [data-bs-theme="dark"] .card {
            background-color: #2b3035;
            border-color: #495057;
        }
    </style>
    @stack('styles')
</head>
<body>

    <!-- Sidebar -->
    <nav id="sidebar" class="d-lg-flex">
                <div class="sidebar-header logo-container">
            <div class="logo-wrapper">
                <img src="/images/berger_logo.png" alt="Berger Paints Logo" class="img-fluid" style="max-height: 40px;">
            </div>
            <div class="mt-2">
                <span class="badge bg-primary opacity-75 fw-normal" style="font-size: 0.7rem;">SALES PORTAL</span>
            </div>
        </div>
                        <ul class="nav flex-column mt-3 flex-grow-1">
            <li class="nav-item">
                <a href="/sales/dashboard" class="nav-link {{ request()->is('sales/dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            
            <li class="nav-item mt-3 mb-1 px-3 text-uppercase text-white-50" style="font-size: 0.75rem; font-weight: 600;">
                MANAJEMEN ORDER
            </li>
            <li class="nav-item">
                <a href="/sales/new-order" class="nav-link {{ request()->is('sales/new-order*') ? 'active' : '' }}"><i class="bi bi-plus-square"></i> Buat Order Baru</a>
            </li>
            <li class="nav-item">
                <a href="/sales/my-orders" class="nav-link {{ request()->is('sales/my-orders*') ? 'active' : '' }}"><i class="bi bi-list-check"></i> Pesanan Saya</a>
            </li>
            <li class="nav-item">
                <a href="/sales/tracking" class="nav-link {{ request()->is('sales/tracking*') ? 'active' : '' }}"><i class="bi bi-geo-alt"></i> Lacak Pesanan</a>
            </li>
        </ul>
        <div class="p-3 text-center border-top border-secondary">
            <small class="text-muted">Regional Jakarta</small>
        </div>
    </nav>

    <!-- Content Wrapper -->
    <div id="content-wrapper">
        <!-- Top Navbar -->
        <nav class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <button type="button" id="sidebarCollapse" class="btn btn-link px-2">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h5 class="mb-0 ms-2 fw-semibold d-none d-sm-block">@yield('page_title', 'Dashboard')</h5>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button class="theme-toggle-nav" id="themeToggle" aria-label="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
                </button>
                
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn btn-link position-relative px-1" data-bs-toggle="dropdown">
                        <i class="bi bi-bell-fill fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
                            5
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow" style="width: 300px;">
                        <h6 class="dropdown-header">Notifikasi</h6>
                        <a class="dropdown-item py-2" href="#">
                            <small class="fw-bold d-block">Pesanan Baru</small>
                            <small class="text-muted">PO-00145 menunggu approval.</small>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center small" href="#">Lihat Semua</a>
                    </div>
                </div>

                <!-- User Profile -->
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                        <img src="https://ui-avatars.com/api/?name=Sales+Rep&background=E8871E&color=fff" alt="User" class="rounded-circle me-2" width="32" height="32">
                        <span class="d-none d-md-inline small">Sales Rep</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>Profil</a></li>
                        <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Pengaturan</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="/"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            @yield('content')
        </main>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Sidebar Toggle
            const sidebarCollapse = document.getElementById('sidebarCollapse');
            const sidebar = document.getElementById('sidebar');
            
            sidebarCollapse.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    sidebar.classList.toggle('show');
                } else {
                    sidebar.classList.toggle('collapsed');
                }
            });

            // Theme Toggle
            const themeToggleBtn = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;

            const currentTheme = htmlElement.getAttribute('data-bs-theme');
            updateIcon(currentTheme);

            themeToggleBtn.addEventListener('click', () => {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                setTheme(currentTheme === 'light' ? 'dark' : 'light');
            });

            function setTheme(theme) {
                htmlElement.setAttribute('data-bs-theme', theme);
                localStorage.setItem('theme', theme);
                updateIcon(theme);
            }
            
            function updateIcon(theme) {
                if (theme === 'dark') {
                    themeIcon.classList.remove('bi-moon-stars-fill');
                    themeIcon.classList.add('bi-sun-fill');
                    themeIcon.style.color = '#E8871E';
                } else {
                    themeIcon.classList.remove('bi-sun-fill');
                    themeIcon.classList.add('bi-moon-stars-fill');
                    themeIcon.style.color = '';
                }
            }
        });
    </script>
    @stack('scripts')
</body>
</html>





