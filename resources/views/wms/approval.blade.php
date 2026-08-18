@extends('layouts.wms')

@section('title', 'Approval Order')
@section('page_title', 'Approval Order')

@section('content')
<div class="table-card card">
    <div class="card-header bg-white pt-4 pb-3 border-bottom-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">Daftar Order Menunggu Persetujuan</h6>
        <div>
            <button class="btn btn-sm btn-outline-secondary fw-semibold"><i class="bi bi-funnel"></i> Filter</button>
        </div>
    </div>
    <div class="card-body p-0 border-top">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">No. Order</th>
                        <th>Tanggal</th>
                        <th>Customer</th>
                        <th>Sales Rep</th>
                        <th>Nilai Transaksi</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-4 fw-bold text-primary">SO-2608-0105</td>
                        <td>15 Aug 2026</td>
                        <td>Toko Besi Maju Jaya</td>
                        <td>Budi Santoso</td>
                        <td class="fw-bold text-dark">Rp 12.500.000</td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-success fw-semibold shadow-sm me-1"><i class="bi bi-check-lg"></i> Setuju</button>
                            <button class="btn btn-sm btn-danger fw-semibold shadow-sm"><i class="bi bi-x-lg"></i> Tolak</button>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-primary">SO-2608-0106</td>
                        <td>15 Aug 2026</td>
                        <td>TB. Makmur Abadi</td>
                        <td>Budi Santoso</td>
                        <td class="fw-bold text-dark">Rp 3.200.000</td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-success fw-semibold shadow-sm me-1"><i class="bi bi-check-lg"></i> Setuju</button>
                            <button class="btn btn-sm btn-danger fw-semibold shadow-sm"><i class="bi bi-x-lg"></i> Tolak</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
