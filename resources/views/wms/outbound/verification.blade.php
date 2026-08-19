@extends('layouts.wms')
@section('title', 'Verifikasi Bukti')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Verifikasi Bukti Fisik (F-OUT-06)</h4>
        <p class="text-muted">Pencocokan dokumen fisik (Surat Jalan basah) yang diunggah Sales untuk penyelesaian akhir transaksi.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-shield-check text-primary me-2"></i>Daftar Menunggu Verifikasi</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th>NO. SURAT JALAN</th>
                        <th>NO. PO</th>
                        <th>CUSTOMER</th>
                        <th>STATUS</th>
                        <th class="text-end">BUKTI FISIK</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="fw-bold text-dark">SJ-KRW-2026-00001</span></td>
                        <td><span class="text-muted">PO-2608-001</span></td>
                        <td>CV Bangun Jaya</td>
                        <td><span class="badge bg-warning text-dark">Menunggu Verifikasi</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#verifyModal"><i class="bi bi-image me-1"></i> Lihat Dokumen</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Verifikasi -->
<div class="modal fade" id="verifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark">Verifikasi: <span class="text-primary">SJ-KRW-2026-00001</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/wms/outbound/verify-bukti/PO-2608-001" method="POST">
                @csrf
                <div class="modal-body py-4">
                    <div class="row">
                        <div class="col-12 col-md-6 mb-3 mb-md-0 text-center">
                            <div class="border rounded-3 p-2 bg-light d-flex align-items-center justify-content-center" style="height: 300px;">
                                <i class="bi bi-file-earmark-image fs-1 text-muted d-block w-100"></i>
                                <span class="d-block text-muted small position-absolute">Simulasi Gambar SJ.jpg</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 d-flex flex-column justify-content-center">
                            <h6 class="fw-bold text-dark mb-3">Detail Kesesuaian</h6>
                            <ul class="list-unstyled small text-muted mb-4">
                                <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i> Tanda Tangan Customer: <strong>Ada</strong></li>
                                <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i> Cap Basah Toko: <strong>Ada</strong></li>
                                <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i> Qty Diterima Sesuai: <strong>Ya (115 Pcs)</strong></li>
                                <li class="mb-2"><i class="bi bi-calendar-check text-success me-2"></i> SLA Pengiriman: <strong>4 Jam (Tercapai)</strong></li>
                            </ul>
                            
                            <div class="alert alert-warning py-2 small mb-0">
                                Pastikan gambar yang diunggah dapat terbaca dengan jelas sebelum menyetujui.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-danger px-4" data-bs-dismiss="modal">Tolak (Ulangi Upload)</button>
                    <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-circle me-1"></i> Verifikasi & Selesaikan</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection