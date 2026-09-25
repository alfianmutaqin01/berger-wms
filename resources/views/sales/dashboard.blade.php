@extends('layouts.soms')

@section('title', 'Dashboard Sales')
@section('page_title', 'Pekerjaan & Pesanan Anda')

{{--
    DASHBOARD SALES - Mobile-First Ergonomic Design.
    Sangat ramah layar ponsel: ringkas, padat informasi, tanpa scroll berlebih.
    Telah diperbaiki agar isi grid tidak terpotong (Flexbox Layout).
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
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        font-weight: 700;
        text-transform: uppercase;
        color: #475569;
    }

    /* CSS tombol Quick Action (.btn-action-*) DIHAPUS bersama tombolnya.
       Jalur ke Buat Pesanan dan Pesanan Saya sudah ada di sidebar; dua jalan
       ke tempat yang sama memakan ruang layar HP tanpa menambah kemampuan.
       Gaya yang ditinggalkan tanpa pemakainya akan disalin orang berikutnya
       sebagai "gaya yang sudah ada di halaman ini". */

    /* Action Alert Banners */
    .action-alert-banner {
        border-radius: 0.75rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        background: #ffffff;
    }
    .alert-clean-success {
        border-left: 4px solid #10b981;
    }
    .alert-clean-danger {
        border-left: 4px solid #ef4444;
    }

    /* Sleek 2x2 Warehouse Pipeline Tile - DIPERBAIKI (Flexbox & Height) */
    .card-tile-link {
        text-decoration: none;
        color: inherit;
        display: block;
        height: 100%;
    }
    .card-tile {
        background: #ffffff;
        border-radius: 0.75rem;
        border: 1px solid rgba(226, 232, 240, 0.95);
        padding: 10px 12px;
        box-shadow: 0 1px 4px rgba(18, 57, 98, 0.04);
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        position: relative;
        /* Flexbox untuk mengatur jarak konten merata dari atas ke bawah */
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 115px; 
        height: 100%; 
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
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 600;
        margin-top: 6px;
        margin-bottom: 2px;
        line-height: 1.2;
    }

    .tile-value {
        font-size: 1.4rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1;
        margin-top: 4px;
        margin-bottom: 2px;
    }

    .tile-subtext {
        font-size: 0.65rem;
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
<div class="sales-hero-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <div>
            <span class="text-white-50 d-block mb-1" style="font-size: 0.75rem;">
                Halo, <strong>{{ auth()->user()?->full_name ?? 'Sales' }}</strong> 👋
            </span>
            <h5 class="fw-bold mb-0 text-white" style="font-size: 1.15rem;">Pekerjaan &amp; Pesanan Anda</h5>
        </div>
        <span class="badge bg-white text-dark rounded-pill px-2 py-1 shadow-sm fw-medium" style="font-size: 0.75rem;">
            {{ \Carbon\Carbon::now()->translatedFormat('d M Y') }}
        </span>
    </div>

    {{-- Cutoff Status Pill (Super Slim) --}}
    <div class="pt-2 mt-1 border-top border-white-50 border-opacity-25">
        @if($cutoffOpen)
            <div class="d-flex align-items-center gap-2 text-white" style="font-size: 0.8rem;">
                <i class="bi bi-unlock-fill text-warning"></i>
                <span>Masih bisa submit sampai <strong class="text-warning">{{ $cutoffLabel }}</strong></span>
            </div>
        @else
            <div class="d-flex align-items-center gap-2 bg-warning text-dark rounded px-2 py-1 fw-semibold" style="font-size: 0.8rem;">
                <i class="bi bi-lock-fill"></i>
                <span>Lewat <strong>{{ $cutoffLabel }}</strong> &mdash; masuk antrean besok</span>
            </div>
        @endif
    </div>
</div>

{{-- ============================================ 2. PROMO & INFO TERBARU ============================================ --}}
@push('styles')
<style>
    /* Styling khusus untuk Banner Promo Mobile */
    .promo-carousel {
        border-radius: 0.85rem;
        overflow: hidden;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        position: relative;
    }
    .promo-item {
        height: 110px; /* Ukuran pas untuk layar HP */
        background-size: cover;
        background-position: center;
        display: flex;
        align-items: center;
        padding: 1rem 1.25rem;
        position: relative;
        text-decoration: none;
    }
    .promo-item::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        /* Gradien gelap agar teks putih selalu terbaca di atas gambar apa pun */
        background: linear-gradient(90deg, rgba(0,0,0,0.75) 0%, rgba(0,0,0,0.1) 100%);
    }
    .promo-content {
        position: relative;
        z-index: 1;
        color: #ffffff;
    }
    .promo-badge {
        background-color: #ef4444; /* Warna merah */
        color: #ffffff;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        padding: 2px 8px;
        border-radius: 4px;
        margin-bottom: 6px;
        display: inline-block;
        text-transform: uppercase;
    }
    .promo-title {
        font-size: 0.95rem;
        font-weight: 800;
        margin-bottom: 2px;
        line-height: 1.2;
    }
    .promo-desc {
        font-size: 0.7rem;
        color: rgba(255, 255, 255, 0.9);
        margin-bottom: 0;
    }
    /* Kustomisasi indikator titik-titik slide */
    .carousel-indicators {
        margin-bottom: 0.5rem;
    }
    .carousel-indicators [data-bs-target] {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background-color: rgba(255,255,255,0.6);
        border: none;
    }
    .carousel-indicators .active {
        background-color: #ffffff;
        width: 14px;
        border-radius: 4px;
    }
</style>
@endpush

{{-- Isinya dari config/wms.php ('promo_sales'), bukan ditulis di sini: satu
     sampai tiga slide, dan layar menyesuaikan sendiri. Tanpa slide sama
     sekali, bagian ini tidak digambar — kotak kosong bertuliskan "belum ada
     promo" cuma memakan ruang layar HP. --}}
@if(count($promo) > 0)
@php($banyakPromo = count($promo))
<div id="promoCarousel" class="carousel slide promo-carousel mb-3"
     @if($banyakPromo > 1) data-bs-ride="carousel" data-bs-interval="6000" @endif>
    {{-- Satu slide tidak punya titik indikator: titik tunggal yang tidak bisa
         diklik ke mana-mana hanya menyarankan ada slide lain yang tersembunyi. --}}
    @if($banyakPromo > 1)
    <div class="carousel-indicators">
        @foreach($promo as $i => $slide)
            <button type="button" data-bs-target="#promoCarousel" data-bs-slide-to="{{ $i }}"
                    class="{{ $i === 0 ? 'active' : '' }}"
                    @if($i === 0) aria-current="true" @endif
                    aria-label="Slide {{ $i + 1 }}"></button>
        @endforeach
    </div>
    @endif

    <div class="carousel-inner">
        @foreach($promo as $i => $slide)
            @php($latar = filled($slide['gambar'] ?? null)
                ? "url('".e($slide['gambar'])."')"
                : ($slide['warna'] ?? 'linear-gradient(45deg, #123962, #1e5692)'))
            <div class="carousel-item {{ $i === 0 ? 'active' : '' }}">
                <div class="promo-item" style="background-image: {{ $latar }};">
                    <div class="promo-content">
                        @if(filled($slide['label'] ?? null))
                            <span class="promo-badge">{{ $slide['label'] }}</span>
                        @endif
                        <h3 class="promo-title">{{ $slide['judul'] ?? '' }}</h3>
                        @if(filled($slide['keterangan'] ?? null))
                            <p class="promo-desc">{{ $slide['keterangan'] }}</p>
                        @endif

                        {{-- Slide tanpa tautan tetap terbaca, hanya tidak bisa
                             diklik. Tautan yang menuju "#" lebih buruk daripada
                             tidak ada tautan: ia menjanjikan halaman yang tidak
                             pernah terbuka. --}}
                        @if(filled($slide['tautan'] ?? null))
                            <a href="{{ $slide['tautan'] }}" class="stretched-link"
                               aria-label="{{ $slide['judul'] ?? 'Buka promo' }}"></a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- ============================================ 3. BUTUH TINDAKAN ANDA (Unified Clean List) --}}
@if($m['perlu_tindakan']['total'] > 0)
    <div class="action-alert-banner alert-clean-danger p-0 mb-3 shadow-sm overflow-hidden">
        <div class="px-3 py-2 d-flex justify-content-between align-items-center bg-danger bg-opacity-10 border-bottom border-danger-subtle">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-6"></i>
                <span class="fw-bold text-dark" style="font-size: 0.85rem;">Butuh Tindakan Anda</span>
            </div>
            <span class="badge bg-danger rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                {{ $m['perlu_tindakan']['total'] }} Perlu Diselesaikan
            </span>
        </div>

        <div class="list-group list-group-flush">
            {{-- DRAFT ITEMS --}}
            @if($m['daftar_draft']->isNotEmpty())
                @foreach($m['daftar_draft'] as $draft)
                    <div class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0" style="font-size: 0.65rem;">Draft</span>
                                <span class="fw-bold text-truncate text-dark" style="font-size: 0.85rem;">{{ $draft->customer?->name ?? 'Tanpa customer' }}</span>
                            </div>
                            <div class="text-muted text-truncate" style="font-size: 0.75rem;">
                                {{ $draft->order_number }} &middot; {{ $draft->details_count }} item
                                @unless($cutoffOpen) &middot; <span class="text-danger fw-semibold">Lewat batas hari ini</span> @endunless
                            </div>
                        </div>
                        <a href="/sales/orders/{{ $draft->id }}" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 flex-shrink-0 fw-semibold" style="font-size: 0.75rem;">
                            Lanjutkan <i class="bi bi-chevron-right ms-1"></i>
                        </a>
                    </div>
                @endforeach
            @elseif($m['perlu_tindakan']['draft'] > 0)
                <div class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center gap-2">
                    <div class="min-w-0 flex-grow-1">
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0 mb-1 d-inline-block" style="font-size: 0.65rem;">Draft</span>
                        <span class="fw-bold text-dark d-block" style="font-size: 0.85rem;">{{ $m['perlu_tindakan']['draft'] }} Draft Belum Dikirim</span>
                        <div class="text-muted" style="font-size: 0.75rem;">Belum masuk antrean gudang logistik</div>
                    </div>
                    <a href="/sales/my-orders?status=draft" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 flex-shrink-0" style="font-size: 0.75rem;">
                        Lihat <i class="bi bi-chevron-right ms-1"></i>
                    </a>
                </div>
            @endif

            {{-- BUKTI KIRIM ITEMS --}}
            @if($m['daftar_bukti']->isNotEmpty())
                @foreach($m['daftar_bukti'] as $pesanan)
                    @php($ditolak = ($pesanan->bukti_ditolak_count ?? 0) > 0)
                    <div class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                @if($ditolak)
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0" style="font-size: 0.65rem;">Bukti Ditolak</span>
                                @else
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0" style="font-size: 0.65rem;">Foto Bukti</span>
                                @endif
                                <span class="fw-bold text-truncate text-dark" style="font-size: 0.85rem;">{{ $pesanan->customer?->name ?? '-' }}</span>
                            </div>
                            <div class="text-muted text-truncate" style="font-size: 0.75rem;">
                                {{ $pesanan->order_number }}
                                @if($ditolak)
                                    &middot; <span class="text-danger fw-semibold">Foto sebelumnya ditolak, unggah ulang</span>
                                @elseif($pesanan->delivered_at)
                                    &middot; Sampai {{ $pesanan->delivered_at->diffForHumans(short: true) }}
                                @endif
                            </div>
                        </div>
                        <a href="/sales/orders/{{ $pesanan->id }}" class="btn btn-sm btn-primary rounded-pill px-3 py-1 flex-shrink-0 fw-semibold" style="font-size: 0.75rem;">
                            <i class="bi bi-camera me-1"></i> Unggah Bukti Kirim
                        </a>
                    </div>
                @endforeach
            @elseif($m['perlu_tindakan']['bukti'] > 0)
                <div class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center gap-2">
                    <div class="min-w-0 flex-grow-1">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0 mb-1 d-inline-block" style="font-size: 0.65rem;">Foto Bukti</span>
                        <span class="fw-bold text-dark d-block" style="font-size: 0.85rem;">{{ $m['perlu_tindakan']['bukti'] }} Pesanan Menunggu Bukti</span>
                        <div class="text-muted" style="font-size: 0.75rem;">Foto Surat Jalan bertanda tangan pelanggan</div>
                    </div>
                    {{-- proof_uploaded, bukan shipping: yang boleh diunggah
                         hanya pesanan yang sudah dinyatakan sampai. --}}
                    <a href="/sales/my-orders?status=proof_uploaded" class="btn btn-sm btn-primary rounded-pill px-3 py-1 flex-shrink-0" style="font-size: 0.75rem;">
                        Unggah Bukti Kirim <i class="bi bi-chevron-right ms-1"></i>
                    </a>
                </div>
            @endif

            {{-- DITOLAK ITEMS --}}
            @if($m['perlu_tindakan']['ditolak'] > 0)
                <div class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center gap-2">
                    <div class="min-w-0 flex-grow-1">
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0 mb-1 d-inline-block" style="font-size: 0.65rem;">Ditolak</span>
                        <span class="fw-bold text-dark d-block" style="font-size: 0.85rem;">{{ $m['perlu_tindakan']['ditolak'] }} Pesanan Ditolak</span>
                        <div class="text-muted" style="font-size: 0.75rem;">Dapat diperbaiki lalu diajukan ulang</div>
                    </div>
                    <a href="/sales/my-orders?status=rejected" class="btn btn-sm btn-outline-warning rounded-pill px-3 py-1 flex-shrink-0" style="font-size: 0.75rem;">
                        Perbaiki <i class="bi bi-chevron-right ms-1"></i>
                    </a>
                </div>
            @endif
        </div>
    </div>
@else
    {{-- Feedback tenang saat semua beres (Super Slim) --}}
    <div class="action-alert-banner alert-clean-success p-3 mb-3 shadow-sm d-flex align-items-center gap-3">
        <i class="bi bi-check-circle-fill text-success fs-4 flex-shrink-0"></i>
        <div class="min-w-0">
            <div class="fw-bold text-dark" style="font-size: 0.85rem;">Tidak ada yang menunggu tindakan Anda</div>
            <div class="text-muted text-truncate" style="font-size: 0.75rem;">
                Semua draft sudah diajukan dan bukti kirim lengkap.
            </div>
        </div>
    </div>
@endif

{{-- ============================================ 4. STATUS PESANAN DI GUDANG (2x2 Grid) --}}
<div class="d-flex justify-content-between align-items-center mb-2 px-1">
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary-subtle text-primary rounded-2 p-1">
            <i class="bi bi-truck fs-6"></i>
        </span>
        {{-- Ditulis wajar, bukan kapital semua: `.section-tag` yang membuatnya
             tampil kapital, sehingga pembaca layar dan pencarian teks tetap
             membaca kalimat biasa. --}}
        <h6 class="section-tag mb-0 text-dark">Pesanan Anda di Gudang</h6>
    </div>
    <span class="text-muted" style="font-size: 0.7rem;">Status pemenuhan</span>
</div>

{{-- Gunakan align-items-stretch agar tinggi row seragam --}}
<div class="row g-2 mb-4 align-items-stretch">
    {{-- Menunggu Diterima --}}
    <div class="col-6">
        <a href="/sales/my-orders?status=pending" class="card-tile-link">
            <div class="card-tile tile-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-hourglass-split"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label">Menunggu Diterima</div>
                {{-- Flex Wrapper untuk mendorong konten subtext ke bawah jika ada sisa ruang --}}
                <div class="d-flex flex-column flex-grow-1 justify-content-end">
                    <div class="tile-value">{{ $m['menunggu_gudang']['jumlah'] ?? 0 }}</div>
                    <div class="tile-subtext text-truncate">
                        @if(!empty($m['menunggu_gudang']['tertua_hari']))
                            <span class="text-warning-emphasis fw-medium">Terlama {{ $m['menunggu_gudang']['tertua_hari'] }} hari</span>
                        @else
                            Tidak ada antrean
                        @endif
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Sedang Diproses --}}
    <div class="col-6">
        <a href="/sales/my-orders" class="card-tile-link">
            <div class="card-tile tile-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-box-seam"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label">Sedang Diproses</div>
                <div class="d-flex flex-column flex-grow-1 justify-content-end">
                    <div class="tile-value">{{ $m['berjalan']['jumlah'] ?? 0 }}</div>
                    <div class="tile-subtext text-truncate">
                        Disetujui s/d pengiriman
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Masih Kurang (Outstanding) --}}
    <div class="col-6">
        <a href="/sales/my-orders" class="card-tile-link">
            <div class="card-tile tile-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-exclamation-diamond"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label">Masih Kurang</div>
                <div class="d-flex flex-column flex-grow-1 justify-content-end">
                    <div class="tile-value text-danger">{{ number_format($m['outstanding']['qty'] ?? 0) }}</div>
                    <div class="tile-subtext text-truncate">
                        unit di {{ $m['outstanding']['pesanan'] ?? 0 }} pesanan
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Selesai Bulan Ini --}}
    <div class="col-6">
        <a href="/sales/my-orders?status=completed" class="card-tile-link">
            <div class="card-tile tile-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="tile-icon"><i class="bi bi-check2-circle"></i></div>
                    <span class="tile-arrow"><i class="bi bi-chevron-right"></i></span>
                </div>
                <div class="tile-label">Selesai Bulan Ini</div>
                <div class="d-flex flex-column flex-grow-1 justify-content-end">
                    <div class="tile-value text-success">{{ $m['selesai_bulan_ini']['jumlah'] ?? 0 }}</div>
                    <div class="tile-subtext text-truncate">
                        Pesanan tuntas
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

