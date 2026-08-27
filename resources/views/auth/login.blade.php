<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Berger Paints WMS</title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Inter', sans-serif;
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

        /* Slideshow Layout */
        .image-side {
            position: relative;
            min-height: 100vh;
            overflow: hidden;
            background-color: #0f172a;
        }
        .bg-slide {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transition: opacity 1.5s ease-in-out;
            z-index: 0;
        }
        .bg-slide.active {
            opacity: 1;
        }
        .image-side::after {
            content: '';
            position: absolute;
            top: 0; right: 0; bottom: 0; left: 0;
            background: linear-gradient(to top, rgba(15, 23, 42, 0.95) 0%, rgba(30, 58, 138, 0.4) 100%);
            z-index: 1;
        }
        .hero-text {
            position: absolute;
            bottom: 12%;
            left: 10%;
            z-index: 3;
            color: #ffffff;
            padding-right: 2rem;
        }
        .hero-text h1 {
            font-weight: 800;
            letter-spacing: -1.5px;
            margin-bottom: 0.75rem;
            text-shadow: 0 4px 20px rgba(0,0,0,0.5);
            line-height: 1.1;
        }
        .hero-text p {
            font-weight: 300;
            opacity: 0.9;
            letter-spacing: 0.3px;
            font-size: 1.15rem;
            max-width: 85%;
        }

        /* Typography Polish for Form */
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            letter-spacing: 0.2px;
        }
        .form-control {
            font-size: 0.95rem;
            padding: 0.6rem 1rem;
        }
        .btn-login {
            background-color: #1B4F8A;
            transition: all 0.2s ease;
        }
        .btn-login:hover {
            background-color: #153e6b;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0 min-vh-100">

        <!-- Image Side (Hidden on Mobile) -->
        <div class="col-lg-7 d-none d-lg-block image-side shadow-lg">
            <!-- Background Images -->
            <div class="bg-slide active" style="background-image: url('/images/berger_warehouse.jpg');"></div>
            <div class="bg-slide" style="background-image: url('/images/berger_paint_splash.jpg');"></div>
            <div class="bg-slide" style="background-image: url('/images/login_bg.jpg');"></div>

            <div class="hero-text">
                <h1 class="display-4">Warnai Dunia<br>Operasionalmu.</h1>
                <p>Platform Digital WMS & SOMS terintegrasi. Mengoptimalkan manajemen persediaan dan logistik dengan presisi tinggi.</p>
            </div>
        </div>

        <!-- Form Side -->
        <div class="col-12 col-lg-5 login-side bg-white z-2 shadow-sm">
            <div class="login-form-wrapper">
                <div class="text-center mb-5">
                    <img src="/images/berger_logo.png" alt="Berger Paints Logo" class="img-fluid mb-4" style="max-height: 55px;">
                    <h4 class="fw-bold text-dark mb-1" style="letter-spacing: -0.5px;">Selamat Datang</h4>
                    <p class="text-muted" style="font-size: 0.9rem;">Silakan otentikasi kredensial Anda</p>
                </div>

                @if (session('status'))
                    <div class="alert alert-warning py-2 small mb-4">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger py-2 small mb-4">{{ $errors->first() }}</div>
                @endif

                <form action="{{ route('login.attempt') }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label class="form-label">Email / Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person"></i></span>
                            <input type="email" name="email" value="{{ old('email') }}" class="form-control border-start-0 bg-light" placeholder="nama@bergerpaints.co.id" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Kata Sandi</label>
                            <a href="#" class="text-decoration-none" style="color: #1B4F8A; font-size: 0.85rem; font-weight: 500;">Lupa Sandi?</a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" id="passwordInput" class="form-control border-start-0 border-end-0 bg-light" placeholder="********" required>
                            <button class="btn btn-light border-start-0 border text-muted" type="button" id="togglePassword">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4 form-check mt-3">
                        <input type="checkbox" name="remember" class="form-check-input" id="remember">
                        <label class="form-check-label text-muted" for="remember" style="font-size: 0.85rem;">Biarkan saya tetap masuk</label>
                    </div>

                    @if (config('services.recaptcha.site_key'))
                        <div class="mb-4">
                            <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-callback="onRecaptchaVerified" data-expired-callback="onRecaptchaExpired"></div>
                        </div>
                    @endif

                    <button type="submit" id="submitLoginBtn" class="btn btn-login w-100 py-2 mt-2 fw-semibold text-white shadow-sm" @if(config('services.recaptcha.site_key')) disabled @endif>Masuk ke Sistem</button>
                </form>

                <div class="text-center mt-5 pt-4 border-top">
                    <p class="text-muted mb-0" style="font-size: 0.8rem;">&copy; 2026 PT. Berger Paints Indonesia</p>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Password Toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('passwordInput');
        const eyeIcon = document.getElementById('eyeIcon');

        togglePassword.addEventListener('click', function() {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            eyeIcon.classList.toggle('bi-eye');
            eyeIcon.classList.toggle('bi-eye-slash');
        });

        passwordInput.addEventListener('blur', function() {
            passwordInput.setAttribute('type', 'password');
            eyeIcon.classList.remove('bi-eye-slash');
            eyeIcon.classList.add('bi-eye');
        });

        // Slideshow Logic for Left Column
        let currentSlide = 0;
        const slides = document.querySelectorAll('.bg-slide');

        if (slides.length > 0) {
            setInterval(() => {
                slides[currentSlide].classList.remove('active');
                currentSlide = (currentSlide + 1) % slides.length;
                slides[currentSlide].classList.add('active');
            }, 6000); // Change image every 6 seconds
        }
    });

    // Tombol submit sengaja nonaktif sampai widget "Saya bukan robot" dicentang
    // (PRD §6.1 F-AUTH-02) -- server tetap jadi penegak utama, ini murni UX.
    function onRecaptchaVerified() {
        document.getElementById('submitLoginBtn').removeAttribute('disabled');
    }

    function onRecaptchaExpired() {
        document.getElementById('submitLoginBtn').setAttribute('disabled', 'disabled');
    }
</script>
@if (config('services.recaptcha.site_key'))
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
@endif
</body>
</html>
