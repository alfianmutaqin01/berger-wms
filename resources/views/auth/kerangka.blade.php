{{-- Kerangka halaman akun di luar portal: lupa sandi dan ganti sandi wajib.

     Sengaja TIDAK memakai layouts.wms. Pemilik sandi sementara belum boleh
     melihat menu apa pun, dan pengunjung halaman lupa sandi belum login sama
     sekali — sidebar yang tampil di sana hanya akan menjanjikan pintu yang
     tertutup. Gayanya menyamai halaman login supaya terasa satu rangkaian. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('judul') - Berger Paints WMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; }
        .kartu-akun { max-width: 440px; }
        .btn-akun { background-color: #123962; border-color: #123962; }
        .btn-akun:hover { background-color: #0d2b4a; border-color: #0d2b4a; }
    </style>
</head>
<body>
@include('partials.pemuat')

<div class="min-vh-100 d-flex align-items-center justify-content-center p-3">
    <div class="card border-0 shadow-sm rounded-4 w-100 kartu-akun">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <img src="/images/berger_logo.png" alt="Berger Paints" class="img-fluid mb-3" style="max-height: 44px;">
                <h5 class="fw-bold text-dark mb-1">@yield('judul')</h5>
                @hasSection('sub')
                    <p class="text-muted small mb-0">@yield('sub')</p>
                @endif
            </div>

            @yield('isi')
        </div>
    </div>
</div>
</body>
</html>
