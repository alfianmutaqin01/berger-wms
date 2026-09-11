@extends('layouts.wms')
@section('title', 'Dashboard WMS')

{{--
    DASHBOARD UTAMA — Super Admin, Manager, dan Logistik.

    KARTU DIPERIKSA LEWAT KEBERADAAN DATANYA, BUKAN @can.
    Tiap blok dibungkus @isset($m['...']). Kuncinya hanya ada kalau
    izin pemiliknya lolos di App\Support\Reporting\AdminDashboard.
--}}

@push('styles')
<style>
    /* =========================================================
       DASHBOARD PREMIUM STYLING
       ========================================================= */
    .dashboard-hero-card {
        background: linear-gradient(135deg, #0d2540 0%, #123962 60%, #1a4f85 100%);
        border-radius: 1.25rem;
        color: #ffffff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px -8px rgba(13, 37, 64, 0.25);
    }
    .dashboard-hero-card::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 320px;
        height: 320px;
        background: radial-gradient(circle, rgba(232, 135, 30, 0.22) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    .dashboard-hero-card::after {
        content: '';
        position: absolute;
        bottom: -80px;
        left: 20%;
        width: 280px;
        height: 280px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.07) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .pulse-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #10b981;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse-green 2s infinite;
    }
    @keyframes pulse-green {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    .section-header-tag {
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
    }

    .stat-card-link {
        text-decoration: none;
        display: block;
        height: 100%;
        color: inherit;
    }

    .stat-card {
        background: #ffffff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.85);
        transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s ease, border-color 0.22s ease;
        position: relative;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(18, 57, 98, 0.04);
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 14px 28px -6px rgba(18, 57, 98, 0.12);
        border-color: rgba(18, 57, 98, 0.2);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: transparent;
    }

    .stat-card-warning::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    .stat-card-primary::before { background: linear-gradient(90deg, #123962, #1e5692); }
    .stat-card-info::before { background: linear-gradient(90deg, #0284c7, #38bdf8); }
    .stat-card-success::before { background: linear-gradient(90deg, #059669, #34d399); }
    .stat-card-danger::before { background: linear-gradient(90deg, #dc2626, #f87171); }
    .stat-card-indigo::before { background: linear-gradient(90deg, #4f46e5, #818cf8); }
    .stat-card-purple::before { background: linear-gradient(90deg, #7c3aed, #a78bfa); }
    .stat-card-secondary::before { background: linear-gradient(90deg, #475569, #94a3b8); }

    .stat-icon-badge {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
        transition: transform 0.2s ease;
    }
    .stat-card:hover .stat-icon-badge {
        transform: scale(1.06);
    }

    .stat-card-warning .stat-icon-badge { background: #fef3c7; color: #b45309; }
    .stat-card-primary .stat-icon-badge { background: #e0e9f5; color: #123962; }
    .stat-card-info .stat-icon-badge { background: #e0f2fe; color: #0369a1; }
    .stat-card-success .stat-icon-badge { background: #d1fae5; color: #047857; }
    .stat-card-danger .stat-icon-badge { background: #fee2e2; color: #b91c1c; }
    .stat-card-indigo .stat-icon-badge { background: #e0e7ff; color: #4338ca; }
    .stat-card-purple .stat-icon-badge { background: #ede9fe; color: #6d28d9; }
    .stat-card-secondary .stat-icon-badge { background: #f1f5f9; color: #475569; }

    .action-chevron {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        color: #94a3b8;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }
    .stat-card:hover .action-chevron {
        background: #123962;
        color: #ffffff;
        transform: translateX(3px);
    }

    .supervision-card {
        background: #ffffff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 2px 8px rgba(18, 57, 98, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .supervision-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px -4px rgba(18, 57, 98, 0.09);
    }

    .activity-row {
        transition: background-color 0.15s ease;
    }
    .activity-row:hover {
        background-color: #f8fafc;
    }

    /* =========================================================
       LAYAR PONSEL (< 576px)

       Sembilan kartu bertumpuk satu per baris berarti sepuluh
       layar gulir sebelum sampai grafik — pada layar 360px itu
       membuat dashboard lebih lambat dibaca daripada membuka
       menunya satu per satu. Di bawah sini kartunya dua per
       baris dan seluruh ukurannya dikecilkan bersama-sama:
       memperkecil kolomnya saja hanya menghasilkan kartu sempit
       berisi angka raksasa yang terpotong.
       ========================================================= */
    @media (max-width: 575.98px) {
        .dashboard-hero-card { border-radius: .9rem; }
        .dashboard-hero-card .p-4 { padding: 1rem !important; }
        .dashboard-hero-card h3 { font-size: 1.1rem; }
        .dashboard-hero-card p { font-size: .78rem; }

        .stat-card { border-radius: .85rem; }
        .stat-card .card-body { padding: .75rem !important; }
        .stat-card h2 { font-size: 1.35rem; }
        .stat-card h6 { font-size: .74rem; }
        .stat-card .small,
        .stat-card small { font-size: .68rem; line-height: 1.25; }

        .stat-icon-badge {
            width: 32px; height: 32px;
            border-radius: 9px;
            font-size: .95rem;
        }

        /* Tanda panah hanya hiasan yang menunjukkan kartunya bisa
           ditekan — di layar sentuh seluruh kartunya memang sudah
           bisa ditekan, jadi ia cuma memakan lebar. */
        .action-chevron { display: none !important; }

        .supervision-card { border-radius: .85rem; }
        .supervision-card .card-body { padding: .85rem !important; }
    }
</style>
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

{{-- ============================================ SECTION: ALUR OPERASIONAL (Semua Peran) --}}
<div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
        <div class="p-1 rounded-2 bg-primary-subtle text-primary">
            <i class="bi bi-arrow-repeat fs-6"></i>
        </div>
        <h6 class="section-header-tag mb-0">Alur Operasional Harian</h6>
    </div>
    <span class="text-muted small">Klik kartu untuk membuka modul terkait</span>
</div>

<div class="row g-3 mb-4">
    {{-- 1. BUTUH DITERIMA / OUTBOUND APPROVAL --}}
    @isset($m['menunggu_diterima'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.approval.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-warning">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-clipboard-check"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Butuh Diterima</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['menunggu_diterima']['jumlah'] }}</h2>
                                <span class="text-muted small">pesanan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            @if($m['menunggu_diterima']['tertua_hari'] !== null)
                                <div class="d-flex align-items-center gap-1 text-warning-emphasis small">
                                    <i class="bi bi-hourglass-split"></i>
                                    <span>Terlama menunggu <strong>{{ $m['menunggu_diterima']['tertua_hari'] }} hari</strong></span>
                                </div>
                            @else
                                <div class="text-muted small">
                                    <i class="bi bi-check2-circle text-success me-1"></i>Tidak ada antrean tertunda
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 2. VERIFIKASI INBOUND --}}
    @isset($m['inbound_menunggu'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.inbound.verify') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-indigo">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Verifikasi Inbound</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['inbound_menunggu']['jumlah'] }}</h2>
                                <span class="text-muted small">dokumen</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Barang di rak, stok belum resmi</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 3. SIAP DIPICKING --}}
    @isset($m['siap_dipicking'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.picking.batching') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-primary">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-boxes"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Siap Dipicking</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['siap_dipicking']['jumlah'] }}</h2>
                                <span class="text-muted small">pesanan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Belum masuk daftar picking</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 4. DAFTAR PICKING --}}
    @isset($m['picking_berjalan'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.picking.queue') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-info">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-list-task"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Daftar Picking</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['picking_berjalan']['dikerjakan'] }}</h2>
                                <span class="text-muted small">dikerjakan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="badge bg-info-subtle text-info-emphasis rounded-pill px-2 py-1 small">
                                {{ $m['picking_berjalan']['terbuka'] }} antrean terbuka
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 5. DALAM PENGIRIMAN --}}
    @isset($m['dalam_pengiriman'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.delivery.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-secondary">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-truck"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Dalam Pengiriman</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['dalam_pengiriman']['jumlah'] }}</h2>
                                <span class="text-muted small">surat jalan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Sudah berangkat, belum sampai</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 6. BUKTI KIRIM --}}
    @isset($m['bukti_menunggu'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.verification.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-success">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-camera"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Bukti Kirim</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['bukti_menunggu']['jumlah'] }}</h2>
                                <span class="text-muted small">menunggu</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Foto diunggah, butuh verifikasi</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 7. OUTSTANDING --}}
    @isset($m['outstanding'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.outstanding.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-danger">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Outstanding</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-danger">{{ number_format($m['outstanding']['qty']) }}</h2>
                                <span class="text-muted small">unit</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Terutang di <strong>{{ $m['outstanding']['pesanan'] }}</strong> pesanan</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 8. SEGERA KEDALUWARSA --}}
    @isset($m['segera_kedaluwarsa'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.inventory.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-danger">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-calendar-x"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Segera Kedaluwarsa</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-danger">{{ $m['segera_kedaluwarsa']['batch'] }}</h2>
                                <span class="text-muted small">batch</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">
                                {{ number_format($m['segera_kedaluwarsa']['qty']) }} unit &le; {{ $m['segera_kedaluwarsa']['ambang'] }} hari lagi
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 9. KARANTINA --}}
    @isset($m['karantina'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.inventory.index', ['status' => 'quarantine']) }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-warning">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-shield-exclamation"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Karantina</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-warning-emphasis">{{ number_format($m['karantina']['qty']) }}</h2>
                                <span class="text-muted small">unit ditahan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">
                                {{ $m['karantina']['batch'] }} batch &middot; 
                                <strong class="text-primary">{{ $m['karantina']['lepas_pekan_ini'] }} selesai pekan ini</strong>
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset
</div>

{{-- ============================================ SECTION: PENGAWASAN (Manager & Super Admin Saja)
     CATATAN PENTING: Logistik TIDAK boleh melihat teks 'Pengawasan' maupun kartu di bawah ini.
     Sesuai aturan Permission & pengetesan test_angka_kartu_terlarang_tidak_ikut_terkirim_ke_halaman. --}}
@if(isset($m['koreksi_stok']) || isset($m['stocktake']) || isset($m['pengguna']))
    <div class="d-flex align-items-center justify-content-between mb-3 mt-4">
        <div class="d-flex align-items-center gap-2">
            <div class="p-1 rounded-2 bg-secondary-subtle text-secondary">
                <i class="bi bi-shield-lock fs-6"></i>
            </div>
            <h6 class="section-header-tag mb-0">Pengawasan</h6>
        </div>
        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-3 py-1 small">
            Khusus Manager &amp; Admin
        </span>
    </div>

    <div class="row g-3 mb-4">
        {{-- KOREKSI STOK --}}
        @isset($m['koreksi_stok'])
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card h-100 supervision-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon-badge" style="background: #f1f5f9; color: #334155; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-sliders"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Koreksi Stok</h6>
                            </div>
                            <span class="badge bg-light text-muted border rounded-pill small">
                                {{ $m['koreksi_stok']['hari'] }} hari terakhir
                            </span>
                        </div>
                        <div class="d-flex align-items-baseline gap-2 mb-2">
                            <h2 class="mb-0 fw-bold text-dark">{{ $m['koreksi_stok']['jumlah'] }}</h2>
                            <span class="text-muted">kali penyesuaian</span>
                        </div>
                        {{-- "Pergeseran neto stok" tidak menjelaskan apa pun; ia
                             hanya mengulang kata "neto". Yang sebenarnya ingin
                             diketahui: setelah semua koreksi digabung, catatan
                             stok jadi LEBIH BANYAK atau LEBIH SEDIKIT — dan itu
                             dua kabar yang sangat berbeda. --}}
                        @php
                            $neto = $m['koreksi_stok']['neto'];
                        @endphp
                        <div class="pt-2 border-top">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge rounded-pill px-2 py-1 {{ $neto < 0 ? 'bg-danger-subtle text-danger' : ($neto > 0 ? 'bg-success-subtle text-success' : 'bg-light text-muted border') }}">
                                    {{ $neto > 0 ? '+' : '' }}{{ number_format($neto) }} unit
                                </span>
                                <span class="text-dark small fw-medium">
                                    @if($neto > 0)
                                        Barang lebih banyak daripada catatan
                                    @elseif($neto < 0)
                                        Barang kurang dari catatan
                                    @else
                                        Koreksinya saling menutup
                                    @endif
                                </span>
                            </div>
                            {{-- Peringatan bahwa neto BISA MENYEMBUNYIKAN kesalahan
                                 harus ada di kartunya, bukan cuma di kode: angka
                                 kecil di sini tidak berarti tidak ada masalah. --}}
                            <small class="text-muted d-block mt-1" style="font-size:.72rem">
                                Gabungan seluruh koreksi. Tambah dan kurang bisa saling menutup,
                                jadi angka kecil belum tentu berarti aman — lihat
                                <a href="{{ route('wms.reports.show', 'pergerakan-stok') }}" class="text-decoration-none">rinciannya</a>.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        @endisset

        {{-- STOCKTAKE --}}
        @isset($m['stocktake'])
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card h-100 supervision-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon-badge" style="background: #f1f5f9; color: #334155; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-check2-square"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Stocktake</h6>
                            </div>
                            @if($m['stocktake']['berjalan'] > 0)
                                <span class="badge bg-info-subtle text-info-emphasis rounded-pill small">
                                    <span class="pulse-dot me-1" style="background-color: #0284c7;"></span>
                                    {{ $m['stocktake']['berjalan'] }} sesi berjalan
                                </span>
                            @else
                                <span class="badge bg-light text-muted border rounded-pill small">Tidak ada sesi aktif</span>
                            @endif
                        </div>
                        @if($m['stocktake']['terakhir'])
                            <div class="d-flex align-items-baseline gap-2 mb-2">
                                <h2 class="mb-0 fw-bold {{ $m['stocktake']['terakhir']['selisih_qty'] > 0 ? 'text-warning-emphasis' : 'text-success' }}">
                                    {{ number_format($m['stocktake']['terakhir']['selisih_qty']) }}
                                </h2>
                                <span class="text-muted">unit selisih</span>
                            </div>
                            <div class="pt-2 border-top">
                                <div class="text-muted small">
                                    <strong>{{ $m['stocktake']['terakhir']['selisih_baris'] }} baris meleset</strong> &middot;
                                    {{ $m['stocktake']['terakhir']['referensi'] }} ({{ $m['stocktake']['terakhir']['tanggal'] }})
                                </div>
                            </div>
                        @else
                            <div class="mb-2">
                                <h2 class="mb-0 fw-bold text-muted">&mdash;</h2>
                            </div>
                            <div class="pt-2 border-top">
                                <span class="text-muted small">Belum ada sesi stocktake yang disahkan</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endisset

        {{-- PENGGUNA --}}
        @isset($m['pengguna'])
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card h-100 supervision-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon-badge" style="background: #f1f5f9; color: #334155; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-people"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Pengguna</h6>
                            </div>
                            <span class="badge bg-success-subtle text-success rounded-pill small">
                                <span class="pulse-dot me-1"></span>
                                {{ $m['pengguna']['sesi_hidup'] }} online
                            </span>
                        </div>
                        <div class="d-flex align-items-baseline gap-2 mb-2">
                            <h2 class="mb-0 fw-bold text-dark">{{ $m['pengguna']['aktif'] }}</h2>
                            <span class="text-muted">karyawan aktif</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 pt-2 border-top">
                            <span class="text-muted small">
                                {{ $m['pengguna']['sesi_hidup'] }} sesi aktif dalam 30 menit &middot;
                                <span class="text-secondary">{{ $m['pengguna']['nonaktif'] }} dinonaktifkan</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        @endisset
    </div>
@endif

{{-- ================================================ SECTION: PAPAN PERINGKAT

     Angkanya dihitung ReportRunner yang sama dengan halaman Laporan, jadi
     urutan di sini dan di berkas Excel tidak akan pernah berbeda. --}}
@isset($m['terlaris'])
<div class="row g-4 mb-4">
    {{-- PRODUK TERLARIS --}}
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100 rounded-4" style="background:#fff;border:1px solid rgba(226,232,240,.85);">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-1 rounded-2 bg-warning-subtle text-warning-emphasis">
                            <i class="bi bi-trophy fs-6"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">Produk Terlaris</h6>
                    </div>
                    {{-- "Terkirim", bukan "dipesan". Pesanan yang masuk tetapi
                         barangnya kosong bukan penjualan. --}}
                    <small class="text-muted">Qty terkirim {{ $m['terlaris']['hari'] }} hari terakhir</small>
                </div>
                <a href="{{ route('wms.reports.show', 'produk-terlaris') }}"
                   class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1">
                    Laporan <i class="bi bi-chevron-right ms-1 small"></i>
                </a>
            </div>
            <div class="card-body pt-0 px-4 pb-4">
                @forelse($m['terlaris']['produk'] as $i => $p)
                    <div class="d-flex align-items-center gap-3 py-2 {{ $i > 0 ? 'border-top border-light-subtle' : '' }}">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center flex-shrink-0
                                     {{ $i === 0 ? 'bg-warning text-dark' : 'bg-light text-secondary border' }}"
                              style="width:28px;height:28px;">{{ $i + 1 }}</span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem">{{ $p['nama'] }}</div>
                            <small class="text-muted font-monospace" style="font-size:.72rem">{{ $p['sku'] }}</small>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold text-dark">{{ number_format($p['terkirim']) }}</div>
                            <small class="text-muted" style="font-size:.7rem">
                                {{ $p['satuan'] }} · {{ $p['pesanan'] }} pesanan
                            </small>
                        </div>
                    </div>
                @empty
                    <p class="text-muted small text-center mb-0 py-4">
                        Belum ada pengiriman dalam {{ $m['terlaris']['hari'] }} hari terakhir.
                    </p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- PELANGGAN TERATAS --}}
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100 rounded-4" style="background:#fff;border:1px solid rgba(226,232,240,.85);">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-1 rounded-2 bg-info-subtle text-info-emphasis">
                            <i class="bi bi-people fs-6"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">Pelanggan Teratas</h6>
                    </div>
                    <small class="text-muted">Qty diterima {{ $m['terlaris']['hari'] }} hari terakhir</small>
                </div>
                <a href="{{ route('wms.reports.show', 'pelanggan-teratas') }}"
                   class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1">
                    Laporan <i class="bi bi-chevron-right ms-1 small"></i>
                </a>
            </div>
            <div class="card-body pt-0 px-4 pb-4">
                @forelse($m['terlaris']['pelanggan'] as $i => $c)
                    <div class="d-flex align-items-center gap-3 py-2 {{ $i > 0 ? 'border-top border-light-subtle' : '' }}">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center flex-shrink-0
                                     {{ $i === 0 ? 'bg-info text-dark' : 'bg-light text-secondary border' }}"
                              style="width:28px;height:28px;">{{ $i + 1 }}</span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem">{{ $c['nama'] }}</div>
                            <small class="text-muted font-monospace" style="font-size:.72rem">{{ $c['kode'] }}</small>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold text-dark">{{ number_format($c['terkirim']) }}</div>
                            <small class="text-muted" style="font-size:.7rem">{{ $c['pesanan'] }} pesanan</small>
                        </div>
                    </div>
                @empty
                    <p class="text-muted small text-center mb-0 py-4">
                        Belum ada pengiriman dalam {{ $m['terlaris']['hari'] }} hari terakhir.
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endisset

{{-- ============================================ SECTION: TREN & AKTIVITAS --}}
<div class="row g-4 mb-4">
    {{-- GRAFIK TREN PESANAN --}}
    @isset($m['tren'])
        <div class="col-12 {{ isset($m['aktivitas']) ? 'col-xl-8' : '' }}">
            <div class="card shadow-sm border-0 h-100 rounded-4" style="background: #ffffff; border: 1px solid rgba(226, 232, 240, 0.85);">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="p-1 rounded-2 bg-primary-subtle text-primary">
                                    <i class="bi bi-graph-up-arrow fs-6"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Pesanan Masuk vs Selesai</h6>
                            </div>
                            <small class="text-muted">Perbandingan volume pesanan masuk dan pemenuhan {{ count($m['tren']['label']) }} bulan terakhir</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-dark border rounded-pill px-3 py-1 small">
                                <span class="d-inline-block rounded-circle me-1" style="width: 8px; height: 8px; background-color: #123962;"></span>
                                Masuk: <strong>{{ array_sum($m['tren']['masuk']) }}</strong>
                            </span>
                            <span class="badge bg-light text-dark border rounded-pill px-3 py-1 small">
                                <span class="d-inline-block rounded-circle me-1" style="width: 8px; height: 8px; background-color: #10b981;"></span>
                                Selesai: <strong>{{ array_sum($m['tren']['selesai']) }}</strong>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body px-4 pb-4 pt-2">
                    <div style="min-height: 320px; height: 320px; width: 100%;">
                        <canvas id="grafikTren"></canvas>
                    </div>
                </div>
            </div>
        </div>
    @endisset

    {{-- LOG AKTIVITAS (Super Admin Saja) --}}
    @isset($m['aktivitas'])
        <div class="col-12 col-xl-4">
            <div class="card shadow-sm border-0 h-100 rounded-4" style="background: #ffffff; border: 1px solid rgba(226, 232, 240, 0.85);">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-1 rounded-2 bg-primary-subtle text-primary">
                            <i class="bi bi-activity fs-6"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">Aktivitas Terbaru</h6>
                    </div>
                    <a href="{{ route('wms.admin.activity-log') }}" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1">
                        Semua <i class="bi bi-chevron-right ms-1 small"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush border-0">
                        @forelse($m['aktivitas'] as $log)
                            <div class="list-group-item px-4 py-3 border-0 border-bottom border-light-subtle activity-row">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 small fw-semibold">
                                        {{ $log->action_label }}
                                    </span>
                                    <small class="text-muted text-nowrap" style="font-size: 0.75rem;">
                                        <i class="bi bi-clock me-1"></i>{{ $log->created_at?->diffForHumans(short: true) }}
                                    </small>
                                </div>
                                <p class="mb-1 text-dark small fw-medium" style="line-height: 1.4;">
                                    {{ $log->description }}
                                </p>
                                <div class="d-flex align-items-center gap-1 text-muted" style="font-size: 0.75rem;">
                                    <i class="bi bi-person text-secondary"></i>
                                    <span>{{ $log->pelaku }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-5 px-4">
                                <div class="text-muted mb-2"><i class="bi bi-inbox fs-1"></i></div>
                                <p class="text-muted mb-0 small">Belum ada aktivitas tercatat.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endisset
</div>
@endsection

@push('scripts')
@isset($m['tren'])
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('grafikTren');
        if (!ctx) return;

        const chartContext = ctx.getContext('2d');
        const gradientMasuk = chartContext.createLinearGradient(0, 0, 0, 300);
        gradientMasuk.addColorStop(0, 'rgba(18, 57, 98, 0.22)');
        gradientMasuk.addColorStop(1, 'rgba(18, 57, 98, 0.01)');

        const gradientSelesai = chartContext.createLinearGradient(0, 0, 0, 300);
        gradientSelesai.addColorStop(0, 'rgba(16, 185, 129, 0.22)');
        gradientSelesai.addColorStop(1, 'rgba(16, 185, 129, 0.01)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($m['tren']['label']),
                datasets: [
                    {
                        label: 'Pesanan Masuk',
                        data: @json($m['tren']['masuk']),
                        borderColor: '#123962',
                        backgroundColor: gradientMasuk,
                        borderWidth: 2.5,
                        pointBackgroundColor: '#123962',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.38,
                    },
                    {
                        label: 'Pesanan Selesai',
                        data: @json($m['tren']['selesai']),
                        borderColor: '#10b981',
                        backgroundColor: gradientSelesai,
                        borderWidth: 2.5,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.38,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            padding: 20,
                            font: { family: "'Inter', sans-serif", size: 12, weight: 500 },
                        },
                    },
                    tooltip: {
                        backgroundColor: '#0d2540',
                        titleFont: { family: "'Inter', sans-serif", size: 12, weight: 600 },
                        bodyFont: { family: "'Inter', sans-serif", size: 12 },
                        padding: 12,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: "'Inter', sans-serif", size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(226, 232, 240, 0.6)' },
                        ticks: {
                            precision: 0,
                            font: { family: "'Inter', sans-serif", size: 11 },
                            callback: function (val) { return val + ' PO'; },
                        },
                    },
                },
            },
        });
    });
</script>
@endisset
@endpush
