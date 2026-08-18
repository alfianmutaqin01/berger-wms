@extends('layouts.soms')

@section('title', 'Dashboard')
@section('page_title', 'Sales Dashboard')

@section('content')
<!-- Statistic Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card card h-100 p-3">
            <div class="d-flex align-items-center mb-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                    <i class="bi bi-currency-dollar"></i>
                </div>
                <div>
                    <h6 class="text-muted fw-normal mb-0" style="font-size: 0.8rem;">Penjualan Bulan Ini</h6>
                    <h4 class="mb-0 fw-bold">Rp 125.5M</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card card h-100 p-3">
            <div class="d-flex align-items-center mb-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <h6 class="text-muted fw-normal mb-0" style="font-size: 0.8rem;">Menunggu Approval</h6>
                    <h4 class="mb-0 fw-bold">8 Order</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card card h-100 p-3">
            <div class="d-flex align-items-center mb-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                    <i class="bi bi-truck"></i>
                </div>
                <div>
                    <h6 class="text-muted fw-normal mb-0" style="font-size: 0.8rem;">Dalam Pengiriman</h6>
                    <h4 class="mb-0 fw-bold">12 Order</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card card h-100 p-3">
            <div class="d-flex align-items-center mb-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <h6 class="text-muted fw-normal mb-0" style="font-size: 0.8rem;">Selesai (Terkirim)</h6>
                    <h4 class="mb-0 fw-bold">45 Order</h4>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- My Recent Orders Table -->
<div class="table-card card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">Pesanan Terakhir Saya</h6>
        <div>
            <a href="/sales/new-order" class="btn btn-sm btn-primary"><i class="bi bi-plus"></i> Buat Order Baru</a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">No. Order</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Nilai Transaksi</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-4 fw-bold text-primary">SO-2608-0105</td>
                        <td>15 Aug 2026</td>
                        <td>Toko Besi Maju Jaya</td>
                        <td>Rp 12.500.000</td>
                        <td><span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Menunggu</span></td>
                        <td class="text-end pe-4">
                            <a href="/sales/tracking" class="btn btn-sm btn-light border"><i class="bi bi-search text-primary"></i> Detail</a>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-primary">SO-2608-0104</td>
                        <td>14 Aug 2026</td>
                        <td>CV. Bintang Abadi</td>
                        <td>Rp 45.200.000</td>
                        <td><span class="badge bg-info"><i class="bi bi-truck me-1"></i>Pengiriman</span></td>
                        <td class="text-end pe-4">
                            <a href="/sales/tracking" class="btn btn-sm btn-light border"><i class="bi bi-search text-primary"></i> Detail</a>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-muted">SO-2608-0100</td>
                        <td class="text-muted">10 Aug 2026</td>
                        <td class="text-muted">Toko Warna Baru</td>
                        <td class="text-muted">Rp 8.500.000</td>
                        <td><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Selesai</span></td>
                        <td class="text-end pe-4">
                            <a href="/sales/tracking" class="btn btn-sm btn-light border"><i class="bi bi-search text-primary"></i> Detail</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
