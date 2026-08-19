@extends('layouts.wms')
@section('title', 'Dashboard Operator Gudang')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Area Kerja Operator</h4>
        <p class="text-muted">Fokus pada tugas lapangan yang harus Anda selesaikan hari ini.</p>
    </div>
</div>

<!-- Statistic Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card h-100 shadow-sm border-0 bg-info text-white">
            <div class="card-body">
                <h6 class="fw-normal mb-2 opacity-75">Tugas Put-away Baru</h6>
                <h3 class="mb-0 fw-bold">12 <span class="fs-6 fw-normal">Pallet</span></h3>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="/wms/inbound/putaway" class="btn btn-light btn-sm text-info fw-bold w-100 rounded-pill shadow-sm">Kerjakan Sekarang</a>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card h-100 shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <h6 class="fw-normal mb-2 opacity-75">Tugas Picking (Ambil Barang)</h6>
                <h3 class="mb-0 fw-bold">8 <span class="fs-6 fw-normal">Surat Jalan</span></h3>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="/wms/outbound/picking" class="btn btn-light btn-sm text-primary fw-bold w-100 rounded-pill shadow-sm">Lihat Daftar</a>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100 shadow-sm border-0 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Stok Rak Menipis</h6>
                <h3 class="mb-0 fw-bold text-danger">5 <span class="fs-6 fw-normal">Item</span></h3>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <button class="btn btn-outline-danger btn-sm w-100 rounded-pill">Isi Ulang Rak</button>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-list-check me-2 text-primary"></i>Log Pekerjaan Terakhir Anda</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small">
                    <tr>
                        <th>WAKTU</th>
                        <th>JENIS TUGAS</th>
                        <th>REFERENSI</th>
                        <th>LOKASI / RAK</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="text-muted small">10 Menit lalu</span></td>
                        <td><span class="badge bg-info-subtle text-info-emphasis">Put-away</span></td>
                        <td class="fw-semibold">IN-2608-011</td>
                        <td>A-01-05</td>
                        <td><i class="bi bi-check-circle-fill text-success"></i> Selesai</td>
                    </tr>
                    <tr>
                        <td><span class="text-muted small">45 Menit lalu</span></td>
                        <td><span class="badge bg-primary-subtle text-primary-emphasis">Picking</span></td>
                        <td class="fw-semibold">PO-2608-005</td>
                        <td>D-02-11</td>
                        <td><i class="bi bi-check-circle-fill text-success"></i> Selesai</td>
                    </tr>
                    <tr>
                        <td><span class="text-muted small">2 Jam lalu</span></td>
                        <td><span class="badge bg-secondary-subtle text-secondary-emphasis">Stock Opname</span></td>
                        <td class="fw-semibold">Cek Fisik B-03</td>
                        <td>B-03-All</td>
                        <td><i class="bi bi-check-circle-fill text-success"></i> Selesai</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection