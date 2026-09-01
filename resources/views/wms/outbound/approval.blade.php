@extends('layouts.wms')
@section('title', 'Approval Pesanan')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Penerimaan Pesanan</h4>
        <p class="text-muted">Kelola persetujuan Purchase Order (PO) dari Sales dan kalkulasi auto-adjustment stok.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-3 pb-0 px-4">
        <ul class="nav nav-tabs border-bottom-0" id="approvalTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold text-dark border-0 border-bottom border-primary border-3" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-tab-pane" type="button" role="tab" aria-controls="pending-tab-pane" aria-selected="true">
                    <i class="bi bi-ui-checks-grid text-primary me-2"></i>Menunggu Diterima
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold text-secondary border-0" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-tab-pane" type="button" role="tab" aria-controls="history-tab-pane" aria-selected="false">
                    <i class="bi bi-clock-history me-2"></i>Riwayat Keputusan
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="tab-content" id="approvalTabsContent">
            
            <!-- TAB 1: PENDING -->
            <div class="tab-pane fade show active p-4" id="pending-tab-pane" role="tabpanel" aria-labelledby="pending-tab" tabindex="0">
                <div class="d-flex justify-content-end mb-3">
                    <div class="input-group input-group-sm w-25">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Cari No PO / Gudang...">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th>NO. PO</th>
                                <th>CUSTOMER</th>
                                <th>DISPATCH CODE</th>
                                <th>ITEM / QTY</th>
                                <th>TANGGAL ORDER</th>
                                <th class="text-end">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="fw-semibold text-primary">PO-2608-001</span></td>
                                <td>CV Bangun Jaya</td>
                                <td><span class="badge bg-secondary">WH-01</span></td>
                                <td>2 Item (120 Qty)</td>
                                <td>18 Ags 2026, 14:00</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#previewModal"><i class="bi bi-search me-1"></i> Preview</button>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="fw-semibold text-primary">PO-2608-002</span></td>
                                <td>Toko Merah <span class="badge bg-danger bg-opacity-10 text-danger border border-danger ms-2 rounded-pill" style="font-size: 0.65rem;" title="Customer memiliki tunggakan/overdue"><i class="bi bi-exclamation-triangle-fill me-1"></i>Menunggak</span></td>
                                <td><span class="badge bg-secondary">WH-01</span></td>
                                <td><span class="badge bg-warning text-dark"><i class="bi bi-file-earmark-text"></i> Via Dokumen</span></td>
                                <td>18 Ags 2026, 14:15</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#previewModal2"><i class="bi bi-search me-1"></i> Preview</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: HISTORY -->
            <div class="tab-pane fade p-4" id="history-tab-pane" role="tabpanel" aria-labelledby="history-tab" tabindex="0">
                <div class="d-flex justify-content-end mb-3">
                    <div class="input-group input-group-sm w-25">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Cari No PO / Gudang...">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th>NO. PO</th>
                                <th>CUSTOMER</th>
                                <th>KEPUTUSAN</th>
                                <th>WAKTU KEPUTUSAN</th>
                                <th>DIPROSES OLEH</th>
                                <th>CATATAN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="fw-semibold text-primary">PO-2608-000</span></td>
                                <td>PT Jaya Abadi</td>
                                <td><span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-check-circle me-1"></i>Disetujui</span></td>
                                <td>18 Ags 2026, 10:30</td>
                                <td>Khoirun Nisa</td>
                                <td class="text-muted small">-</td>
                            </tr>
                            <tr>
                                <td><span class="fw-semibold text-primary">PO-2607-099</span></td>
                                <td>Toko Makmur (Menunggak)</td>
                                <td><span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-x-circle me-1"></i>Ditolak</span></td>
                                <td>17 Ags 2026, 16:45</td>
                                <td>Khoirun Nisa</td>
                                <td class="text-muted small">Tunggakan melebihi limit. Menunggu pelunasan bulan lalu.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>
