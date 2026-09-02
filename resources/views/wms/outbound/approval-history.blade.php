@extends('layouts.wms')

@section('title', 'Riwayat Penerimaan Pesanan')
@section('page_title', 'Riwayat Penerimaan Pesanan')

@section('content')
{{-- Permintaan pemilik produk: seluruh penerimaan DAN penolakan tercatat di
     satu halaman, supaya keputusan Logistik bisa ditelusuri belakangan. --}}
<a href="{{ route('wms.approval.index') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke antrean
</a>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-clock-history text-primary me-2"></i> Riwayat Keputusan
        </h5>
        <small class="text-muted">Terbaru di atas.</small>
    </div>

    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-7">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                           placeholder="Cari nomor PO, nomor SO, atau customer...">
                </div>
            </div>
            <div class="col-8 col-md-3">
                <select name="hasil" class="form-select">
                    <option value="">Semua keputusan</option>
                    <option value="diterima" @selected($filters['hasil'] === 'diterima')>Diterima</option>
                    <option value="ditolak" @selected($filters['hasil'] === 'ditolak')>Ditolak</option>
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
                        <th>No. SO (BC)</th>
                        <th>Customer</th>
                        <th>Gudang</th>
                        <th>Keputusan</th>
                        <th>Oleh</th>
                        <th>Waktu</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($orders as $order)
                    @php($ditolak = $order->rejected_at !== null)
                    <tr>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $order->order_number }}</span>
                            @if($order->customer_po_number)
                                <div class="small text-muted">PO customer: {{ $order->customer_po_number }}</div>
                            @endif
                        </td>
                        <td class="font-monospace">{{ $order->bc_so_number ?? '—' }}</td>
                        <td>
                            <div class="fw-semibold">{{ $order->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $order->customer?->code }}</small>
                        </td>
                        <td>{{ $order->warehouse?->name ?? '—' }}</td>
                        <td>
                            @if($ditolak)
                                <span class="badge bg-danger-subtle text-danger-emphasis">Ditolak</span>
                                @if($order->rejection_reason)
                                    <div class="small text-muted mt-1">{{ $order->rejection_reason }}</div>
                                @endif
                            @else
                                <span class="badge bg-success-subtle text-success-emphasis">Diterima</span>
                                <div class="small text-muted mt-1">{{ $order->details_count }} item</div>
                                @if($order->approval_note)
                                    <div class="small text-muted">{{ $order->approval_note }}</div>
                                @endif
                            @endif
                        </td>
                        <td>{{ $ditolak ? ($order->rejectedBy?->full_name ?? '—') : ($order->approvedBy?->full_name ?? '—') }}</td>
                        <td>
                            @php($waktu = $ditolak ? $order->rejected_at : $order->approved_at)
                            <div>{{ $waktu?->format('d M Y') ?? '—' }}</div>
                            <small class="text-muted">{{ $waktu?->format('H:i') }}</small>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clock-history display-6 d-block mb-2 opacity-50"></i>
                            Belum ada pesanan yang diterima maupun ditolak.
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
