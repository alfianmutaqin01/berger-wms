@extends('layouts.wms')

@section('title', 'Billing & Penagihan')
@section('page_title', 'Billing & Penagihan')

@section('content')
<div class="container-fluid p-0">
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white border-0 shadow-sm h-100" style="background: linear-gradient(135deg, var(--color-primary), #0f2e54) !important;">
                <div class="card-body">
                    <h6 class="text-white-50 mb-2">Total Piutang Belum Lunas</h6>
                    <h3 class="fw-bold mb-0">Rp 125.500.000</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-danger border-4">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Piutang Jatuh Tempo (Overdue)</h6>
                    <h3 class="fw-bold text-danger mb-0">Rp 45.200.000</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-success border-4">
                <div class="card-body">
                    <h6 class="text-muted mb-2">Pembayaran Diterima Bulan Ini</h6>
                    <h3 class="fw-bold text-success mb-0">Rp 350.800.000</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent border-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">Daftar Tagihan (Invoice)</h6>
            <div>
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </div>
        <div class="card-body mt-2 p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">No. Invoice</th>
                            <th>No. Surat Jalan</th>
                            <th>Customer</th>
                            <th>Jatuh Tempo</th>
                            <th>Nominal</th>
                            <th>Status Pembayaran</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="ps-4 fw-medium"><i class="bi bi-receipt text-primary me-2"></i>INV-2608-0101</td>
                            <td>SJ-2608-0100</td>
                            <td>Toko Warna</td>
                            <td><span class="text-danger fw-bold">10 Aug 2026</span></td>
                            <td class="fw-medium">Rp 12.500.000</td>
                            <td><span class="badge bg-danger">Overdue</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-envelope me-1"></i> Remainder</button>
                                <button class="btn btn-sm btn-success shadow-sm ms-1">Konfirmasi Lunas</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium"><i class="bi bi-receipt text-primary me-2"></i>INV-2608-0102</td>
                            <td>SJ-2608-0101</td>
                            <td>TB. Sentosa</td>
                            <td>30 Aug 2026</td>
                            <td class="fw-medium">Rp 25.000.000</td>
                            <td><span class="badge bg-warning text-dark">Belum Bayar</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-success shadow-sm">Konfirmasi Lunas</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium text-muted"><i class="bi bi-receipt text-muted me-2"></i>INV-2607-0099</td>
                            <td class="text-muted">SJ-2607-0099</td>
                            <td class="text-muted">CV. Jaya</td>
                            <td class="text-muted">30 Jul 2026</td>
                            <td class="text-muted">Rp 8.200.000</td>
                            <td><span class="badge bg-success">Lunas</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i> Cetak Tanda Terima</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