{{-- ============================================ 5. GRAFIK TREN (Compact & Tidy) --}}
<div class="chart-box p-3 mb-3 shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-graph-up text-primary" style="font-size: 0.9rem;"></i>
                <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.85rem;">Pesanan Dibuat vs Selesai</h6>
            </div>
            <small class="text-muted" style="font-size: 0.7rem;">{{ count($m['tren']['label'] ?? []) }} bulan terakhir</small>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                Dibuat: <strong>{{ array_sum($m['tren']['dibuat'] ?? []) }}</strong>
            </span>
            <span class="badge bg-light text-dark border rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                Selesai: <strong>{{ array_sum($m['tren']['selesai'] ?? []) }}</strong>
            </span>
        </div>
    </div>
    <div class="chart-container-mobile mt-2">
        <canvas id="grafikSales"></canvas>
    </div>
</div>

{{-- Safe Area Spacer for Bottom Nav --}}
<div class="pb-3"></div>
@endsection

@push('scripts')
{{-- Versi terkunci + hash integritas: tanpa keduanya, CDN yang dibajak bisa
     menjalankan skrip apa pun di layar Sales. Hash ini sama dengan yang
     dipakai dashboard Admin — lihat wms/dashboard/_admin-skrip.blade.php. --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('grafikSales');
        if (!ctx) return;

        const isSmallScreen = window.innerWidth < 576;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($m['tren']['label'] ?? []),
                datasets: [
                    {
                        label: 'Dibuat',
                        data: @json($m['tren']['dibuat'] ?? []),
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
                        data: @json($m['tren']['selesai'] ?? []),
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