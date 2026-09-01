@extends('layouts.wms')

@section('title', 'Daftar Verifikasi Logistik')
@section('page_title', 'Daftar Verifikasi Logistik')

@section('content')
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

<!-- Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Dokumen Menunggu</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ number_format($stats['dokumen']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-secondary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Total Palet</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ number_format($stats['palet']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-info border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Belum Diverifikasi</h6>
                <h3 class="mb-0 fw-bold text-info">{{ number_format($stats['belum']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        {{-- Palet berselisih ditonjolkan: di situlah Logistik memutuskan angka final stok. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Palet Berselisih</h6>
                <h3 class="mb-0 fw-bold text-danger">{{ number_format($stats['selisih']) }}</h3>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-shield-check text-primary me-2"></i> Daftar Menunggu Verifikasi</h5>
        <p class="text-muted small mt-1 mb-0">Pilih dokumen untuk melakukan pengecekan fisik akhir. Dokumen yang baru diverifikasi sebagian tetap tampil di sini.</p>
    </div>

    <div class="card-body p-4">
        <form method="GET" action="{{ route('wms.inbound.verify') }}" class="row g-2 mb-4 align-items-stretch">
            <div class="col-12 col-md-5">
                <div class="input-group h-100">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-white border-start-0" placeholder="No. dokumen, batch, no. produksi...">
                </div>
            </div>
            <div class="col-8 col-md-3">
                <select name="warehouse_id" class="form-select h-100">
                    <option value="">Semua Gudang</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($filters['warehouse_id'] == $warehouse->id)>{{ $warehouse->display_label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" title="Terapkan filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ route('wms.inbound.verify') }}" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-secondary small fw-semibold text-nowrap">NO. DOKUMEN</th>
                        <th class="text-secondary small fw-semibold text-nowrap">TGL PRODUKSI</th>
                        <th class="text-secondary small fw-semibold text-nowrap">GUDANG</th>
                        <th class="text-secondary small fw-semibold" style="min-width: 170px;">BATCH</th>
                        <th class="text-secondary small fw-semibold" style="min-width: 160px;">KEMAJUAN</th>
                        <th class="text-secondary small fw-semibold text-nowrap">STATUS</th>
                        <th class="text-secondary small fw-semibold text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $doc)
                        @php
                            $batches = $doc->details->pluck('batch_no')->unique()->values();
                            $total = $doc->details_count;
                            $sudah = $doc->details_verified_count;
                            $persen = $total > 0 ? (int) round($sudah / $total * 100) : 0;
                        @endphp
                        <tr>
                            <td class="fw-bold text-primary font-monospace text-nowrap">{{ $doc->document_number }}</td>
                            <td class="small text-muted text-nowrap">{{ $doc->production_date->translatedFormat('d M Y') }}</td>
                            <td class="small text-muted text-nowrap">{{ $doc->warehouse?->code ?? '—' }}</td>
                            <td>
                                @forelse($batches->take(2) as $batch)
                                    <span class="badge bg-light text-dark border font-monospace me-1">{{ $batch }}</span>
                                @empty
                                    <span class="text-muted small">—</span>
                                @endforelse
                                @if($batches->count() > 2)
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary"
                                          title="{{ $batches->implode(', ') }}">+{{ $batches->count() - 2 }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px; min-width: 70px;">
                                        <div class="progress-bar {{ $sudah > 0 ? 'bg-info' : 'bg-secondary' }}" style="width: {{ $persen }}%"></div>
                                    </div>
                                    <small class="text-muted text-nowrap fw-semibold">{{ $sudah }}/{{ $total }} palet</small>
                                </div>
                            </td>
                            <td class="text-nowrap">
                                @php
                                    $warna = $doc->status === \App\Models\InboundHeader::STATUS_PARTIAL_VERIFIED ? 'warning' : 'info';
                                @endphp
                                <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis border border-{{ $warna }}">
                                    <i class="bi bi-shield-check me-1"></i>{{ $doc->status_label }}
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="{{ route('wms.inbound.verify.process', $doc->document_number) }}"
                                   class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm text-nowrap">
                                    {{ $sudah > 0 ? 'Lanjutkan' : 'Mulai Verifikasi' }} <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-check2-circle fs-1 d-block mb-3 opacity-50"></i>
                                Tidak ada dokumen yang menunggu verifikasi.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($documents->hasPages())
            <div class="mt-4">{{ $documents->links() }}</div>
        @endif
    </div>
</div>
@endsection
