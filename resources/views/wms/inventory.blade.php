@extends('layouts.wms')

@section('title', 'Data Inventory')
@section('page_title', 'Master Inventory & Stok')

@section('content')
<div class="container-fluid p-0">
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" placeholder="Cari kode SKU atau nama barang...">
            </div>
        </div>
        <div class="col-md-8 text-end">
            <button class="btn btn-outline-secondary me-2"><i class="bi bi-funnel"></i> Filter</button>
            <button class="btn btn-outline-success me-2"><i class="bi bi-file-earmark-excel"></i> Export</button>
            <button class="btn btn-primary-custom"><i class="bi bi-upc-scan me-2"></i>Scan Barcode</button>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">SKU / Barcode</th>
                            <th>Nama Produk</th>
                            <th>Lokasi Rak</th>
                            <th>Stok Fisik</th>
                            <th>Status Stok</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="ps-4 fw-medium">BPI-WEAT-001</td>
                            <td>Weathercoat Exterior - Putih (20L)</td>
                            <td>Rak A1-L2</td>
                            <td class="fw-bold fs-5">145</td>
                            <td><span class="badge bg-success">Aman</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary">Sesuaikan</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium">BPI-VIP-015</td>
                            <td>VIP Wood & Metal - Hitam (1L)</td>
                            <td>Rak B3-L1</td>
                            <td class="fw-bold fs-5 text-warning">12</td>
                            <td><span class="badge bg-warning text-dark">Terbatas</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary">Sesuaikan</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium">BPI-BJD-008</td>
                            <td>Berger Jensolin - Merah (5L)</td>
                            <td>Rak C1-L4</td>
                            <td class="fw-bold fs-5 text-danger">0</td>
                            <td><span class="badge bg-danger">Habis</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary">Sesuaikan</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection