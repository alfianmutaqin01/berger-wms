@extends('layouts.wms')

@section('title', 'Master Customers')
@section('page_title', 'Master Customers')

@section('content')
<div class="table-card card">
    <div class="card-header bg-white pt-4 pb-3 border-bottom-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">Daftar Pelanggan</h6>
        <div>
            <button class="btn btn-sm btn-primary fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                <i class="bi bi-plus-circle me-1"></i> Tambah Pelanggan
            </button>
        </div>
    </div>
    <div class="card-body p-0 border-top">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Kode</th>
                        <th>Nama Pelanggan</th>
                        <th>No. Telepon</th>
                        <th>Alamat</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
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
</div>

<!-- Modal Tambah Customer -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-primary me-2"></i>Tambah Pelanggan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form action="#" method="POST">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Kode Pelanggan</label>
                        <input type="text" class="form-control bg-light" value="CUST-003" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Nama Pelanggan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" placeholder="Masukkan nama..." required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">No. Telepon <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" placeholder="Contoh: 08123456789" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Alamat Lengkap <span class="text-danger">*</span></label>
                        <textarea class="form-control" rows="3" placeholder="Masukkan alamat lengkap..." required></textarea>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-save me-1"></i> Simpan Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
