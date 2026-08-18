@extends('layouts.wms')

@section('title', 'Dashboard')
@section('page_title', 'Overview Gudang')

@section('content')
<div class="container-fluid p-0">
    <!-- Statistic Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Total Trans.</h6>
                    <h3 class="mb-0 fw-bold">156</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 shadow-sm border-0 border-start border-warning border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Perlu Aprv.</h6>
                    <h3 class="mb-0 fw-bold text-warning">12</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card h-100 shadow-sm border-0 border-start border-info border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Dalam Kirim</h6>
                    <h3 class="mb-0 fw-bold text-info">8</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0 border-start border-success border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Complete</h6>
                    <h3 class="mb-0 fw-bold text-success">134</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0 border-start border-danger border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Overdue</h6>
                    <h3 class="mb-0 fw-bold text-danger">5</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Chart Area -->
        <div class="col-12 col-xl-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-3 pb-0">
                    <h6 class="fw-bold mb-0">Grafik Penjualan</h6>
                    <select class="form-select form-select-sm w-auto">
                        <option>Bulan Ini</option>
                        <option>Bulan Lalu</option>
                        <option>Tahun Ini</option>
                    </select>
                </div>
                <div class="card-body" style="min-height: 300px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Recent Orders Table -->
        <div class="col-12 col-xl-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-3 pb-0">
                    <h6 class="fw-bold mb-0">Pesanan Terbaru</h6>
                    <button class="btn btn-sm btn-link text-decoration-none">Lihat Semua</button>
                </div>
                <div class="card-body p-0 mt-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">No. PO</th>
                                    <th>Customer</th>
                                    <th class="pe-3 text-end">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="ps-3 fw-medium">#PO-00145</td>
                                    <td>Toko Makmur</td>
                                    <td class="pe-3 text-end"><span class="badge bg-warning text-dark">Menunggu</span></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 fw-medium">#PO-00144</td>
                                    <td>TB. Sentosa</td>
                                    <td class="pe-3 text-end"><span class="badge bg-info">Proses</span></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 fw-medium">#PO-00143</td>
                                    <td>CV. Jaya</td>
                                    <td class="pe-3 text-end"><span class="badge bg-success">Siap Kirim</span></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 fw-medium">#PO-00142</td>
                                    <td>Toko Warna</td>
                                    <td class="pe-3 text-end"><span class="badge bg-secondary">Dikirim</span></td>
                                </tr>
                                <tr>
                                    <td class="ps-3 fw-medium">#PO-00141</td>
                                    <td>Bintang Terang</td>
                                    <td class="pe-3 text-end"><span class="badge bg-success">Selesai</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
        
        // Define colors based on design system
        const primaryColor = '#1B4F8A';
        const secondaryColor = '#E8871E';
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4'],
                datasets: [
                    {
                        label: 'Selesai',
                        data: [65, 59, 80, 81],
                        backgroundColor: primaryColor,
                        borderRadius: 4
                    },
                    {
                        label: 'Tertunda',
                        data: [12, 19, 3, 5],
                        backgroundColor: secondaryColor,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    });
</script>
@endpush
