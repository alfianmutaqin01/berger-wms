@extends('layouts.wms')

@section('title', 'Permintaan Material (MRF)')
@section('page_title', 'Permintaan Material Produksi')

@push('styles')
<style>
    .mrf-stat-card {
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        background: #ffffff;
    }
    .mrf-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(18, 57, 98, 0.08) !important;
    }
    .mrf-stat-active {
        box-shadow: 0 4px 14px rgba(18, 57, 98, 0.1) !important;
        position: relative;
    }
    .mrf-stat-active::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 20%;
        right: 20%;
        height: 3px;
        background: currentColor;
        border-radius: 3px;
    }
    .table-mrf thead th {
        background-color: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
        padding: 0.75rem 0.85rem;
    }
    .table-mrf tbody td {
        padding: 0.85rem 0.85rem;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
    }
    .table-mrf tbody tr:hover td {
        background-color: #f8fafc;
    }
</style>
@endpush

@section('content')
{{-- DAFTAR MRF — dibaca empat peran dengan kepentingan yang berbeda:
     Produksi memantau permintaannya, Logistik mencari yang menunggu
     keputusannya, Operator memastikan tugas yang ia pegang milik siapa, dan
     Manager melihat keseluruhannya. Kolom "Menunggu Tindakan" dengan jelas
     menampilkan posisi berkas dan pihak penanggung jawab aksi selanjutnya. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3 d-flex align-items-center mb-3" role="alert">
        <i class="bi bi-{{ $ikon }} fs-5 me-2 flex-shrink-0"></i>
        <div>{{ session($jenis) }}</div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

{{-- Metric / Status KPI Cards --}}
<div class="row g-2.5 g-md-3 mb-3">
    @foreach([
        ['Menunggu Persetujuan Atasan', 'Verifikasi WhatsApp', $stats['menunggu_atasan'], 'secondary', 'hourglass-split', \App\Models\MaterialRequisition::STATUS_PENDING_APPROVAL],
        ['Menunggu Alokasi Logistik', 'Penentuan Batch Rak', $stats['menunggu_logistik'], 'warning', 'boxes', \App\Models\MaterialRequisition::STATUS_PENDING_LOGISTICS],
        ['Menunggu Picking Gudang', 'Pengambilan Fisik', $stats['menunggu_picking'], 'info', 'cart-check', \App\Models\MaterialRequisition::STATUS_PENDING_PICKING],
        ['Siap Serah Terima', 'Di Rak Serah Terima', $stats['siap_diambil'], 'primary', 'box-seam', \App\Models\MaterialRequisition::STATUS_READY_FOR_PICKUP],
    ] as [$judul, $subjudul, $angka, $warna, $ikon, $status])
    <div class="col-6 col-lg-3">
        <a href="{{ request()->fullUrlWithQuery(['status' => $status, 'page' => null]) }}"
           class="text-decoration-none text-reset">
            <div class="card border-0 shadow-sm rounded-4 h-100 mrf-stat-card {{ $filters['status'] === $status ? 'mrf-stat-active border border-2 border-'.$warna.' text-'.$warna : '' }}">
                <div class="card-body p-3 d-flex align-items-center gap-2.5">
                    <div class="rounded-3 bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;font-size:1.25rem">
                        <i class="bi bi-{{ $ikon }}"></i>
                    </div>
                    <div class="min-w-0 flex-grow-1">
                        <div class="fs-4 fw-bold text-dark lh-1 mb-1">{{ $angka }}</div>
                        <div class="fw-semibold text-truncate small" style="font-size:0.78rem;">{{ $judul }}</div>
                        <div class="text-muted text-truncate" style="font-size:0.68rem;">{{ $subjudul }}</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    @endforeach
</div>

{{-- Main Container Card --}}
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-3 p-md-4">
        {{-- Section Header & Quick Action --}}
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom">
            <div>
                <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-list-columns-reverse text-primary"></i> Daftar Permintaan Material (MRF)
                </h6>
                <span class="text-muted small">Kelola alur permohonan material produksi dan serah terima dari logistik</span>
            </div>
            @can(\App\Support\Permission::MRF_CREATE)
            <a href="{{ route('wms.mrf.create') }}" class="btn btn-primary btn-sm rounded-3 px-3 py-1.5 shadow-sm d-inline-flex align-items-center gap-1.5 fw-semibold">
                <i class="bi bi-plus-circle-fill"></i> Buat Permintaan
            </a>
            @endcan
        </div>

        {{-- Filters Form --}}
        <form method="GET" class="row g-2 mb-3 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0 text-muted rounded-start-3"><i class="bi bi-search"></i></span>
                    <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm border-start-0 rounded-end-3"
                           placeholder="Cari nomor MRF, keperluan, atasan...">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm rounded-3">
                    <option value="">Semua Status</option>
                    @foreach(\App\Models\MaterialRequisition::STATUS_LABELS as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($filters['status'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="jenis" class="form-select form-select-sm rounded-3">
                    <option value="">Semua Jenis</option>
                    @foreach(\App\Models\MaterialRequisition::TYPES as $nilai => $jenis)
                        <option value="{{ $nilai }}" @selected($filters['jenis'] === $nilai)>{{ $jenis['label'] }}</option>
                    @endforeach
                </select>
            </div>
            @if($gudangOptions->count() > 1)
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select form-select-sm rounded-3">
                    <option value="">Semua Gudang</option>
                    @foreach($gudangOptions as $g)
                        <option value="{{ $g->id }}" @selected($filters['warehouse_id'] == $g->id)>{{ $g->code }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-12 col-md-auto d-flex gap-2 ms-auto">
                <button class="btn btn-sm btn-primary rounded-3 px-3">
                    <i class="bi bi-funnel me-1"></i> Terapkan
                </button>
                <a href="{{ route('wms.mrf.index') }}" class="btn btn-sm btn-outline-secondary rounded-3">Reset</a>
            </div>
        </form>

        {{-- Table responsive --}}
        <div class="table-responsive rounded-3 border">
            <table class="table table-hover table-mrf align-middle mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Nomor MRF</th>
                        <th style="min-width: 190px;">Jenis &amp; Keperluan</th>
                        <th style="min-width: 150px;">Pemohon</th>
                        <th class="text-end" style="min-width: 110px;">Diminta</th>
                        <th style="min-width: 130px;">Status</th>
                        <th style="min-width: 220px;"><i class="bi bi-clock-history me-1 text-primary"></i> Menunggu Tindakan</th>
                        <th class="text-end" style="min-width: 90px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($halaman as $mrf)
                    <tr>
                        <td>
                            <span class="font-monospace fw-bold text-dark">{{ $mrf->mrf_number }}</span>
                            <div class="small text-muted" style="font-size: 0.72rem;">
                                <i class="bi bi-clock me-1"></i>{{ $mrf->created_at->format('d/m/Y H:i') }}
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border px-2 py-0.5 rounded-pill mb-1 fw-medium" style="font-size:0.7rem;">
                                {{ $mrf->jenis_label }}
                            </span>
                            <div class="small text-muted text-truncate" style="max-width:260px" title="{{ $mrf->purpose }}">
                                {{ $mrf->purpose }}
                            </div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark small">{{ $mrf->nama_pemohon }}</div>
                            <div class="small text-muted" style="font-size: 0.72rem;">
                                <i class="bi bi-building me-1"></i>{{ $mrf->department_name ?? $mrf->warehouse?->code ?? '-' }}
                            </div>
                        </td>
                        <td class="text-end">
                            <span class="fw-bold text-dark fs-6">{{ number_format($mrf->total_diminta) }}</span>
                            <div>
                                <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-0.5" style="font-size:0.68rem;">
                                    {{ $mrf->items_count }} SKU
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $mrf->status_badge }} rounded-pill px-2.5 py-1 fw-medium" style="font-size: 0.75rem;">
                                {{ $mrf->status_label }}
                            </span>
                        </td>
                        <td>
                            {{-- POSISI & TINDAKAN SELANJUTNYA: Sangat jelas siapa dan langkah apa yang sedang berjalan --}}
                            @switch($mrf->status)
                                @case(\App\Models\MaterialRequisition::STATUS_PENDING_APPROVAL)
                                    <div class="d-flex align-items-center gap-1.5 mb-0.5">
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                            <i class="bi bi-whatsapp me-1"></i>Persetujuan Atasan
                                        </span>
                                    </div>
                                    <div class="small text-muted text-truncate" style="max-width: 190px;" title="{{ $mrf->approver_name }}">
                                        <i class="bi bi-person me-1"></i>{{ $mrf->approver_name }}
                                    </div>
                                    @break

                                @case(\App\Models\MaterialRequisition::STATUS_PENDING_LOGISTICS)
                                    <div class="d-flex align-items-center gap-1.5 mb-0.5">
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                            <i class="bi bi-boxes me-1"></i>Alokasi Batch
                                        </span>
                                    </div>
                                    <div class="small text-muted" style="font-size: 0.72rem;">
                                        <i class="bi bi-building-gear me-1"></i>Tim Logistik Gudang
                                    </div>
                                    @break

                                @case(\App\Models\MaterialRequisition::STATUS_PENDING_PICKING)
                                    <div class="d-flex align-items-center gap-1.5 mb-0.5">
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                            <i class="bi bi-cart-check me-1"></i>Picking Rak
                                        </span>
                                    </div>
                                    <div class="small text-muted" style="font-size: 0.72rem;">
                                        <i class="bi bi-person-badge me-1"></i>Operator Gudang
                                    </div>
                                    @break

                                @case(\App\Models\MaterialRequisition::STATUS_READY_FOR_PICKUP)
                                    <div class="d-flex align-items-center gap-1.5 mb-0.5">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Serah Terima
                                        </span>
                                    </div>
                                    <div class="small fw-semibold text-primary text-truncate" style="max-width: 190px;" title="{{ $mrf->handoverLocation?->code ?? 'Rak Serah Terima' }}">
                                        <i class="bi bi-geo-alt me-1"></i>{{ $mrf->handoverLocation?->code ?? 'Rak Serah Terima' }}
                                    </div>
                                    @break

                                @case(\App\Models\MaterialRequisition::STATUS_RECEIVED)
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-check2-all me-1"></i>Tuntas Diterima
                                    </span>
                                    @break

                                @case(\App\Models\MaterialRequisition::STATUS_REJECTED_APPROVAL)
                                    <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-x-circle me-1"></i>Ditolak Atasan
                                    </span>
                                    @break

                                @case(\App\Models\MaterialRequisition::STATUS_REJECTED_LOGISTICS)
                                    <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-x-circle me-1"></i>Ditolak Logistik
                                    </span>
                                    @break

                                @case(\App\Models\MaterialRequisition::STATUS_CANCELLED)
                                    <span class="badge bg-light text-muted border rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                        <i class="bi bi-dash-circle me-1"></i>Dibatalkan
                                    </span>
                                    @break

                                @default
                                    <span class="text-muted">—</span>
                            @endswitch
                        </td>
                        <td class="text-end">
                            <a href="{{ route('wms.mrf.show', $mrf) }}" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-medium" style="font-size: 0.78rem;">
                                Detail <i class="bi bi-chevron-right ms-0.5"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <div class="py-3">
                                <i class="bi bi-clipboard2-x fs-1 d-block mb-2 opacity-25 text-primary"></i>
                                <div class="fw-semibold text-dark">Belum ada permintaan material</div>
                                <div class="small text-muted">Tidak ada dokumen MRF yang cocok dengan filter yang dipilih.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3 d-flex justify-content-end">{{ $halaman->links() }}</div>
    </div>
</div>
@endsection
