@extends('layouts.soms')

@section('title', 'Pesanan Saya')
@section('page_title', 'Pesanan Saya')

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

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <div>
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-receipt text-primary me-2"></i>Pesanan Saya</h5>
        <p class="text-muted small mb-0">Draft masih bisa diubah dan dihapus. Setelah dikirim, pesanan terkunci.</p>
    </div>
    <a href="{{ url('/sales/new-order') }}" class="btn btn-primary fw-bold">
        <i class="bi bi-plus-lg me-1"></i> Buat Pesanan
    </a>
</div>

<form method="GET" action="{{ url('/sales/my-orders') }}" class="row g-2 mb-4">
    <div class="col-12 col-md-6">
        <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                   placeholder="Nomor PO, nomor PO customer, nama customer...">
        </div>
    </div>
    <div class="col-8 col-md-4">
        <select name="status" class="form-select">
            <option value="">Semua Status</option>
            @foreach($statuses as $slug => $label)
                <option value="{{ $slug }}" @selected($filters['status'] === $slug)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-4 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i></button>
        <a href="{{ url('/sales/my-orders') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>
    </div>
</form>

@forelse($orders as $order)
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <a href="{{ url('/sales/orders/'.$order->id) }}" class="fw-bold font-monospace text-decoration-none">
                        {{ $order->order_number }}
                    </a>
                    @if($order->customer_po_number)
                        <span class="badge bg-light text-dark border ms-1 font-monospace" title="Nomor PO customer">
                            PO cust: {{ $order->customer_po_number }}
                        </span>
                    @endif
                    <div class="text-dark">{{ $order->customer?->name ?? '—' }}</div>
                </div>
                <span class="badge bg-{{ $order->status_color }}">{{ $order->status_label }}</span>
            </div>

            <div class="d-flex flex-wrap gap-3 small text-muted mb-3">
                <span><i class="bi bi-building me-1"></i>{{ $order->warehouse?->code }}</span>
                <span><i class="bi bi-credit-card me-1"></i>{{ $order->paymentTerm?->name }}</span>
                <span>
                    <i class="bi bi-box-seam me-1"></i>
                    @if($order->isDocumentBased() && $order->details_count === 0)
                        Rincian menyusul dari Logistik
                    @else
                        {{ $order->details_count }} item
                    @endif
                </span>
                <span>
                    <i class="bi bi-clock me-1"></i>
                    {{ ($order->submitted_at ?? $order->created_at)->translatedFormat('d M Y, H:i') }}
                </span>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ url('/sales/orders/'.$order->id) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye me-1"></i>Detail
                </a>

                {{-- Ubah, hapus, dan kirim HANYA muncul untuk draft. Tombol
                     yang tampil lalu ditolak server adalah cacat UX; aturan
                     yang sama ditegakkan lagi di controller. --}}
                @if($order->isEditable())
                    <a href="{{ url('/sales/orders/'.$order->id.'/edit') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil me-1"></i>Ubah
                    </a>

                    <form method="POST" action="{{ url('/sales/orders/'.$order->id.'/submit') }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary" @disabled(! $cutoffOpen)
                                title="{{ $cutoffOpen ? 'Kirim ke Logistik' : 'Order ditutup pukul '.$cutoffLabel }}">
                            <i class="bi bi-send me-1"></i>Kirim
                        </button>
                    </form>

                    <form method="POST" action="{{ url('/sales/orders/'.$order->id) }}" class="d-inline"
                          onsubmit="return confirm('Hapus draft {{ $order->order_number }}? Tindakan ini tidak bisa dibatalkan.');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash me-1"></i>Hapus
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@empty
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-receipt fs-1 d-block mb-3 opacity-50"></i>
            Belum ada pesanan yang cocok dengan filter ini.
            <div class="mt-3">
                <a href="{{ url('/sales/new-order') }}" class="btn btn-primary">Buat Pesanan Pertama</a>
            </div>
        </div>
    </div>
@endforelse

@if($orders->hasPages())
    <div class="mt-4">{{ $orders->links() }}</div>
@endif

@unless($cutoffOpen)
<p class="text-center text-muted small mt-3">
    <i class="bi bi-clock-history me-1"></i>
    Order ditutup pukul {{ $cutoffLabel }}. Draft tetap bisa disimpan dan dikirim besok.
</p>
@endunless
@endsection
