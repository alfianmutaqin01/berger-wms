@extends('layouts.soms')

@section('title', 'Buat Order Baru')
@section('page_title', 'Buat Order Baru')

@section('content')
<form action="#" method="POST">
    <div class="row g-4">
        <!-- Header Information -->
        <div class="col-12 col-lg-4">
            <div class="table-card card h-100">
                <div class="card-header border-bottom-0 pb-0 pt-4 bg-white">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-info-circle text-primary me-2"></i>Informasi Header</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Pelanggan <span class="text-danger">*</span></label>
                        <select class="form-select" required>
                            <option value="" selected disabled>Pilih Pelanggan...</option>
                            <option value="1">Toko Besi Maju Jaya</option>
                            <option value="2">CV. Bintang Abadi</option>
                            <option value="3">Toko Warna Baru</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Nomor PO (Opsional)</label>
                        <input type="text" class="form-control" placeholder="Contoh: PO/2026/08/112">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Tgl. Pengiriman Diminta <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" value="2026-08-20" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Term of Payment</label>
                        <select class="form-select">
                            <option value="1">NET 30</option>
                            <option value="2">NET 45</option>
                            <option value="3">Cash Before Delivery (CBD)</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label text-muted small fw-bold">Catatan</label>
                        <textarea class="form-control" rows="3" placeholder="Tambahkan catatan jika ada..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Lines -->
        <div class="col-12 col-lg-8">
            <div class="table-card card h-100">
                <div class="card-header d-flex justify-content-between align-items-center bg-white pt-4 pb-3 border-bottom-0">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Daftar Produk</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" id="addProductBtn"><i class="bi bi-plus"></i> Tambah Baris</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="productTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" style="width: 35%;">Produk <span class="text-danger">*</span></th>
                                    <th style="width: 15%;">Qty <span class="text-danger">*</span></th>
                                    <th style="width: 15%;">Satuan</th>
                                    <th style="width: 25%;">Catatan Khusus</th>
                                    <th class="text-end pe-4" style="width: 10%;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="product-row-tr">
                                    <td class="ps-4">
                                        <select class="form-select form-select-sm" required>
                                            <option value="" selected disabled>Cari SKU/Nama...</option>
                                            <option value="1">BPI-1001 - Cat Tembok Putih 5Kg</option>
                                            <option value="2">BPI-1002 - Cat Tembok Biru 5Kg</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm text-center" value="1" min="1" required>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm bg-light text-center" value="Pail" readonly>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm" placeholder="Opsional">
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row border-0" disabled><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light border-top-0 pt-3 pb-3 d-flex justify-content-end gap-2 px-4">
                    <button type="button" class="btn btn-light border bg-white shadow-sm fw-semibold">Batal</button>
                    <button type="submit" class="btn btn-primary shadow-sm fw-semibold"><i class="bi bi-save me-1"></i> Simpan Order</button>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const addBtn = document.getElementById('addProductBtn');
        const tbody = document.querySelector('#productTable tbody');
        
        addBtn.addEventListener('click', function() {
            const tr = document.createElement('tr');
            tr.className = 'product-row-tr';
            tr.innerHTML = 
                <td class="ps-4">
                    <select class="form-select form-select-sm" required>
                        <option value="" selected disabled>Cari SKU/Nama...</option>
                        <option value="1">BPI-1001 - Cat Tembok Putih 5Kg</option>
                        <option value="2">BPI-1002 - Cat Tembok Biru 5Kg</option>
                    </select>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm text-center" value="1" min="1" required>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm bg-light text-center" value="Pail" readonly>
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" placeholder="Opsional">
                </td>
                <td class="text-end pe-4">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row border-0"><i class="bi bi-trash"></i></button>
                </td>
            ;
            tbody.appendChild(tr);
            updateRemoveButtons();
        });
        
        tbody.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-remove-row');
            if (btn && !btn.disabled) {
                btn.closest('tr').remove();
                updateRemoveButtons();
            }
        });
        
        function updateRemoveButtons() {
            const rows = tbody.querySelectorAll('tr');
            const btns = tbody.querySelectorAll('.btn-remove-row');
            btns.forEach(btn => {
                btn.disabled = rows.length === 1;
            });
        }
    });
</script>
@endpush
@endsection
