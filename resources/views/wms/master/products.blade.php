@extends('layouts.wms')

@section('title', 'Master Products')
@section('page_title', 'Master Products')

@section('content')
<div class="table-card card">
    <div class="card-header bg-white pt-4 pb-3 border-bottom-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">Katalog Produk</h6>
        <div>
            <button class="btn btn-sm btn-primary fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="bi bi-plus-circle me-1"></i> Tambah Produk
            </button>
        </div>
    </div>
    <div class="card-body p-0 border-top">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">SKU</th>
                        <th>Nama Produk</th>
                        <th>Kemasan</th>
                        <th>Kategori</th>
                        <th>Stok Tersedia</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-4 fw-bold text-muted">BPI-1001</td>
                        <td class="fw-bold text-dark">Cat Tembok Putih 5Kg</td>
                        <td>Pail</td>
                        <td>Interior</td>
                        <td><span class="badge bg-success">150 Pail</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-muted">BPI-1002</td>
                        <td class="fw-bold text-dark">Cat Tembok Biru 5Kg</td>
                        <td>Pail</td>
                        <td>Interior</td>
                        <td><span class="badge bg-warning text-dark">12 Pail</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah Produk -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-seam text-primary me-2"></i>Tambah Produk Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form action="#" method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">SKU Produk <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" placeholder="Contoh: BPI-1003" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Kategori <span class="text-danger">*</span></label>
                            <select class="form-select" required>
                                <option value="Interior">Interior</option>
                                <option value="Exterior">Exterior</option>
                                <option value="Thinner">Thinner</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted small fw-bold">Nama Produk <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" placeholder="Masukkan nama produk..." required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Jenis Kemasan</label>
                            <select class="form-select">
                                <option value="Pail">Pail</option>
                                <option value="Galon">Galon</option>
                                <option value="Kaleng">Kaleng</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Stok Awal</label>
                            <input type="number" class="form-control" value="0">
                        </div>
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
