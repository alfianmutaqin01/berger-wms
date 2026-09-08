@extends('layouts.wms')
@section('title', 'Dashboard Produksi')

{{--
    DASHBOARD PRODUKSI — empat angka, dan batasnya disengaja.

    Versi lama halaman ini menampilkan target produksi, mesin aktif, dan stok
    bahan baku menipis. Tidak satu pun ada di sistem ini: tidak ada tabel
    bahan baku, tidak ada mesin, tidak ada purchasing. Angka seperti itu bukan
    sekadar dummy yang belum diisi — ia menjanjikan modul yang tidak pernah
    dibangun, dan selama masih terpasang orang menunggu angkanya berubah
    sendiri suatu hari.

    Alasan pemilihan keempat angka penggantinya ada di
    App\Support\Reporting\ProductionDashboard.
--}}

@push('styles')
<style>
    .dashboard-hero-card {
        background: linear-gradient(135deg, #0d2540 0%, #123962 60%, #1a4f85 100%);
        border-radius: 1.25rem;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px -8px rgba(13, 37, 64, .25);
    }
    .dashboard-hero-card::before {
        content: '';
        position: absolute;
        top: -60px; right: -60px;
        width: 320px; height: 320px;
        background: radial-gradient(circle, rgba(232, 135, 30, .22) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .section-header-tag {
        font-size: .72rem;
        letter-spacing: .08em;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
    }

    .stat-card-link { text-decoration: none; display: block; height: 100%; color: inherit; }

    .stat-card {
        background: #fff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, .85);
        transition: transform .22s cubic-bezier(.16, 1, .3, 1), box-shadow .22s ease, border-color .22s ease;
        position: relative;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(18, 57, 98, .04);
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 14px 28px -6px rgba(18, 57, 98, .12);
        border-color: rgba(18, 57, 98, .2);
    }
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: transparent;
    }

    .stat-card-warning::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    .stat-card-indigo::before  { background: linear-gradient(90deg, #4f46e5, #818cf8); }
    .stat-card-success::before { background: linear-gradient(90deg, #059669, #34d399); }
    .stat-card-danger::before  { background: linear-gradient(90deg, #dc2626, #f87171); }

    .stat-icon-badge {
        width: 46px; height: 46px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
        transition: transform .2s ease;
    }
    .stat-card:hover .stat-icon-badge { transform: scale(1.06); }

    .stat-card-warning .stat-icon-badge { background: #fef3c7; color: #b45309; }
    .stat-card-indigo .stat-icon-badge  { background: #e0e7ff; color: #4338ca; }
    .stat-card-success .stat-icon-badge { background: #d1fae5; color: #047857; }
    .stat-card-danger .stat-icon-badge  { background: #fee2e2; color: #b91c1c; }

    .action-chevron {
        width: 28px; height: 28px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: #f8fafc;
        color: #94a3b8;
        font-size: .85rem;
        transition: all .2s ease;
    }
    .stat-card:hover .action-chevron { background: #123962; color: #fff; transform: translateX(3px); }

    .panel-card {
        background: #fff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, .9);
        box-shadow: 0 2px 8px rgba(18, 57, 98, .04);
    }
</style>
@endpush

@section('content')
{{-- ------------------------------------------------------------- HERO --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="dashboard-hero-card p-4">
            <div class="row align-items-center g-3">
                <div class="col-12 col-lg-8">
                    <span class="text-uppercase small fw-bold opacity-75" style="letter-spacing:.06em;">
                        Produksi &middot; Serah Terima Barang Jadi
                    </span>
                    <h3 class="fw-bold mb-1 mt-2 text-white">Barang yang Sudah Anda Serahkan</h3>
                    <p class="text-white-50 mb-0 small">
                        Setiap dokumen melewati dua tahap sesudah diserahkan: dinaikkan ke rak oleh
                        Operator, lalu diakui resmi oleh Logistik. Halaman ini menunjukkan sampai
                        mana perjalanannya.
                    </p>
                </div>
                <div class="col-12 col-lg-4 text-lg-end">
                    <div class="d-inline-flex flex-column align-items-lg-end gap-2">
                        <span class="badge bg-white text-dark rounded-pill px-3 py-2 shadow-sm fw-medium">
                            <i class="bi bi-building me-1 text-primary"></i>
                            {{ $gudang ?? 'Seluruh gudang' }}
                        </span>
                        <a href="{{ route('wms.inbound.create') }}" class="btn btn-light btn-sm rounded-pill px-3 fw-semibold">
                            <i class="bi bi-plus-circle me-1"></i> Buat Dokumen Inbound
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ------------------------------------------------------------ KARTU --}}
<div class="d-flex align-items-center gap-2 mb-3">
    <div class="p-1 rounded-2 bg-primary-subtle text-primary"><i class="bi bi-arrow-repeat fs-6"></i></div>
    <h6 class="section-header-tag mb-0">Perjalanan Serahan Anda</h6>
</div>

<div class="row g-3 mb-4">
    {{-- 1. Sudah diserahkan, belum naik rak. --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="{{ route('wms.inbound.history') }}" class="stat-card-link">
            <div class="card h-100 stat-card stat-card-warning">
                <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="stat-icon-badge"><i class="bi bi-hourglass-split"></i></div>
                            <div class="action-chevron"><i class="bi bi-arrow-right"></i></div>
                        </div>
                        <h6 class="text-muted fw-normal mb-1">Menunggu Naik Rak</h6>
                        <div class="d-flex align-items-baseline gap-2">
                            <h2 class="mb-0 fw-bold text-dark">{{ $m['menunggu_putaway']['dokumen'] }}</h2>
                            <span class="text-muted small">dokumen</span>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top border-light-subtle">
                        <span class="text-muted small">
                            {{ $m['menunggu_putaway']['palet'] }} palet menunggu diangkat
                        </span>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- 2. Sudah di rak, tapi stoknya BELUM RESMI — yang paling sering
         disalahpahami: barang terlihat ada tetapi belum bisa dijual. --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="{{ route('wms.inbound.history') }}" class="stat-card-link">
            <div class="card h-100 stat-card stat-card-indigo">
                <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="stat-icon-badge"><i class="bi bi-patch-check"></i></div>
                            <div class="action-chevron"><i class="bi bi-arrow-right"></i></div>
                        </div>
                        <h6 class="text-muted fw-normal mb-1">Menunggu Verifikasi</h6>
                        <div class="d-flex align-items-baseline gap-2">
                            <h2 class="mb-0 fw-bold text-dark">{{ $m['menunggu_verifikasi']['dokumen'] }}</h2>
                            <span class="text-muted small">dokumen</span>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top border-light-subtle">
                        <span class="text-muted small">Sudah di rak, stok belum resmi</span>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- 3. Hasil yang sudah tuntas. --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="{{ route('wms.inbound.history') }}" class="stat-card-link">
            <div class="card h-100 stat-card stat-card-success">
                <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="stat-icon-badge"><i class="bi bi-check2-circle"></i></div>
                            <div class="action-chevron"><i class="bi bi-arrow-right"></i></div>
                        </div>
                        <h6 class="text-muted fw-normal mb-1">Selesai Bulan Ini</h6>
                        <div class="d-flex align-items-baseline gap-2">
                            <h2 class="mb-0 fw-bold text-dark">{{ $m['masuk_bulan_ini']['dokumen'] }}</h2>
                            <span class="text-muted small">dokumen</span>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top border-light-subtle">
                        <span class="text-muted small">
                            {{ number_format($m['masuk_bulan_ini']['unit']) }} unit masuk resmi
                        </span>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- 4. Satu-satunya kabar buruk di halaman ini, dan justru yang paling
         berguna: yang dinyatakan Produksi tidak sama dengan yang sampai di
         rak. Tanpa kartu ini, selisihnya baru ketahuan lewat stocktake
         berbulan-bulan kemudian. --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <a href="{{ route('wms.inbound.history') }}" class="stat-card-link">
            <div class="card h-100 stat-card stat-card-danger">
                <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="stat-icon-badge"><i class="bi bi-exclamation-diamond"></i></div>
                            <div class="action-chevron"><i class="bi bi-arrow-right"></i></div>
                        </div>
                        <h6 class="text-muted fw-normal mb-1">Selisih Saat Naik Rak</h6>
                        <div class="d-flex align-items-baseline gap-2">
                            <h2 class="mb-0 fw-bold {{ $m['selisih_putaway']['baris'] > 0 ? 'text-danger' : 'text-dark' }}">
                                {{ $m['selisih_putaway']['baris'] }}
                            </h2>
                            <span class="text-muted small">palet</span>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-top border-light-subtle">
                        @if($m['selisih_putaway']['baris'] > 0)
                            <span class="text-danger small">
                                {{ number_format($m['selisih_putaway']['unit']) }} unit meleset,
                                {{ $m['selisih_putaway']['hari'] }} hari terakhir
                            </span>
                        @else
                            <span class="text-muted small">
                                <i class="bi bi-check2 text-success me-1"></i>Semua cocok
                                ({{ $m['selisih_putaway']['hari'] }} hari terakhir)
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

{{-- --------------------------------------------------- DOKUMEN TERAKHIR --}}
<div class="card panel-card">
    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-0">
        <h6 class="fw-bold mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Serahan Terakhir</h6>
        <a href="{{ route('wms.inbound.history') }}" class="btn btn-sm btn-link text-decoration-none">Riwayat lengkap</a>
    </div>
    <div class="card-body px-4 pb-4 pt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small">
                    <tr>
                        <th>NO. DOKUMEN</th>
                        <th>TGL PRODUKSI</th>
                        <th class="text-center">PALET</th>
                        <th>DIBUAT</th>
                        <th class="text-end">STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($m['terakhir'] as $dok)
                        <tr>
                            <td class="fw-semibold">{{ $dok->document_number }}</td>
                            <td class="small text-muted">{{ $dok->production_date?->translatedFormat('d M Y') }}</td>
                            <td class="text-center">{{ $dok->details_count }}</td>
                            <td class="small text-muted">{{ $dok->created_at?->diffForHumans(short: true) }}</td>
                            <td class="text-end">
                                <span @class([
                                    'badge rounded-pill',
                                    'bg-warning-subtle text-warning-emphasis' => $dok->status === \App\Models\InboundHeader::STATUS_PUTAWAY_PENDING,
                                    'bg-primary-subtle text-primary-emphasis' => in_array($dok->status, [
                                        \App\Models\InboundHeader::STATUS_VERIFICATION_PENDING,
                                        \App\Models\InboundHeader::STATUS_PARTIAL_VERIFIED,
                                    ], true),
                                    'bg-success-subtle text-success-emphasis' => $dok->status === \App\Models\InboundHeader::STATUS_VERIFIED,
                                ])>{{ $dok->status_label }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                Belum ada dokumen inbound.
                                <a href="{{ route('wms.inbound.create') }}">Buat yang pertama</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
