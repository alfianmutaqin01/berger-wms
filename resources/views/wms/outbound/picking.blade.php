@extends('layouts.wms')
@section('title', 'Proses Picking')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Daftar Picking Pesanan (F-OUT-03)</h4>
        <p class="text-muted">Instruksi pengambilan barang dari rak berurutan berdasarkan lokasi terdekat.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0">
            <i class="bi bi-box-seam text-primary me-2"></i>Picking List: <span class="text-primary">PO-2608-001</span>
        </h6>
        <span class="badge bg-primary rounded-pill px-3 py-2">Tujuan: CV Bangun Jaya</span>
    </div>
    <div class="card-body p-4">
        
        <div class="table-responsive border rounded-3 mb-4">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="table-light small">
                    <tr>
                        <th class="text-start">LOKASI RAK <i class="bi bi-sort-down"></i></th>
                        <th class="text-start">SKU & DESKRIPSI</th>
                        <th>BATCH NO</th>
                        <th>UoM</th>
                        <th class="bg-primary-subtle">QTY DIAMBIL</th>
                        <th>STOK AWAL RAK</th>
                        <th class="text-danger">SISA DI RAK</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Item 1: Rak A -->
                    <tr>
                        <td class="text-start"><span class="badge bg-dark">A-01-05</span></td>
                        <td class="text-start small fw-semibold">BP-20KG-BLU<br><span class="text-muted fw-normal">Cat Pelapis Berger Blue 20Kg</span></td>
                        <td><span class="text-muted font-monospace">BCH-202607-15</span></td>
                        <td>Pail 20 Kg</td>
                        <td class="bg-primary-subtle fw-bold fs-5 text-primary">15</td>
                        <td><span class="badge bg-secondary">15</span></td>
                        <td class="text-danger fw-bold">0</td>
                    </tr>
                    <!-- Item 2: Rak G -->
                    <tr>
                        <td class="text-start"><span class="badge bg-dark">G-03-01</span></td>
                        <td class="text-start small fw-semibold">BP-5KG-WHT<br><span class="text-muted fw-normal">Cat Tembok Berger White 5Kg</span></td>
                        <td><span class="text-muted font-monospace">BCH-202608-01</span></td>
                        <td>Galon 5 Kg</td>
                        <td class="bg-primary-subtle fw-bold fs-5 text-primary">100</td>
                        <td><span class="badge bg-secondary">150</span></td>
                        <td class="text-success fw-bold">50</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form action="/wms/outbound/complete-picking/PO-2608-001" method="POST" class="d-flex justify-content-end">
            @csrf
            <button type="submit" class="btn btn-success px-5 rounded-pill shadow-sm py-2">
                <i class="bi bi-check2-all me-2"></i> Siap Loading (Selesai Picking)
            </button>
        </form>

    </div>
</div>

@endsection