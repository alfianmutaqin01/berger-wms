@extends('layouts.wms')
@section('title', 'Verifikasi Logistik')
@section('page_title', 'Verifikasi Bukti Surat Jalan (F-OUT-05)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Lacak seluruh pesanan yang sedang dalam perjalanan dan verifikasi dokumen Surat Jalan fisik yang diunggah oleh pihak Ekspedisi/Sales.</p>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-shield-check text-primary me-2"></i>Daftar Menunggu Verifikasi & Riwayat</h6>
        
        <!-- Filter Tabs -->
        <style>
            .nav-pills .nav-link { color: #6c757d; font-weight: 500; transition: all 0.2s; }
            .nav-pills .nav-link:hover { color: #1e3a8a; }
            .nav-pills .nav-link.active { background-color: #1e3a8a !important; color: #ffffff !important; }
            .nav-pills .nav-link.active:hover { background-color: #172554 !important; color: #ffffff !important; }
        </style>
        <ul class="nav nav-pills nav-sm" id="pills-tab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill px-4 py-2 me-2 shadow-sm" id="pills-pending-tab" data-bs-toggle="pill" data-bs-target="#pills-pending" type="button" role="tab" aria-selected="true">Menunggu Verifikasi <span class="badge bg-danger ms-2">1</span></button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill px-4 py-2" id="pills-history-tab" data-bs-toggle="pill" data-bs-target="#pills-history" type="button" role="tab" aria-selected="false">Riwayat (Selesai)</button>
            </li>
        </ul>
    </div>
    
    <div class="card-body p-4">
        <div class="tab-content" id="pills-tabContent">
            
            <!-- Tab: Menunggu Verifikasi -->
            <div class="tab-pane fade show active" id="pills-pending" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th>NO. PO / SURAT JALAN</th>
                                <th>CUSTOMER</th>
                                <th>SUPIR & KENDARAAN</th>
                                <th>STATUS</th>
                                <th class="text-end">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Item yang diupload Sales dari simulasi sebelumnya -->
                            <tr>
                                <td>
                                    <span class="fw-bold text-primary d-block mb-1">PO-1508-011</span>
                                    <small class="text-muted">SJ-00123/VIII/2026</small>
                                </td>
                                <td><span class="fw-semibold">PT Sentosa Abadi</span></td>
                                <td>
                                    <span class="d-block mb-1">Herman (0812-9988)</span>
                                    <span class="badge bg-light text-dark border">B 9901 XX</span>
                                </td>
                                <td><span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Menunggu Verifikasi WMS</span></td>
                                <td class="text-end">
                                    <button class="btn btn-sm rounded-pill px-3 shadow-sm text-white" style="background-color: #1e3a8a; border-color: #1e3a8a;" onmouseover="this.style.backgroundColor='#172554'; this.style.color='#ffffff';" onmouseout="this.style.backgroundColor='#1e3a8a'; this.style.color='#ffffff';" data-bs-toggle="modal" data-bs-target="#modalVerifikasi"><i class="bi bi-check2-circle me-1"></i> Periksa Dokumen</button>
                                </td>
                            </tr>
                            <!-- Item Masih di jalan -->
                            <tr>
                                <td>
                                    <span class="fw-bold text-primary d-block mb-1">PO-2608-001</span>
                                    <small class="text-muted">SJ-00124/VIII/2026</small>
                                </td>
                                <td><span class="fw-semibold">CV Bangun Jaya</span></td>
                                <td>
                                    <span class="d-block mb-1">Budi (0811-2233)</span>
                                    <span class="badge bg-light text-dark border">D 1234 ABC</span>
                                </td>
                                <td><span class="badge bg-secondary"><i class="bi bi-truck me-1"></i>Dalam Perjalanan</span></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled>Menunggu Upload Sales</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Tab: Riwayat (Selesai) -->
            <div class="tab-pane fade" id="pills-history" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th>NO. PO / SURAT JALAN</th>
                                <th>CUSTOMER</th>
                                <th>TANGGAL SELESAI</th>
                                <th>STATUS TERAKHIR</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <span class="fw-bold text-primary d-block mb-1">PO-1008-055</span>
                                    <small class="text-muted">SJ-00110/VIII/2026</small>
                                </td>
                                <td><span class="fw-semibold">Toko Maju Terus</span></td>
                                <td>11 Ags 2026, 16:30</td>
                                <td><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Selesai & Diverifikasi</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="fw-bold text-primary d-block mb-1">PO-0908-012</span>
                                    <small class="text-muted">SJ-00098/VIII/2026</small>
                                </td>
                                <td><span class="fw-semibold">PT Warna Indah</span></td>
                                <td>10 Ags 2026, 10:15</td>
                                <td><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Selesai & Diverifikasi</span></td>
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
<!-- Modal Verifikasi -->
<div class="modal fade" id="modalVerifikasi" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="modal-title fw-bold text-dark">Verifikasi Bukti SJ: <span class="text-primary">PO-1508-011</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="/wms/outbound/verify-bukti/PO-1508-011" method="POST" id="verifyForm">
                @csrf
                <div class="modal-body p-4">
                    <div class="row">
                        <!-- Sisi Kiri: Info SJ -->
                        <div class="col-md-5">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Informasi Pengiriman</h6>
                            <table class="table table-sm table-borderless small mb-4">
                                <tr><td class="text-muted" width="100">Customer</td><td class="fw-bold text-dark">PT Sentosa Abadi</td></tr>
                                <tr><td class="text-muted">Total Qty</td><td>150 (3 SKU)</td></tr>
                                <tr><td class="text-muted">Supir</td><td>Herman</td></tr>
                                <tr><td class="text-muted">Plat</td><td><span class="badge bg-light text-dark border">B 9901 XX</span></td></tr>
                            </table>

                            <h6 class="fw-bold text-dark mb-2">Tindakan Admin</h6>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="check1" required>
                                <label class="form-check-label small" for="check1">Surat Jalan memiliki stempel/tanda tangan toko yang sah.</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="check2" required>
                                <label class="form-check-label small" for="check2">Jumlah barang (150 Qty) diterima penuh tanpa komplain fisik.</label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small text-muted">Catatan (Opsional)</label>
                                <textarea class="form-control" rows="2" placeholder="Tidak ada catatan..."></textarea>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Bukti Foto -->
                        <div class="col-md-7">
                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Foto Unggahan Sales / Supir</h6>
                            <div class="border rounded-3 p-2 bg-light text-center position-relative" style="height: 300px; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow: hidden;">
                                <img src="https://images.unsplash.com/photo-1615538337583-0570b6d2da56?auto=format&fit=crop&q=80&w=400&h=300" alt="Bukti Surat Jalan" class="img-fluid rounded shadow-sm" style="max-height: 90%; object-fit: contain;">
                                <div class="mt-2 text-center w-100">
                                    <span class="badge bg-dark opacity-75">bukti_sj_sentosa.jpg</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4 px-4 py-3">
                    <button type="button" class="btn btn-outline-danger px-4 rounded-pill" onclick="alert('Bukti ditolak. Notifikasi perbaikan dikirim ke Sales.')"><i class="bi bi-x-circle me-1"></i> Tolak Dokumen</button>
                    <button type="submit" class="btn btn-success px-4 rounded-pill fw-bold shadow-sm"><i class="bi bi-check-all me-1"></i> Verifikasi & Selesaikan Pesanan</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    document.getElementById('verifyForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Siklus Selesai!',
            text: 'Dokumen berhasil diverifikasi. Pesanan resmi ditutup dan siap untuk diteruskan ke tim Finance (Penagihan/Billing).',
            icon: 'success',
            confirmButtonColor: '#198754'
        }).then(() => {
            window.location.reload();
        });
    });
</script>
@endpush