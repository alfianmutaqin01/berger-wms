<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-POD Konfirmasi Kedatangan</title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .epod-card { max-width: 400px; margin: 0 auto; }
    </style>
</head>
<body class="py-4 px-3">

<div class="epod-card card shadow-lg border-0 rounded-4 overflow-hidden">
    <!-- Header -->
    <div class="bg-primary text-white text-center py-4" style="background-color: #123962 !important;">
        <i class="bi bi-truck fs-1 d-block mb-2"></i>
        <h5 class="fw-bold mb-0">Konfirmasi Kedatangan</h5>
        <p class="small text-white-50 mb-0">Berger Paints E-POD System</p>
    </div>

    <div class="card-body p-4">
        
        @if(session('success'))
            <div class="text-center py-5">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                <h5 class="fw-bold text-dark mt-3 mb-2">Berhasil Terkirim</h5>
                <p class="text-muted small">Konfirmasi barang sampai telah direkam ke sistem. Harap pastikan toko menandatangani Surat Jalan fisik.</p>
                <button class="btn btn-primary w-100 rounded-pill mt-3" onclick="window.close()">Tutup Jendela</button>
            </div>
        @else
            <!-- Rincian Tugas -->
            <div class="mb-4 text-center">
                <h2 class="fw-bold text-primary mb-1">{{ $po_number }}</h2>
                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill">Status: Dalam Perjalanan</span>
            </div>

            <div class="border rounded-3 p-3 bg-light mb-4 text-start">
                <small class="text-muted d-block fw-semibold mb-1">Tujuan Pengiriman:</small>
                <h6 class="fw-bold text-dark mb-1"><i class="bi bi-shop text-primary me-1"></i> CV Bangun Jaya</h6>
                <small class="text-muted d-block"><i class="bi bi-geo-alt me-1"></i> Jl. Raya Kosambi No 12, Karawang</small>
            </div>

            <form action="/epod/{{ $po_number }}/confirm" method="POST">
                @csrf
                <div class="alert alert-warning small p-3 rounded-3 mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Tekan tombol di bawah <b>HANYA</b> jika Anda telah tiba di lokasi toko dan barang mulai diturunkan.
                </div>
                
                <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill py-3 shadow fs-5 fw-bold" id="btnArrived">
                    <i class="bi bi-geo-fill me-2"></i> BARANG SAMPAI
                </button>
            </form>
        @endif

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('btnArrived');
        if(btn) {
            btn.addEventListener('click', function() {
                this.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Memproses...';
                this.classList.add('disabled');
            });
        }
    });
</script>
</body>
</html>