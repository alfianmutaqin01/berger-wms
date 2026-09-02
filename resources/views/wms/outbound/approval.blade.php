@extends('layouts.wms')

@section('title', 'Pesanan Masuk')
@section('page_title', 'Pesanan Masuk')

@section('content')
{{-- docs/4 §4.3.3 — antrean pesanan yang menunggu diterima Logistik. --}}
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('warning'))
{{-- Pesanan diterima TAPI sebagian menunggu stok. Bukan hijau (ada yang
     belum beres) dan bukan merah (pesanannya sendiri berhasil). --}}
<div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-circle-fill me-2"></i>{{ session('warning') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Menunggu Diterima</h6>
                <h3 class="mb-0 fw-bold text-warning">{{ number_format($stats['menunggu']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-info border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Perlu Input Rincian</h6>
                <h3 class="mb-0 fw-bold text-info">{{ number_format($stats['dokumen']) }}</h3>
                <small class="text-muted">pesanan lewat dokumen</small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 d-flex align-items-stretch">
        <div class="card border-0 shadow-sm rounded-4 h-100 w-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-muted fw-normal mb-1">Riwayat Penerimaan</h6>
                    <small class="text-muted">Pesanan yang sudah diterima atau ditolak</small>
                </div>
                <a href="{{ route('wms.approval.history') }}" class="btn btn-outline-secondary rounded-3">
                    <i class="bi bi-clock-history me-1"></i> Buka Riwayat
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-inbox-fill text-primary me-2"></i> Antrean Pesanan
        </h5>
        <small class="text-muted">Diurutkan dari yang paling lama menunggu.</small>
    </div>

    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                           placeholder="Cari nomor PO, nomor SO, atau customer...">
                </div>
            </div>
            <div class="col-8 col-md-4">
                <select name="warehouse" class="form-select">
                    <option value="">Semua gudang</option>
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected((string) $filters['warehouse'] === (string) $w->id)>
                            {{ $w->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-md-2 d-grid">
                <button class="btn btn-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PO</th>
                        <th>Customer</th>
                        <th>Sales</th>
                        <th>Gudang</th>
                        <th class="text-center">Item</th>
                        <th>Disubmit</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $order->order_number }}</span>
                            @if($order->isDocumentBased())
                                {{-- Nomor PO milik customer, bukan nomor sistem. --}}
                                <div class="small text-muted">
                                    <i class="bi bi-paperclip"></i> PO customer: {{ $order->customer_po_number ?? '—' }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $order->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $order->customer?->code }}</small>
                        </td>
                        <td>{{ $order->user?->full_name ?? '—' }}</td>
                        <td>{{ $order->warehouse?->name ?? '—' }}</td>
                        <td class="text-center">
                            @if($order->isDocumentBased() && $order->details_count === 0)
                                <span class="badge bg-info-subtle text-info-emphasis">perlu diinput</span>
                            @else
                                {{ $order->details_count }} item
                            @endif
                        </td>
                        <td>
                            <div>{{ $order->submitted_at?->format('d M Y') ?? '—' }}</div>
                            <small class="text-muted">{{ $order->submitted_at?->format('H:i') }}</small>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('wms.approval.show', $order) }}" class="btn btn-sm btn-primary rounded-3">
                                <i class="bi bi-eye me-1"></i> Periksa
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                            Tidak ada pesanan yang menunggu diterima.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
