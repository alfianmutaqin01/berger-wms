<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Berger Paints</title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        /* Hide default browser password reveal icon */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
        }
        input[type="password"]::-webkit-reveal {
            display: none;
        }
        .login-side {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-form-wrapper {
            width: 100%;
            max-width: 420px;
            padding: 2rem;
        }
        .image-side {
            background: url('/images/login_bg.jpg') no-repeat center center;
            background-size: cover;
            min-height: 100vh;
            position: relative;
        }
        .image-side::after {
            content: '';
            position: absolute;
            top: 0; right: 0; bottom: 0; left: 0;
            background: linear-gradient(to top, rgba(27, 79, 138, 0.9) 0%, rgba(27, 79, 138, 0) 50%);
        }
        .hero-text {
            position: absolute;
            bottom: 10%;
            left: 10%;
            z-index: 3;
            color: #ffffff;
        }
        .hero-text h1 {
            font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 0.5rem;
            text-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .hero-text p {
            font-weight: 300;
            opacity: 0.9;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0 min-vh-100">
        
        <!-- Image Side (Hidden on Mobile) -->
        <div class="col-lg-7 d-none d-lg-block image-side shadow-lg z-1">
            <div class="hero-text">
                <h1 class="display-4">Warnai Duniamu</h1>
                <p class="fs-5">Sistem Terintegrasi Berger Paints WMS & SOMS</p>
            </div>
        </div>

        <!-- Form Side -->
        <div class="col-12 col-lg-5 login-side bg-white z-2 shadow-sm">
            <div class="login-form-wrapper">
                <div class="text-center mb-5">
                    <img src="/images/berger_logo.png" alt="Berger Paints Logo" class="img-fluid mb-4" style="max-height: 55px;">
                    <h4 class="fw-bold text-dark mb-1">Selamat Datang</h4>
                    <p class="text-muted small">Silakan masuk ke portal Anda</p>
                </div>

                <form action="#" method="POST" onsubmit="event.preventDefault(); window.location.href = document.getElementById('roleSelect').value;">
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-semibold">Email / Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person"></i></span>
                            <input type="email" class="form-control border-start-0 bg-light" placeholder="nama@bergerpaints.co.id" required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label text-muted small fw-semibold mb-0">Password</label>
                            <a href="#" class="text-decoration-none small" style="color: #1B4F8A;">Lupa Sandi?</a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                            <input type="password" id="passwordInput" class="form-control border-start-0 border-end-0 bg-light" placeholder="********" required>
                            <button class="btn btn-light border-start-0 border text-muted" type="button" id="togglePassword">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-semibold">Pilih Portal (Mockup Role)</label>
                        <select class="form-select bg-light" id="roleSelect" required>
                            <option value="/sales/dashboard">Sales</option>
                            <option value="/wms/approval">Production</option>
                            <option value="/wms/dashboard">Operator gudang</option>
                            <option value="/wms/delivery">Logistic</option>
                            <option value="/wms/approval">Manager</option>
                            <option value="/wms/admin/users">Super Admin</option>
                        </select>
                    </div>

                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="remember">
                        <label class="form-check-label small text-muted" for="remember">Ingat saya</label>
                    </div>
                    
                    <button type="submit" class="btn w-100 py-2 fw-semibold text-white shadow-sm" style="background-color: #1B4F8A;">Masuk Sistem</button>
                </form>
                
                <div class="text-center mt-5 pt-3 border-top">
                    <p class="text-muted small mb-0">&copy; 2026 PT. Berger Paints Indonesia</p>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('passwordInput');
        const eyeIcon = document.getElementById('eyeIcon');

        // Toggle password visibility on click
        togglePassword.addEventListener('click', function() {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            eyeIcon.classList.toggle('bi-eye');
            eyeIcon.classList.toggle('bi-eye-slash');
        });

        // Hide password when input loses focus
        passwordInput.addEventListener('blur', function() {
            passwordInput.setAttribute('type', 'password');
            eyeIcon.classList.remove('bi-eye-slash');
            eyeIcon.classList.add('bi-eye');
        });
    });
</script>
</body>
</html>

