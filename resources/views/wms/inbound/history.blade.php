@extends('layouts.wms')

@section('title', 'Riwayat Produksi')
@section('page_title', 'Riwayat Produksi')

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
                <h6 class="text-muted fw-normal mb-2">Total Dokumen</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ number_format($stats['total']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Menunggu Put-away</h6>
                <h3 class="mb-0 fw-bold text-warning">{{ number_format($stats['putaway']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-info border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Menunggu Verifikasi</h6>
                <h3 class="mb-0 fw-bold text-info">{{ number_format($stats['verifikasi']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Selesai</h6>
                <h3 class="mb-0 fw-bold text-success">{{ number_format($stats['selesai']) }}</h3>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-clock-history text-primary me-2"></i> Riwayat Input Produksi</h5>
            <p class="text-muted small mt-1 mb-0">Pantau status setiap dokumen produksi yang sudah diserahkan ke gudang.</p>
        </div>
        <a href="{{ route('wms.inbound.create') }}" class="btn btn-primary fw-bold shadow-sm">
            <i class="bi bi-plus-circle me-1"></i> Input Produksi
        </a>
    </div>

    <div class="card-body p-4">
        <!-- Filter: submit via GET agar hasil filter bisa di-bookmark & di-share -->
        <form method="GET" action="{{ route('wms.inbound.history') }}" class="row g-2 mb-4 align-items-stretch">
            <div class="col-12 col-md-3">
                <div class="input-group h-100">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-white border-start-0" placeholder="No. dokumen, batch, no. produksi...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select h-100">
                    <option value="">Semua Status</option>
                    @foreach($statuses as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['status'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select h-100">
                    <option value="">Semua Gudang</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($filters['warehouse_id'] == $warehouse->id)>{{ $warehouse->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control h-100" title="Tanggal produksi dari">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control h-100" title="Tanggal produksi sampai">
            </div>
            <div class="col-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" title="Terapkan filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ route('wms.inbound.history') }}" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-secondary small fw-semibold text-nowrap">TGL PRODUKSI</th>
                        <th class="text-secondary small fw-semibold text-nowrap">NO. DOKUMEN</th>
                        <th class="text-secondary small fw-semibold text-nowrap">GUDANG</th>
                        <th class="text-secondary small fw-semibold" style="min-width: 180px;">BATCH</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">JML PALET</th>
                        <th class="text-secondary small fw-semibold text-nowrap">DIBUAT OLEH</th>
                        <th class="text-secondary small fw-semibold text-nowrap">STATUS</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $doc)
                        @php
                            // Satu dokumen bisa memuat beberapa batch karena satu
                            // berkas produksi berisi banyak baris.
                            $batches = $doc->details->pluck('batch_no')->unique()->values();
                        @endphp
                        <tr>
                            <td class="text-nowrap">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary-subtle text-primary rounded px-2 py-1 me-2 text-center" style="min-width: 42px;">
                                        <small class="d-block fw-bold" style="line-height: 1;">{{ $doc->production_date->format('d') }}</small>
                                        <small class="d-block text-uppercase" style="font-size: 0.65rem;">{{ $doc->production_date->translatedFormat('M') }}</small>
                                    </div>
                                    <span class="small text-muted">{{ $doc->production_date->format('Y') }}</span>
                                </div>
                            </td>
                            <td class="fw-bold text-primary font-monospace">{{ $doc->document_number }}</td>
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
                            <td class="text-center fw-semibold">{{ number_format($doc->details_count) }}</td>
                            <td class="small text-muted text-nowrap">{{ $doc->creator?->full_name ?? '—' }}</td>
                            <td class="text-nowrap">
                                @php
                                    $warna = match($doc->status) {
                                        \App\Models\InboundHeader::STATUS_VERIFIED => 'success',
                                        \App\Models\InboundHeader::STATUS_PUTAWAY_PENDING => 'warning',
                                        \App\Models\InboundHeader::STATUS_PARTIAL_VERIFIED => 'info',
                                        default => 'info',
                                    };
                                    $ikon = match($doc->status) {
                                        \App\Models\InboundHeader::STATUS_VERIFIED => 'bi-check-circle',
                                        \App\Models\InboundHeader::STATUS_PUTAWAY_PENDING => 'bi-hourglass-split',
                                        default => 'bi-shield-check',
                                    };
                                @endphp
                                <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis border border-{{ $warna }}">
                                    <i class="bi {{ $ikon }} me-1"></i>{{ $doc->status_label }}
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="/wms/inbound/history/{{ $doc->document_number }}" class="btn btn-sm btn-outline-primary fw-semibold text-nowrap">
                                    <i class="bi bi-eye me-1"></i> Detail
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                                Belum ada riwayat produksi yang cocok dengan filter ini.
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
