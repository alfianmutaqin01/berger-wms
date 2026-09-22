@extends('layouts.wms')
@section('title', 'Dashboard WMS')

{{--
    DASHBOARD UTAMA — Super Admin, Manager, dan Logistik.

    KARTU DIPERIKSA LEWAT KEBERADAAN DATANYA, BUKAN @can.
    Tiap blok dibungkus @isset($m['...']). Kuncinya hanya ada kalau
    izin pemiliknya lolos di App\Support\Reporting\AdminDashboard.
--}}

@push('styles')
@include('wms.dashboard._admin-gaya')
@endpush

@section('content')
{{-- ============================================ HERO / HEADER BANNER --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="dashboard-hero-card p-4">
            <div class="row align-items-center g-3">
                <div class="col-12 col-lg-8">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="pulse-dot"></span>
                        <span class="text-uppercase small fw-bold tracking-wider opacity-75" style="letter-spacing: 0.06em;">
                            Sistem Manajemen Gudang &middot; Berger Paints
                        </span>
                    </div>
                    <h3 class="fw-bold mb-1 text-white">Pusat Kendali Logistik &amp; Operasional</h3>
                    <p class="text-white-50 mb-0 small">
                        Pantau kelancaran alur barang masuk, antrean picking, verifikasi pengiriman, dan integritas stok secara real-time.
                    </p>
                </div>
                <div class="col-12 col-lg-4 text-lg-end">
                    <div class="d-inline-flex flex-column align-items-lg-end gap-2">
                        <span class="badge bg-white text-dark rounded-pill px-3 py-2 shadow-sm fw-medium">
                            <i class="bi bi-building me-1 text-primary"></i>
                            {{ $gudang ?? 'Seluruh gudang' }}
                        </span>
                        <div class="text-white-50 small">
                            <i class="bi bi-calendar3 me-1"></i>
                            {{ \Carbon\Carbon::now()->translatedFormat('l, d F Y') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@include('wms.dashboard._admin-alur')

@include('wms.dashboard._admin-pengawasan')

@include('wms.dashboard._admin-peringkat')

@include('wms.dashboard._admin-tren')
@endsection

@push('scripts')
@include('wms.dashboard._admin-skrip')
@endpush
