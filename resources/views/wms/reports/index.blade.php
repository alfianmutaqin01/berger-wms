@extends('layouts.wms')

@section('title', 'Laporan & Ekspor')
@section('page_title', 'Laporan & Analisis')

@section('content')
<div class="row mb-4 align-items-center">
    <div class="col-12 col-lg-6">
        <h4 class="fw-bold text-dark mb-0">Sentral Laporan Gudang</h4>
        <p class="text-muted mb-0">Unduh data operasional secara real-time untuk evaluasi kinerja (Non-Finansial).</p>
    </div>
    <div class="col-12 col-lg-6 mt-3 mt-lg-0">
        <div class="card border-0 shadow-sm rounded-pill p-2 bg-white">
            <div class="d-flex align-items-center">
                <span class="text-muted fw-bold ms-3 me-2 small">Rentang Waktu:</span>
                <input type="date" class="form-control border-0 bg-light rounded-pill px-3 mx-1" value="2026-08-01">
                <span class="text-muted fw-bold mx-1">-</span>
                <input type="date" class="form-control border-0 bg-light rounded-pill px-3 mx-1" value="2026-08-31">
                <button class="btn btn-primary rounded-circle ms-2 shadow-sm d-flex justify-content-center align-items-center flex-shrink-0" style="width: 40px; height: 40px; padding: 0;" title="Terapkan Filter">
                    <i class="bi bi-funnel-fill"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Laporan 1 -->
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 rounded-4 report-card transition-all">
            <div class="card-body p-4 d-flex flex-column">
                <div class="rounded-3 bg-primary bg-opacity-10 d-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                    <i class="bi bi-truck text-primary fs-3"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">Pengiriman Selesai</h5>
                <p class="text-muted small mb-4 flex-grow-1">Rekapitulasi seluruh Surat Jalan yang telah dikonfirmasi tuntas (Delivery Complete) berdasarkan rentang waktu.</p>
                <div class="d-grid gap-2">
                    <button class="btn btn-light border fw-semibold text-primary" onclick="alert('Mempersiapkan File Excel...')"><i class="bi bi-file-earmark-excel me-2"></i>Unduh Excel</button>
                    <button class="btn btn-light border fw-semibold text-danger" onclick="alert('Mempersiapkan File PDF...')"><i class="bi bi-file-earmark-pdf me-2"></i>Unduh PDF</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Laporan 2 -->
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 rounded-4 report-card transition-all">
            <div class="card-body p-4 d-flex flex-column">
                <div class="rounded-3 bg-success bg-opacity-10 d-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                    <i class="bi bi-graph-up-arrow text-success fs-3"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">Performa Produk</h5>
                <p class="text-muted small mb-4 flex-grow-1">Peringkat produk paling laku atau paling banyak pergerakan keluar (Kuantitas, non-harga).</p>
                <div class="d-grid gap-2">
                    <button class="btn btn-light border fw-semibold text-primary" onclick="alert('Mempersiapkan File Excel...')"><i class="bi bi-file-earmark-excel me-2"></i>Unduh Excel</button>
                    <button class="btn btn-light border fw-semibold text-danger" onclick="alert('Mempersiapkan File PDF...')"><i class="bi bi-file-earmark-pdf me-2"></i>Unduh PDF</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Laporan 3 -->
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 rounded-4 report-card transition-all">
            <div class="card-body p-4 d-flex flex-column">
                <div class="rounded-3 bg-warning bg-opacity-10 d-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                    <i class="bi bi-boxes text-warning fs-3"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">Posisi Stok Real-time</h5>
                <p class="text-muted small mb-4 flex-grow-1">Snapshot sisa stok di seluruh gudang berdasarkan batch, termasuk yang terkunci untuk alokasi.</p>
                <div class="d-grid gap-2">
                    <button class="btn btn-light border fw-semibold text-primary" onclick="alert('Mempersiapkan File Excel...')"><i class="bi bi-file-earmark-excel me-2"></i>Unduh Excel</button>
                    <button class="btn btn-light border fw-semibold text-danger" onclick="alert('Mempersiapkan File PDF...')"><i class="bi bi-file-earmark-pdf me-2"></i>Unduh PDF</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Laporan 4 -->
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 rounded-4 report-card transition-all">
            <div class="card-body p-4 d-flex flex-column">
                <div class="rounded-3 bg-info bg-opacity-10 d-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                    <i class="bi bi-people text-info fs-3"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">Performa Tim Sales</h5>
                <p class="text-muted small mb-4 flex-grow-1">Laporan pencapaian transaksi per Sales dan rata-rata SLA pengerjaan order mereka.</p>
                <div class="d-grid gap-2">
                    <button class="btn btn-light border fw-semibold text-primary" onclick="alert('Mempersiapkan File Excel...')"><i class="bi bi-file-earmark-excel me-2"></i>Unduh Excel</button>
                    <button class="btn btn-light border fw-semibold text-danger" onclick="alert('Mempersiapkan File PDF...')"><i class="bi bi-file-earmark-pdf me-2"></i>Unduh PDF</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .report-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.08) !important;
        border: 1px solid rgba(var(--bs-primary-rgb), 0.2) !important;
    }
    .transition-all {
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
</style>
@endpush
@endsection