@endsection
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('#approvalTabs .nav-link');
        tabs.forEach(tab => {
            tab.addEventListener('show.bs.tab', function(e) {
                // Reset all
                tabs.forEach(t => {
                    t.classList.remove('active', 'fw-bold', 'text-dark', 'border-bottom', 'border-primary', 'border-3');
                    t.classList.add('fw-semibold', 'text-secondary', 'border-0');
                });
                // Set active
                e.target.classList.add('active', 'fw-bold', 'text-dark', 'border-bottom', 'border-primary', 'border-3');
                e.target.classList.remove('fw-semibold', 'text-secondary', 'border-0');
            });
        });
    });
</script>

@push('modals')
<!-- Modal Preview 1 (Normal PO with SKUs) -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark">Preview PO: <span class="text-primary">PO-2608-001</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/wms/outbound/approve/PO-2608-001" method="POST">
                @csrf
                <div class="modal-body py-4">
                    <div class="row mb-4">
                        <div class="col-md-7">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Detail Pelanggan & Pengiriman</h6>
                            <table class="table table-sm table-borderless small mb-0">
                                <tr><td class="text-muted" width="120">Customer</td><td class="fw-semibold">CV Bangun Jaya</td></tr>
                                <tr><td class="text-muted">Sales Rep</td><td>Budi Santoso</td></tr>
                                <tr><td class="text-muted">Waktu Order</td><td>18 Ags 2026, 14:00 WIB</td></tr>
                                <tr><td class="text-muted">Gudang Asal</td><td><span class="badge bg-secondary">WH-01 (Karawang)</span></td></tr>
                                <tr><td class="text-muted">Term Pembayaran</td><td>Tempo 30 Hari</td></tr>
                            </table>
                        </div>
                        <div class="col-md-5">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Bukti Pesanan (Opsional)</h6>
                            <div class="border rounded-3 p-1 bg-light text-center" style="height: 120px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                {{-- Placeholder inline (data URI), menggantikan via.placeholder.com yang sudah mati. --}}
                                <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='150'%3E%3Crect width='300' height='150' fill='%23f1f3f5'/%3E%3Cpath d='M120 58h60v34h-60z' fill='none' stroke='%23adb5bd' stroke-width='3'/%3E%3Ccircle cx='137' cy='71' r='6' fill='%23adb5bd'/%3E%3Cpath d='M126 92l16-16 12 12 9-8 11 12z' fill='%23adb5bd'/%3E%3Ctext x='150' y='120' font-family='sans-serif' font-size='12' fill='%236c757d' text-anchor='middle'%3EBukti Pesanan Toko%3C/text%3E%3C/svg%3E" alt="Bukti Pesanan" class="img-fluid rounded" style="max-height: 100%; object-fit: contain;">
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info d-flex align-items-center rounded-3 p-3 mb-4">
                        <i class="bi bi-info-circle-fill fs-4 me-3 text-info"></i>
                        <div>
                            <strong>Simulasi Auto-Adjustment Stok Aktif</strong><br>
                            <span class="small">Sistem akan memotong otomatis Qty Disetujui jika stok gudang (FIFO) tidak mencukupi, dan mencatat sisanya sebagai Lost Sales.</span>
                        </div>
                    </div>
                    
                    <h6 class="fw-bold text-dark mb-2">Daftar Item Dipesan</h6>
                    <div class="table-responsive border" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-bordered table-sm mb-0 text-center align-middle" style="font-family: 'Consolas', monospace; font-size: 0.85rem;">
                            <thead style="background-color: #f3f2f1; border-bottom: 2px solid #ccc;">
                                <tr>
                                    <th class="text-start px-2">Kode SKU</th>
                                    <th class="text-start px-2">Nama Produk</th>
                                    <th>Qty_Dipesan</th>
                                    <th>Stok_Tersedia</th>
                                    <th style="background-color: #e2f0d9;">Qty_Disetujui</th>
                                    <th style="background-color: #fbe5d6;">Lost_Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-start px-2">BP-5KG-WHT</td>
                                    <td class="text-start px-2 text-truncate" style="max-width: 200px;">Cat Tembok Berger White 5Kg</td>
                                    <td>100</td>
                                    <td class="text-success fw-bold">150</td>
                                    <td style="background-color: #e2f0d9; font-weight: bold;">100</td>
                                    <td style="background-color: #fbe5d6; color: #c00000;">0</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-warning border border-warning-subtle bg-warning-subtle rounded-3 p-3 mt-4 mb-0">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-warning"><i class="bi bi-upc-scan"></i></span>
                            <input type="text" class="form-control border-warning" placeholder="Ketik Nomor Referensi ERP / SO..." required oninput="document.getElementById('btn-approve').disabled = this.value.trim() === ''">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-outline-danger px-4" onclick="rejectOrder('PO-2608-001')">Tolak Pesanan</button>
                    <button type="submit" class="btn btn-primary px-4" id="btn-approve" disabled>Terima Pesanan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Preview 2 (Document Only PO without SKUs) -->
