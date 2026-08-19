@extends('layouts.wms')

@section('title', 'Master Customers')
@section('page_title', 'Master Customers')

@section('content')
<div class="row mb-4">
    <div class="col-12 col-md-8">
        <h4 class="fw-bold text-dark mb-0">Manajemen Pelanggan</h4>
        <p class="text-muted">Kelola basis data pelanggan aktif dan review pengajuan pelanggan baru dari Sales.</p>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom pt-4 px-4 pb-0">
        <!-- Nav Tabs -->
        <ul class="nav nav-tabs border-bottom-0" id="customerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold px-4 py-3" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button" role="tab">
                    <i class="bi bi-person-check me-2"></i>Pelanggan Aktif
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold px-4 py-3 text-danger" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                    <i class="bi bi-person-exclamation me-2"></i>Menunggu Approval <span class="badge bg-danger rounded-pill ms-2">1</span>
                </button>
            </li>
        </ul>
    </div>
    
    <div class="card-body p-0">
        <div class="tab-content" id="customerTabsContent">
            
            <!-- Tab: Pelanggan Aktif -->
            <div class="tab-pane fade show active" id="active" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center p-4 border-bottom bg-light">
                    <div class="input-group w-50">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" placeholder="Cari pelanggan...">
                    </div>
                    <button class="btn btn-primary fw-semibold rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                        <i class="bi bi-plus-circle me-2"></i>Tambah Manual
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th class="ps-4">KODE</th>
                                <th>NAMA PELANGGAN</th>
                                <th>NO. TELEPON</th>
                                <th>ALAMAT</th>
                                <th>STATUS</th>
                                <th class="text-end pe-4">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="ps-4 fw-bold text-muted">CUST-001</td>
                                <td class="fw-bold text-dark">Toko Besi Maju Jaya</td>
                                <td>0812-3456-7890</td>
                                <td>Jl. Raya Bekasi No. 45</td>
                                <td><span class="badge bg-success">Aktif</span></td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="ps-4 fw-bold text-muted">CUST-002</td>
                                <td class="fw-bold text-dark">CV. Bintang Abadi</td>
                                <td>0819-8765-4321</td>
                                <td>Kawasan Industri Karawang</td>
                                <td><span class="badge bg-success">Aktif</span></td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Tab: Menunggu Approval -->
            <div class="tab-pane fade" id="pending" role="tabpanel">
                <div class="table-responsive mt-3">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th class="ps-4">NAMA PENGAJU (SALES)</th>
                                <th>NAMA TOKO CALON</th>
                                <th>KONTAK</th>
                                <th>ALAMAT</th>
                                <th>LAMPIRAN</th>
                                <th class="text-end pe-4">AKSI REVIEW</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="ps-4">
                                    <span class="fw-bold text-dark d-block">Budi Santoso</span>
                                    <small class="text-muted">Tgl Pengajuan: 19 Ags 2026</small>
                                </td>
                                <td>
                                    <h6 class="mb-0 fw-bold text-primary">Toko Cat Makmur Jaya</h6>
                                </td>
                                <td>0812-3456-7890</td>
                                <td>Jl. Raya Bogor KM 29, Depok</td>
                                <td>
                                    <button class="btn btn-sm btn-light border text-primary"><i class="bi bi-image me-1"></i>Lihat KTP/Foto</button>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-success fw-bold rounded-pill px-3 shadow-sm me-1" onclick="alert('Customer disetujui! Kode CUST-003 diterbitkan.')"><i class="bi bi-check-lg me-1"></i>Approve</button>
                                    <button class="btn btn-sm btn-outline-danger fw-bold rounded-pill px-3"><i class="bi bi-x-lg me-1"></i>Tolak</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>
@endsection

@push('modals')