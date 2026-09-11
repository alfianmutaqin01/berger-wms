@extends('layouts.soms')

@section('title', 'Dashboard Sales')
@section('page_title', 'Pekerjaan & Pesanan Anda')

{{--
    DASHBOARD SALES — Mobile-First Ergonomic Design.
    Sangat ramah layar ponsel: ringkas, padat informasi, tanpa scroll berlebih.
--}}

@push('styles')
<style>
    /* =========================================================
       SALES MOBILE-FIRST DASHBOARD STYLING (SUPER COMPACT)
       ========================================================= */
    .sales-hero-card {
        background: linear-gradient(135deg, #0d2540 0%, #123962 60%, #1a4f85 100%);
        border-radius: 0.85rem;
        color: #ffffff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 14px -3px rgba(13, 37, 64, 0.18);
    }
    .sales-hero-card::before {
        content: '';
        position: absolute;
        top: -40px; right: -30px;
        width: 160px; height: 160px;
        background: radial-gradient(circle, rgba(232, 135, 30, .25) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .section-tag {
        font-size: 0.72rem;
        letter-spacing: 0.06em;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
    }

    /* Quick Action Buttons */
    .btn-quick-action {
        height: 38px;
        min-height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 0.65rem;
        text-decoration: none;
        transition: transform 0.12s ease;
    }
    .btn-quick-action:active {
        transform: scale(0.97);
    }

    /* Action Alert Banners */
    .action-alert-banner {
        border-radius: 0.75rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: #ffffff;
    }
    .alert-clean-success {
        border-left: 3.5px solid #10b981;
    }
    .alert-clean-danger {
        border-left: 3.5px solid #ef4444;
    }

    .action-chip-card {
        border: 1px solid rgba(226, 232, 240, 0.8);
        background: #fafbfc;
        transition: background-color 0.15s ease;
    }
    .action-chip-card:hover, .action-chip-card:active {
        background: #f1f5f9;
    }

    /* Sleek 2x2 Warehouse Pipeline Tile */
    .card-tile-link {
        text-decoration: none;
        color: inherit;
        display: block;
    }
    .card-tile {
        background: #ffffff;
        border-radius: 0.75rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        padding: 9px 11px;
        box-shadow: 0 1px 4px rgba(18, 57, 98, 0.04);
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        position: relative;
        overflow: hidden;
    }
    .card-tile:active {
        transform: scale(0.97);
    }
    .card-tile:hover {
        border-color: rgba(18, 57, 98, 0.2);
        box-shadow: 0 4px 10px rgba(18, 57, 98, 0.08);
    }

    .tile-warning { border-left: 3.5px solid #f59e0b; }
    .tile-info    { border-left: 3.5px solid #0284c7; }
    .tile-danger  { border-left: 3.5px solid #ef4444; }
    .tile-success { border-left: 3.5px solid #10b981; }

    .tile-icon {
        width: 26px;
        height: 26px;
        border-radius: 7px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
    }
    .tile-warning .tile-icon { background: #fef3c7; color: #b45309; }
    .tile-info .tile-icon    { background: #e0f2fe; color: #0369a1; }
    .tile-danger .tile-icon  { background: #fee2e2; color: #b91c1c; }
    .tile-success .tile-icon { background: #d1fae5; color: #047857; }

    .tile-arrow {
        font-size: 0.75rem;
        color: #cbd5e1;
        transition: transform 0.15s ease;
    }
    .card-tile-link:hover .tile-arrow {
        transform: translate(2px, -2px);
        color: #123962;
    }

    .tile-label {
        font-size: 0.72rem;
        color: #64748b;
        font-weight: 500;
        margin-top: 3px;
    }

    .tile-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
        margin: 2px 0;
    }

    .tile-subtext {
        font-size: 0.67rem;
        color: #94a3b8;
        line-height: 1.2;
    }

    /* Mobile Chart Container */
    .chart-box {
        background: #ffffff;
        border-radius: 0.75rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        box-shadow: 0 1px 4px rgba(18, 57, 98, 0.04);
    }
    .chart-container-mobile {
        height: 165px !important;
        min-height: 165px !important;
        max-height: 175px !important;
        position: relative;
    }
    @media (min-width: 768px) {
        .chart-container-mobile {
            height: 220px !important;
            min-height: 220px !important;
            max-height: 230px !important;
        }
    }
</style>
@endpush

@section('content')
{{-- ============================================ 1. COMPACT HERO & CUTOFF BANNER --}}
<div class="sales-hero-card p-2.5 px-3 p-sm-3 mb-2">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <div>
            <span class="text-white-50 small d-block" style="font-size: 0.72rem;">
                Halo, <strong>{{ auth()->user()?->full_name ?? 'Sales' }}</strong> 👋
            </span>
            <h5 class="fw-bold mb-0 text-white" style="font-size: 1.05rem;">Pekerjaan &amp; Pesanan Anda</h5>
        </div>
        <span class="badge bg-white text-dark rounded-pill px-2 py-1 shadow-sm small fw-medium" style="font-size: 0.7rem;">
            {{ \Carbon\Carbon::now()->translatedFormat('d M Y') }}
        </span>
    </div>

    {{-- Cutoff Status Pill (Super Slim) --}}
    <div class="pt-1.5 border-top border-white-50 border-opacity-25">
        @if($cutoffOpen)
            <div class="d-flex align-items-center gap-1.5 text-white small" style="font-size: 0.76rem;">
                <i class="bi bi-unlock-fill text-warning"></i>
                <span>Masih bisa submit sampai <strong class="text-warning">{{ $cutoffLabel }}</strong></span>
            </div>
        @else
            <div class="d-flex align-items-center gap-1.5 bg-warning text-dark rounded px-2 py-0.5 small fw-semibold" style="font-size: 0.74rem;">
                <i class="bi bi-lock-fill"></i>
                <span>Lewat <strong>{{ $cutoffLabel }}</strong> &mdash; masuk antrean besok</span>
            </div>
        @endif
    </div>
</div>

{{-- ============================================ 2. QUICK ACTIONS (Compact) --}}
<div class="row g-2 mb-2.5">
    <div class="col-6">
        <a href="/sales/new-order" class="btn-quick-action text-white shadow-sm" style="background: linear-gradient(135deg, #123962 0%, #1e5692 100%);">
            <i class="bi bi-plus-circle-fill text-warning"></i>
            <span>Pesanan Baru</span>
        </a>
    </div>
    <div class="col-6">
        <a href="/sales/my-orders" class="btn-quick-action text-dark bg-white border shadow-sm">
            <i class="bi bi-card-list text-primary"></i>
            <span>Pesanan Saya</span>
        </a>
    </div>
</div>

{{-- ============================================ 3. BUTUH TINDAKAN ANDA --}}
@if($m['perlu_tindakan']['total'] > 0)
    <div class="action-alert-banner alert-clean-danger p-2.5 px-3 mb-2.5 shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-1.5">
                <i class="bi bi-exclamation-circle-fill text-danger fs-6"></i>
                <h6 class="section-tag mb-0 text-dark">Butuh Tindakan Anda</h6>
            </div>
            <span class="badge bg-danger rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                {{ $m['perlu_tindakan']['total'] }} Perlu Diselesaikan
            </span>
        </div>

        <div class="row g-1.5">
            @if($m['perlu_tindakan']['draft'] > 0)
                <div class="col-12 col-md-4">
                    <a href="/sales/my-orders?status=draft" class="action-chip-card d-flex align-items-center justify-content-between p-2 rounded-2 text-decoration-none">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                            <i class="bi bi-pencil-square text-danger fs-6"></i>
                            <div class="min-w-0">
                                <div class="fw-bold text-dark small" style="font-size: 0.78rem;">Draft Belum Dikirim</div>
                                <div class="text-muted small text-truncate" style="font-size: 0.68rem;">
                                    Belum terlihat oleh Logistik sama sekali.
                                    @unless($cutoffOpen) <span class="text-danger fw-semibold">Batas hari ini sudah lewat.</span> @endunless
                                </div>
                            </div>
                        </div>
                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2 fw-bold ms-1" style="font-size: 0.72rem;">{{ $m['perlu_tindakan']['draft'] }}</span>
                    </a>
                </div>
            @endif

            @if($m['perlu_tindakan']['ditolak'] > 0)
                <div class="col-12 col-md-4">
                    <a href="/sales/my-orders?status=rejected" class="action-chip-card d-flex align-items-center justify-content-between p-2 rounded-2 text-decoration-none">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                            <i class="bi bi-arrow-counterclockwise text-warning fs-6"></i>
                            <div class="min-w-0">
                                <div class="fw-bold text-dark small" style="font-size: 0.78rem;">Ditolak</div>
                                <div class="text-muted small text-truncate" style="font-size: 0.68rem;">
                                    Masih bisa diperbaiki lalu diajukan ulang.
                                </div>
                            </div>
                        </div>
                        <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill px-2 fw-bold ms-1" style="font-size: 0.72rem;">{{ $m['perlu_tindakan']['ditolak'] }}</span>
                    </a>
                </div>
            @endif

            @if($m['perlu_tindakan']['bukti'] > 0)
                <div class="col-12 col-md-4">
                    <div class="action-chip-card d-flex align-items-center justify-content-between p-2 rounded-2">
                        <div class="d-flex align-items-center gap-2 min-w-0">
                            <i class="bi bi-camera text-primary fs-6"></i>
                            <div class="min-w-0">
                                <div class="fw-bold text-dark small" style="font-size: 0.78rem;">Menunggu Foto Bukti</div>
                                <div class="text-muted small text-truncate" style="font-size: 0.68rem;">
                                    Barang sudah jalan. Pesanan belum dianggap selesai sampai buktinya masuk.
                                </div>
                            </div>
                        </div>
                        <span class="badge bg-primary-subtle text-primary rounded-pill px-2 fw-bold ms-1" style="font-size: 0.72rem;">{{ $m['perlu_tindakan']['bukti'] }}</span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- DAFTAR UNGGAH BUKTI (Compact List) --}}
    @if($m['daftar_bukti']->isNotEmpty())
        <div class="action-alert-banner p-0 mb-2.5 shadow-sm">
            <div class="px-3 py-2 d-flex justify-content-between align-items-center border-bottom border-light-subtle bg-light bg-opacity-50">
                <span class="fw-bold small text-dark" style="font-size: 0.78rem;">
                    <i class="bi bi-camera me-1 text-primary"></i> Unggah Bukti Kirim
                </span>
                <a href="/sales/my-orders?status=shipping" class="text-decoration-none small text-muted" style="font-size: 0.72rem;">Semua</a>
            </div>
            <div class="list-group list-group-flush">
                @foreach($m['daftar_bukti'] as $pesanan)
                    <div class="list-group-item px-3 py-1.5 d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="fw-bold text-truncate text-dark" style="font-size: 0.82rem;">{{ $pesanan->customer?->name ?? '—' }}</div>
                            <div class="text-muted small" style="font-size: 0.7rem;">
                                {{ $pesanan->order_number }}
                                @if($pesanan->shipped_at) &middot; {{ $pesanan->shipped_at->diffForHumans(short: true) }} @endif
                            </div>
                        </div>
                        <a href="/sales/orders/{{ $pesanan->id }}" class="btn btn-sm btn-primary rounded-pill px-2.5 py-0.5 flex-shrink-0" style="font-size: 0.75rem;">
                            <i class="bi bi-upload me-1"></i> Unggah
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- DAFTAR DRAFT (Compact List) --}}
    @if($m['daftar_draft']->isNotEmpty())
        <div class="action-alert-banner p-0 mb-2.5 shadow-sm">
            <div class="px-3 py-2 d-flex justify-content-between align-items-center border-bottom border-light-subtle bg-light bg-opacity-50">
                <span class="fw-bold small text-dark" style="font-size: 0.78rem;">
                    <i class="bi bi-pencil-square me-1 text-danger"></i> Draft Menunggu Dikirim
                </span>
                <a href="/sales/my-orders?status=draft" class="text-decoration-none small text-muted" style="font-size: 0.72rem;">Semua</a>
            </div>
            <div class="list-group list-group-flush">
                @foreach($m['daftar_draft'] as $draft)
                    <div class="list-group-item px-3 py-1.5 d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="fw-bold text-truncate text-dark" style="font-size: 0.82rem;">{{ $draft->customer?->name ?? 'Tanpa customer' }}</div>
                            <div class="text-muted small" style="font-size: 0.7rem;">
                                {{ $draft->details_count }} item &middot; {{ $draft->updated_at?->diffForHumans(short: true) }}
                            </div>
                        </div>
                        <a href="/sales/orders/{{ $draft->id }}" class="btn btn-sm btn-outline-danger rounded-pill px-2.5 py-0.5 flex-shrink-0" style="font-size: 0.75rem;">
                            Buka
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@else
    {{-- Feedback tenang saat semua beres (Super Slim) --}}
    <div class="action-alert-banner alert-clean-success p-2 px-3 mb-2.5 shadow-sm d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill text-success fs-6 flex-shrink-0"></i>
        <div class="min-w-0">
            <div class="fw-bold text-dark small" style="font-size: 0.78rem;">Tidak ada yang menunggu tindakan Anda</div>
            <div class="text-muted small text-truncate" style="font-size: 0.7rem;">
                Tidak ada draft yang belum dikirim, tidak ada pesanan ditolak, dan semua bukti kirim sudah diunggah.
            </div>
        </div>
    </div>
@endif

{{-- ============================================ 4. PESANAN ANDA DI GUDANG (Sleek 2x2 Grid) --}}
<div class="d-flex align-items-center justify-content-between mb-1.5 mt-2">
    <div class="d-flex align-items-center gap-1.5">
        <div class="p-1 rounded bg-primary-subtle text-primary"><i class="bi bi-truck fs-6"></i></div>
        <h6 class="section-tag mb-0">Pesanan Anda di Gudang</h6>
    </div>
    <span class="text-muted small" style="font-size: 0.7rem;">Status pemenuhan</span>
</div>

<div class="row g-2 mb-3">
    {{-- Menunggu Diterima --}}
    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders?status=pending" class="card-tile-link">
            <div class="card-tile tile-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-hourglass-split"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label text-truncate">Menunggu Diterima</div>
                <div class="tile-value">{{ $m['menunggu_gudang']['jumlah'] }}</div>
                <div class="tile-subtext text-truncate">
                    @if($m['menunggu_gudang']['tertua_hari'] !== null)
                        <span class="text-warning-emphasis fw-medium">Terlama {{ $m['menunggu_gudang']['tertua_hari'] }} hari</span>
                    @else
                        Tidak ada antrean
                    @endif
                </div>
            </div>
        </a>
    </div>

    {{-- Sedang Diproses --}}
    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders" class="card-tile-link">
            <div class="card-tile tile-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-box-seam"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label text-truncate">Sedang Diproses</div>
                <div class="tile-value">{{ $m['berjalan']['jumlah'] }}</div>
                <div class="tile-subtext text-truncate">
                    Disetujui s/d pengiriman
                </div>
            </div>
        </a>
    </div>

    {{-- Masih Kurang (Outstanding) --}}
    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders" class="card-tile-link">
            <div class="card-tile tile-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-exclamation-diamond"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label text-truncate">Masih Kurang</div>
                <div class="tile-value text-danger">{{ number_format($m['outstanding']['qty']) }}</div>
                <div class="tile-subtext text-truncate">
                    unit di {{ $m['outstanding']['pesanan'] }} pesanan
                </div>
            </div>
        </a>
    </div>

    {{-- Selesai Bulan Ini --}}
    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders?status=completed" class="card-tile-link">
            <div class="card-tile tile-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-check2-circle"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label text-truncate">Selesai Bulan Ini</div>
                <div class="tile-value text-success">{{ $m['selesai_bulan_ini']['jumlah'] }}</div>
                <div class="tile-subtext text-truncate">
                    Pesanan tuntas
                </div>
            </div>
        </a>
    </div>
</div>

{{-- ============================================ 5. GRAFIK TREN (Compact & Tidy) --}}
<div class="chart-box p-2.5 px-3 mb-2 shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <div>
            <div class="d-flex align-items-center gap-1.5">
                <i class="bi bi-graph-up text-primary" style="font-size: 0.85rem;"></i>
                <h6 class="fw-bold mb-0 text-dark small" style="font-size: 0.84rem;">Pesanan Dibuat vs Selesai</h6>
            </div>
            <small class="text-muted" style="font-size: 0.68rem;">{{ count($m['tren']['label']) }} bulan terakhir</small>
        </div>
        <div class="d-flex align-items-center gap-1.5">
            <span class="badge bg-light text-dark border rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                Dibuat: <strong>{{ array_sum($m['tren']['dibuat']) }}</strong>
            </span>
            <span class="badge bg-light text-dark border rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                Selesai: <strong>{{ array_sum($m['tren']['selesai']) }}</strong>
            </span>
        </div>
    </div>
    <div class="chart-container-mobile">
        <canvas id="grafikSales"></canvas>
    </div>
</div>

{{-- Safe Area Spacer for Bottom Nav --}}
<div class="pb-2"></div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('grafikSales');
        if (!ctx) return;

        const isSmallScreen = window.innerWidth < 576;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($m['tren']['label']),
                datasets: [
                    {
                        label: 'Dibuat',
                        data: @json($m['tren']['dibuat']),
                        borderColor: '#123962',
                        backgroundColor: 'rgba(18, 57, 98, 0.08)',
                        borderWidth: 2,
                        pointBackgroundColor: '#123962',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1.5,
                        pointRadius: isSmallScreen ? 2.5 : 3.5,
                        fill: true,
                        tension: 0.35,
                    },
                    {
                        label: 'Selesai',
                        data: @json($m['tren']['selesai']),
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.08)',
                        borderWidth: 2,
                        pointBackgroundColor: '#059669',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1.5,
                        pointRadius: isSmallScreen ? 2.5 : 3.5,
                        fill: true,
                        tension: 0.35,
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
                            boxWidth: 6,
                            padding: isSmallScreen ? 8 : 12,
                            font: { size: isSmallScreen ? 10 : 11 },
                        },
                    },
                    tooltip: {
                        padding: 8,
                        cornerRadius: 6,
                        titleFont: { size: 11 },
                        bodyFont: { size: 10 },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: isSmallScreen ? 9 : 10.5 },
                            maxRotation: 0,
                        },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(226, 232, 240, 0.5)' },
                        ticks: {
                            precision: 0,
                            font: { size: isSmallScreen ? 9 : 10.5 },
                        },
                    },
                },
            },
        });
    });
</script>
@endpush
