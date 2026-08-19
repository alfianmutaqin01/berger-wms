@extends('layouts.wms')
@section('title', 'Approval Pesanan')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Approval Pesanan (F-OUT-02)</h4>
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
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-ui-checks-grid text-primary me-2"></i>Daftar Menunggu Approval</h6>
        <div class="input-group input-group-sm w-25">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" class="form-control bg-light border-start-0" placeholder="Cari No PO / Gudang...">
        </div>
    </div>
    <div class="card-body p-4">
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
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Preview & Approval -->
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
                                <!-- Simulasi jika ada gambar yang diupload -->
                                <img src="https://via.placeholder.com/300x150.png?text=Bukti+Pesanan+Toko" alt="Bukti Pesanan" class="img-fluid rounded" style="max-height: 100%; object-fit: contain;">
                            </div>
                            <div class="text-center mt-1"><small class="text-muted"><i class="bi bi-paperclip"></i> bukti_pesanan.jpg</small></div>
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
                    <div class="table-responsive border rounded-3">
                        <table class="table table-bordered mb-0 text-center align-middle">
                            <thead class="table-light small">
                                <tr>
                                    <th class="text-start">SKU / Produk</th>
                                    <th>Qty Dipesan</th>
                                    <th>Stok Tersedia</th>
                                    <th class="bg-primary-subtle">Qty Disetujui</th>
                                    <th class="bg-danger-subtle">Lost Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-start small fw-semibold">BP-5KG-WHT<br><span class="text-muted fw-normal">Cat Tembok Berger White 5Kg</span></td>
                                    <td>100</td>
                                    <td><span class="badge bg-success">150</span></td>
                                    <td class="bg-primary-subtle fw-bold">100</td>
                                    <td class="bg-danger-subtle text-danger">0</td>
                                </tr>
                                <tr>
                                    <td class="text-start small fw-semibold">BP-20KG-BLU<br><span class="text-muted fw-normal">Cat Pelapis Berger Blue 20Kg</span></td>
                                    <td>20</td>
                                    <td><span class="badge bg-warning text-dark">15</span></td>
                                    <td class="bg-primary-subtle fw-bold text-primary">15</td>
                                    <td class="bg-danger-subtle text-danger fw-bold">5</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> Approve Pesanan</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection