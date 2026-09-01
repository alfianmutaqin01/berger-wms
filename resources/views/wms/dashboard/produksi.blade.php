@extends('layouts.wms')
@section('title', 'Dashboard Produksi')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Dashboard Produksi</h4>
        <p class="text-muted">Pantau status *batch* produksi harian dan ketersediaan bahan baku utama.</p>
    </div>
</div>

<!-- Statistic Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card h-100 shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <h6 class="fw-normal mb-2 opacity-75">Target Produksi (Bulan Ini)</h6>
                <h3 class="mb-0 fw-bold">12.500 <span class="fs-6 fw-normal">Batch</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Selesai Diproduksi</h6>
                <h3 class="mb-0 fw-bold text-success">8.200 <span class="fs-6 fw-normal">Batch</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Sedang Diproses</h6>
                <h3 class="mb-0 fw-bold text-warning">45 <span class="fs-6 fw-normal">Mesin Aktif</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-12 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Peringatan Bahan Baku</h6>
                <h3 class="mb-0 fw-bold text-danger">3 <span class="fs-6 fw-normal">Material Kritis</span></h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart Area -->
    <div class="col-12 col-xl-7">
        <div class="card shadow-sm border-0 h-100 rounded-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                <h6 class="fw-bold mb-0">Tren Output Produksi Harian</h6>
            </div>
            <div class="card-body px-4" style="min-height: 300px;">
                <canvas id="prodChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Alert Table -->
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm border-0 h-100 rounded-4 border-top border-danger border-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                <h6 class="fw-bold text-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Bahan Baku Menipis</h6>
            </div>
            <div class="card-body p-4 mt-2">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold">Pigment Putih (Titanium Dioxide)</h6>
                            <small class="text-muted">Gudang Bahan Baku A</small>
                        </div>
                        <span class="badge bg-danger rounded-pill px-3">Sisa 12 Drum</span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold">Resin Acrylic Premium</h6>
                            <small class="text-muted">Gudang Bahan Baku B</small>
                        </div>
                        <span class="badge bg-danger rounded-pill px-3">Sisa 5 Tangki</span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold">Pelarut (Solvent X)</h6>
                            <small class="text-muted">Gudang Cairan</small>
                        </div>
                        <span class="badge bg-warning text-dark rounded-pill px-3">Sisa 25 Drum</span>
                    </li>
                </ul>
                <button class="btn btn-outline-danger w-100 mt-4 rounded-pill">Minta Restock ke Purchasing</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('prodChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'],
                datasets: [{
                    label: 'Batch Diproduksi',
                    data: [350, 420, 380, 510, 480, 200],
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    });
</script>
@endpush