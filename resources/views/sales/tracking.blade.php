@extends('layouts.soms')

@section('title', 'Lacak Pesanan')
@section('page_title', 'Lacak Pesanan')

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="table-card card">
            <div class="card-header bg-white pt-4 pb-3 border-bottom-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">SO-2608-0104</h5>
                        <p class="text-muted small fw-semibold mb-0">CV. Bintang Abadi &bull; Rp 45.200.000</p>
                    </div>
                    <span class="badge bg-info fw-bold px-3 py-2"><i class="bi bi-truck me-1"></i>Dalam Pengiriman</span>
                </div>
            </div>
            <div class="card-body bg-light p-4 border-top">
                <!-- Timeline using SOMS CSS timeline classes -->
                <div class="timeline mt-2 mb-2">
                    <div class="timeline-item completed">
                        <h6 class="fw-bold mb-1">Pesanan Dibuat</h6>
                        <p class="timeline-time mb-0">14 Aug 2026, 10:00 WIB</p>
                        <p class="text-muted small mt-1">Pesanan berhasil dibuat oleh Budi Santoso.</p>
                    </div>
                    
                    <div class="timeline-item completed">
                        <h6 class="fw-bold mb-1">Disetujui Manager</h6>
                        <p class="timeline-time mb-0">14 Aug 2026, 11:30 WIB</p>
                        <p class="text-muted small mt-1">Pesanan telah disetujui dan diteruskan ke Gudang.</p>
                    </div>
                    
                    <div class="timeline-item completed">
                        <h6 class="fw-bold mb-1">Dalam Pengiriman</h6>
                        <p class="timeline-time mb-0">14 Aug 2026, 15:45 WIB</p>
                        <p class="text-muted small mt-1">Pesanan sedang dalam perjalanan. (Supir: Anton - B 1234 CD)</p>
                    </div>
                    
                    <div class="timeline-item pending border-0 pb-0">
                        <h6 class="fw-bold mb-1 text-muted">Selesai / Terkirim</h6>
                        <p class="timeline-time mb-0">Menunggu konfirmasi</p>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white py-3 border-top-0">
                <a href="/sales/dashboard" class="btn btn-outline-secondary btn-sm fw-semibold"><i class="bi bi-arrow-left"></i> Kembali ke Daftar</a>
            </div>
        </div>
    </div>
</div>
@endsection
