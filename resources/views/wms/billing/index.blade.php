@extends('layouts.wms')
@section('title', 'Billing & Penagihan')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Billing & Penagihan (F-BILL-01)</h4>
        <p class="text-muted">Pantau status piutang, tanggal jatuh tempo, dan kelola pelunasan tagihan kustomer.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<!-- Statistik Singkat -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 rounded-4 shadow-sm bg-danger text-white h-100">
            <div class="card-body p-4">
                <h6 class="fw-semibold opacity-75 mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Overdue (Menunggak)</h6>
                <h3 class="fw-bold mb-0">15 Pesanan</h3>
                <small class="opacity-75">Terkait 1 Kustomer Diblokir</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 rounded-4 shadow-sm bg-warning text-dark h-100">
            <div class="card-body p-4">
                <h6 class="fw-semibold opacity-75 mb-1"><i class="bi bi-clock-history me-2"></i>Menunggu Pembayaran</h6>
                <h3 class="fw-bold mb-0">32 Pesanan</h3>
                <small class="opacity-75">Total 1.500 Item Terkirim</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 rounded-4 shadow-sm bg-success text-white h-100">
            <div class="card-body p-4">
                <h6 class="fw-semibold opacity-75 mb-1"><i class="bi bi-check-circle-fill me-2"></i>Lunas (Bulan Ini)</h6>
                <h3 class="fw-bold mb-0">128 Pesanan</h3>
                <small class="opacity-75">Siklus transaksi berhasil ditutup</small>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-receipt text-primary me-2"></i>Daftar Tagihan Tempo</h6>
        <div class="d-flex gap-2">
            <select class="form-select form-select-sm bg-light border-0">
                <option value="">Semua Status</option>
                <option value="overdue">Overdue</option>
                <option value="belum_lunas">Belum Lunas</option>
            </select>
        </div>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="table-light small text-muted">
                    <tr>
                        <th class="text-start">NO. PO</th>
                        <th class="text-start">CUSTOMER</th>
                        <th>TANGGAL ORDER</th>
                        <th>TERM (TEMPO)</th>
                        <th>JATUH TEMPO</th>
                        <th>TOTAL QTY</th>
                        <th>STATUS</th>
                        <th class="text-end">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Baris 1: Overdue -->
                    <tr class="table-danger border-danger">
                        <td class="text-start fw-bold">PO-2607-088</td>
                        <td class="text-start fw-semibold">Toko Merah</td>
                        <td>15 Jul 2026</td>
                        <td><span class="badge bg-secondary">30 Hari</span></td>
                        <td class="text-danger fw-bold"><i class="bi bi-exclamation-circle-fill me-1"></i> 14 Ags 2026</td>
                        <td>150 Pcs</td>
                        <td><span class="badge bg-danger">Overdue</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-success rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#confirmModal"><i class="bi bi-check-circle me-1"></i> Lunas</button>
                        </td>
                    </tr>
                    <!-- Baris 2: Belum Lunas (Masih Aman) -->
                    <tr>
                        <td class="text-start fw-bold">PO-2608-001</td>
                        <td class="text-start fw-semibold">CV Bangun Jaya</td>
                        <td>18 Ags 2026</td>
                        <td><span class="badge bg-secondary">60 Hari</span></td>
                        <td class="text-dark">17 Okt 2026</td>
                        <td>115 Pcs</td>
                        <td><span class="badge bg-warning text-dark">Belum Lunas</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-success rounded-pill px-3" onclick="alert('Belum jatuh tempo. Konfirmasi manual diperlukan.')"><i class="bi bi-check-circle"></i> Lunas</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Konfirmasi Pelunasan (F-BILL-02) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark">Konfirmasi Pembayaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/wms/billing/confirm/PO-2607-088" method="POST">
                @csrf
                <div class="modal-body py-4">
                    <p class="text-muted small mb-4">Anda akan memverifikasi pelunasan untuk tagihan <strong>PO-2607-088 (Toko Merah)</strong>. <br>Tindakan ini akan <strong>mencabut blokir pemesanan</strong> dari pelanggan ini.</p>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal Pembayaran Diterima <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="payment_date" required value="{{ date('Y-m-d') }}">
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success px-4 shadow-sm"><i class="bi bi-check-circle-fill me-1"></i> Konfirmasi Lunas</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection