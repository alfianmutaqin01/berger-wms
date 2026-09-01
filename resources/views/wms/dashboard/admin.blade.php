@extends('layouts.wms')
@section('title', 'Dashboard WMS')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Dashboard Global (Admin / Logistik)</h4>
        <p class="text-muted">Ringkasan seluruh aktivitas WMS (Inbound & Outbound) dan pergerakan barang.</p>
    </div>
</div>

<!-- Statistic Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <h6 class="fw-normal mb-2 opacity-75">Total Pesanan</h6>
                <h3 class="mb-0 fw-bold">156 <span class="fs-6 fw-normal">PO</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card h-100 shadow-sm border-0 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Butuh Approval</h6>
                <h3 class="mb-0 fw-bold text-warning">12 <span class="fs-6 fw-normal">PO</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-info border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Dalam Pengiriman</h6>
                <h3 class="mb-0 fw-bold text-info">8 <span class="fs-6 fw-normal">S.Jalan</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Selesai (Lunas)</h6>
                <h3 class="mb-0 fw-bold text-success">134 <span class="fs-6 fw-normal">Pesanan</span></h3>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-2">
        <div class="card h-100 shadow-sm border-0 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Overdue</h6>
                <h3 class="mb-0 fw-bold text-danger">5 <span class="fs-6 fw-normal">Toko</span></h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart Area -->
    <div class="col-12 col-xl-8">
        <div class="card shadow-sm border-0 h-100 rounded-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-0">
                <h6 class="fw-bold mb-0">Tren Transaksi Pesanan</h6>
                <select class="form-select form-select-sm w-auto bg-light border-0 rounded-pill">
                    <option>Bulan Ini</option>
                    <option>Bulan Lalu</option>
                    <option>Tahun Ini</option>
                </select>
            </div>
            <div class="card-body px-4" style="min-height: 300px;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Orders Table -->
    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0 h-100 rounded-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-0">
                <h6 class="fw-bold mb-0">Aktivitas Terbaru</h6>
                <button class="btn btn-sm btn-link text-decoration-none">Semua</button>
            </div>
            <div class="card-body p-0 mt-3 px-2">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <tbody>
                            <tr>
                                <td class="ps-3 fw-bold small text-primary">#PO-00145</td>
                                <td class="small fw-semibold">Toko Makmur</td>
                                <td class="pe-3 text-end"><span class="badge bg-warning text-dark">Menunggu Aprv</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-bold small text-primary">#PO-00144</td>
                                <td class="small fw-semibold">TB. Sentosa</td>
                                <td class="pe-3 text-end"><span class="badge bg-info">Picking</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-bold small text-primary">#PO-00143</td>
                                <td class="small fw-semibold">CV. Jaya</td>
                                <td class="pe-3 text-end"><span class="badge bg-primary">Pengiriman</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-bold small text-primary">#PO-00142</td>
                                <td class="small fw-semibold">Toko Warna</td>
                                <td class="pe-3 text-end"><span class="badge bg-success">Selesai</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-bold small text-primary">#PO-00141</td>
                                <td class="small fw-semibold">Bintang Terang</td>
                                <td class="pe-3 text-end"><span class="badge bg-danger">Overdue</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('salesChart').getContext('2d');
        const primaryColor = '#1B4F8A';
        const secondaryColor = '#E8871E';
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4'],
                datasets: [
                    {
                        label: 'Pesanan Selesai',
                        data: [120, 150, 180, 190],
                        backgroundColor: primaryColor,
                        borderRadius: 4
                    },
                    {
                        label: 'Pesanan Tertunda',
                        data: [30, 20, 15, 5],
                        backgroundColor: secondaryColor,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    });
</script>
@endpush