<div class="modal fade" id="previewModal2" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark">Preview PO: <span class="text-primary">PO-2608-002</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/wms/outbound/approve/PO-2608-002" method="POST">
                @csrf
                <div class="modal-body py-4">
                    <div class="row mb-4">
                        <div class="col-md-7">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Detail Pelanggan & Pengiriman</h6>
                            <table class="table table-sm table-borderless small mb-0">
                                <tr><td class="text-muted" width="120">Customer</td><td class="fw-semibold">Toko Merah</td></tr>
                                <tr><td class="text-muted">Sales Rep</td><td>Budi Santoso</td></tr>
                                <tr><td class="text-muted">Waktu Order</td><td>18 Ags 2026, 14:15 WIB</td></tr>
                            </table>
                        </div>
                        <div class="col-md-5">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Dokumen Pesanan</h6>
                            <div class="border rounded-3 p-1 bg-light text-center" style="height: 120px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                {{-- Placeholder inline (data URI), menggantikan via.placeholder.com yang sudah mati. --}}
                                <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='150'%3E%3Crect width='300' height='150' fill='%23f1f3f5'/%3E%3Crect x='120' y='45' width='60' height='60' rx='3' fill='%23fff' stroke='%23adb5bd' stroke-width='3'/%3E%3Cpath d='M131 60h38M131 72h38M131 84h24' stroke='%23adb5bd' stroke-width='3' stroke-linecap='round'/%3E%3Ctext x='150' y='128' font-family='sans-serif' font-size='12' fill='%236c757d' text-anchor='middle'%3EDokumen Pesanan%3C/text%3E%3C/svg%3E" alt="Dokumen Pesanan" class="img-fluid rounded" style="max-height: 100%;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold text-dark mb-0">Daftar Item Dipesan</h6>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnUploadExcel"><i class="bi bi-file-earmark-excel me-1"></i> Upload Excel Item</button>
                    </div>
                    
                    <div id="emptyStateExcel" class="text-center p-5 border rounded bg-light mb-3">
                        <i class="bi bi-file-earmark-x text-muted fs-1"></i>
                        <p class="text-muted mt-2 mb-0">Sales tidak mencantumkan SKU. Harap upload dokumen Excel rincian pesanan.</p>
                    </div>

                    <div id="excelTableContainer" class="table-responsive border d-none" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-bordered table-sm mb-0 text-center align-middle" style="font-family: 'Consolas', monospace; font-size: 0.85rem;">
                            <thead style="background-color: #f3f2f1; border-bottom: 2px solid #ccc;">
                                <tr>
                                    <th class="text-start px-2">Kode SKU</th>
                                    <th class="text-start px-2">Nama Produk</th>
                                    <th>Qty_Dipesan</th>
                                    <th>Stok_Tersedia</th>
                                    <th style="background-color: #e2f0d9;">Qty_Disetujui</th>
                                    <th style="background-color: #fbe5d6;">Lost_Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-start px-2">BP-20KG-BLU</td>
                                    <td class="text-start px-2 text-truncate" style="max-width: 200px;">Cat Pelapis Berger Blue 20Kg</td>
                                    <td>50</td>
                                    <td class="text-success fw-bold">100</td>
                                    <td style="background-color: #e2f0d9; font-weight: bold;">50</td>
                                    <td style="background-color: #fbe5d6; color: #c00000;">0</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-warning border border-warning-subtle bg-warning-subtle rounded-3 p-3 mt-4 mb-0">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-warning"><i class="bi bi-upc-scan"></i></span>
                            <input type="text" class="form-control border-warning" placeholder="Ketik Nomor Referensi ERP / SO..." required oninput="document.getElementById('btn-approve2').disabled = this.value.trim() === ''">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-outline-danger px-4" onclick="rejectOrder('PO-2608-002')">Tolak Pesanan</button>
                    <button type="submit" class="btn btn-primary px-4" id="btn-approve2" disabled>Terima Pesanan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const btnUploadExcel = document.getElementById('btnUploadExcel');
        const emptyStateExcel = document.getElementById('emptyStateExcel');
        const excelTableContainer = document.getElementById('excelTableContainer');

        if (btnUploadExcel) {
            btnUploadExcel.addEventListener('click', function() {
                Swal.fire({
                    title: 'Upload File Excel',
                    html: '<p class="small text-muted mb-3">Upload rincian pesanan dari file Excel.</p><input type="file" id="excelFile" class="form-control" accept=".xlsx, .xls">',
                    showCancelButton: true,
                    confirmButtonText: 'Generate & Baca',
                    cancelButtonText: 'Batal',
                    preConfirm: () => {
                        const file = Swal.getPopup().querySelector('#excelFile').files[0];
                        if (!file) {
                            Swal.showValidationMessage('Harap pilih file Excel terlebih dahulu');
                        }
                        return { file: file }
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Membaca & Generate Data...',
                            allowOutsideClick: false,
                            didOpen: () => { Swal.showLoading(); }
                        });
                        setTimeout(() => {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil',
                                text: 'Data item berhasil digenerate dari Excel!',
                                timer: 1500,
                                showConfirmButton: false
                            });
                            emptyStateExcel.classList.add('d-none');
                            excelTableContainer.classList.remove('d-none');
                            btnUploadExcel.classList.add('d-none'); // Hide the button after success
                        }, 1500);
                    }
                });
            });
        }
    });
    function rejectOrder(poNumber) {
        // Sembunyikan modal bootstrap agar tidak memblokir fokus input pada SweetAlert
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(m => {
            const modalInstance = bootstrap.Modal.getInstance(m);
            if(modalInstance) modalInstance.hide();
        });

        setTimeout(() => {
            Swal.fire({
                title: 'Tolak Pesanan ' + poNumber + '?',
                input: 'textarea',
                inputLabel: 'Berikan Alasan Penolakan (Wajib)',
                inputPlaceholder: 'Ketik alasan penolakan di sini...',
                inputAttributes: {
                    'aria-label': 'Alasan Penolakan'
                },
                showCancelButton: true,
                confirmButtonText: 'Tolak Pesanan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc3545',
                inputValidator: (value) => {
                    if (!value || value.trim() === '') {
                        return 'Alasan penolakan tidak boleh kosong!'
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Memproses Penolakan...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    setTimeout(() => {
                        Swal.fire({
                            icon: 'success',
                            title: 'Pesanan Ditolak',
                            text: 'Pesanan ' + poNumber + ' telah ditolak dan dikembalikan ke Sales.',
                            confirmButtonColor: '#198754'
                        }).then(() => {
                            // Secara visual pindahkan ke tab riwayat (hanya untuk mockup)
                            const historyTabBtn = document.getElementById('history-tab');
                            if(historyTabBtn) {
                                const tab = new bootstrap.Tab(historyTabBtn);
                                tab.show();
                            }
                        });
                    }, 1000);
                }
            });
        }, 300); // Beri jeda 300ms agar animasi hide modal selesai
    }
</script>
@endpush
