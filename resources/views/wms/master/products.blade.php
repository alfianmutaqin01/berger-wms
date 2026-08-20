@extends('layouts.wms')

@section('title', 'Master Produk')
@section('page_title', 'Master Data Produk')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i> Master Data Produk</h5>
                    <p class="text-muted small mt-1 mb-0">Daftar referensi seluruh SKU produk tanpa memuat informasi jumlah stok.</p>
                </div>
                <div>
                    <button class="btn btn-outline-secondary fw-bold shadow-sm me-2">
                        <i class="bi bi-upload me-1"></i> Import Excel
                    </button>
                    <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="bi bi-plus-circle me-1"></i> Tambah Produk
                    </button>
                </div>
            </div>
            <div class="card-body p-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="input-group" style="max-width: 350px;">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Cari SAPSKU atau Description...">
                    </div>
                    <div class="d-flex gap-2">
                        <select class="form-select bg-light border-0" style="width: 150px;">
                            <option value="">Semua Type</option>
                            <option value="Alk Primer">Alk Primer</option>
                            <option value="AMC">AMC</option>
                            <option value="Apex Emulsion">Apex Emulsion</option>
                        </select>
                        <select class="form-select bg-light border-0" style="width: 130px;">
                            <option value="">Semua UoM</option>
                            <option value="TIN">TIN</option>
                            <option value="PAIL">PAIL</option>
                            <option value="CAN">CAN</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-center" style="width: 50px;">NO</th>
                                <th class="text-secondary small fw-semibold">SAPSKU</th>
                                <th class="text-secondary small fw-semibold" style="min-width: 280px;">DESCRIPTION</th>
                                <th class="text-secondary small fw-semibold">PRODUCT TYPE</th>
                                <th class="text-secondary small fw-semibold text-center">UoM</th>
                                <th class="text-secondary small fw-semibold text-end">GROSS WEIGHT</th>
                                <th class="text-secondary small fw-semibold text-end">NET WEIGHT</th>
                                <th class="text-secondary small fw-semibold text-center pe-3">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-center text-muted">1</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F03603202804</td>
                                <td class="text-dark">Trucare Alkali Resist Primer White 4Kg</td>
                                <td><span class="badge bg-secondary text-white">Alk Primer</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">TIN</span></td>
                                <td class="text-end font-monospace text-muted">4.60</td>
                                <td class="text-end font-monospace fw-bold text-dark">4.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">2</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F03603202820</td>
                                <td class="text-dark">Trucare Alkali Resist Primer White 20Kg</td>
                                <td><span class="badge bg-secondary text-white">Alk Primer</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">PAIL</span></td>
                                <td class="text-end font-monospace text-muted">21.20</td>
                                <td class="text-end font-monospace fw-bold text-dark">20.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">3</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F13150111210</td>
                                <td class="text-dark">AMC Fast Blue 1Ltr</td>
                                <td><span class="badge bg-info text-dark">AMC</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">CAN</span></td>
                                <td class="text-end font-monospace text-muted">1.30</td>
                                <td class="text-end font-monospace fw-bold text-dark">1.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">4</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F13150216210</td>
                                <td class="text-dark">AMC Fast Green 1Ltr</td>
                                <td><span class="badge bg-info text-dark">AMC</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">CAN</span></td>
                                <td class="text-end font-monospace text-muted">1.37</td>
                                <td class="text-end font-monospace fw-bold text-dark">1.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">5</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F00123202225</td>
                                <td class="text-dark">Apex Emulsion White 2.5Ltr</td>
                                <td><span class="badge bg-primary text-white">Apex Emulsion</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">TIN</span></td>
                                <td class="text-end font-monospace text-muted">4.21</td>
                                <td class="text-end font-monospace fw-bold text-dark">2.500</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">6</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F00123708320</td>
                                <td class="text-dark">Apex Emulsion Harvest Cream 20Ltr</td>
                                <td><span class="badge bg-primary text-white">Apex Emulsion</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">PAIL</span></td>
                                <td class="text-end font-monospace text-muted">28.06</td>
                                <td class="text-end font-monospace fw-bold text-dark">20.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
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
<!-- Modal Tambah Produk -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-box-seam text-primary me-2"></i> Tambah Master Produk</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">SAPSKU *</label>
                    <input type="text" class="form-control font-monospace" placeholder="e.g., ID1-F036...">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Description *</label>
                    <input type="text" class="form-control" placeholder="Nama lengkap produk...">
                </div>
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Product Type</label>
                        <input type="text" class="form-control" placeholder="e.g., Alk Primer">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">UoM (Kemasan)</label>
                        <select class="form-select">
                            <option value="PAIL">PAIL</option>
                            <option value="TIN">TIN</option>
                            <option value="CAN">CAN</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-0">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Gross Weight</label>
                        <input type="number" step="0.01" class="form-control" placeholder="0.00">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Net Weight</label>
                        <input type="number" step="0.01" class="form-control" placeholder="0.000">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" onclick="alert('Simulasi: Produk baru ditambahkan');"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </div>
    </div>
</div>
@endpush