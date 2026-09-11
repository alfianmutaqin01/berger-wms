@extends('layouts.wms')

@section('title', 'Rincian Penerimaan '.$order->order_number)
@section('page_title', 'Rincian Penerimaan '.$order->order_number)

@section('content')
{{-- HANYA UNTUK DIBACA. Layar keputusan ada di wms.approval.show dan menolak
     pesanan yang sudah dinilai. Menaruh tombol keputusan di sini berarti satu
     pesanan bisa dinilai dua kali lewat dua pintu yang berbeda. --}}

<a href="{{ route('wms.approval.history') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke riwayat penerimaan
</a>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 border-bottom pb-3 mb-3">
            <div>
                <h5 class="fw-bold text-dark mb-1 font-monospace">{{ $order->order_number }}</h5>
                <div class="text-muted small">
                    {{ $order->customer?->name ?? '—' }}
                    @if($order->customer?->code)
                        <span class="font-monospace">({{ $order->customer->code }})</span>
                    @endif
                </div>
                <div class="text-muted small">
                    @if($order->bc_so_number)SO <span class="font-monospace">{{ $order->bc_so_number }}</span> &middot; @endif
                    @if($order->customer_po_number)PO customer <span class="font-monospace">{{ $order->customer_po_number }}</span> &middot; @endif
                    {{ $order->warehouse?->code ?? '—' }}
                </div>
            </div>

            <div class="text-md-end small text-muted">
                <div><strong>Diajukan:</strong> {{ $order->user?->full_name ?? '—' }}</div>
                @if($order->dibuatkanOrangLain())
                    {{-- Pesanan yang diketik Admin/Manager atas nama Sales.
                         Kalau tidak disebut di sini, riwayat penerimaannya
                         terbaca seolah Sales yang mengajukannya sendiri. --}}
                    <div class="text-warning-emphasis">
                        <i class="bi bi-pencil-square me-1"></i>dibuatkan {{ $order->placedBy?->full_name ?? '—' }}
                    </div>
                @endif
                <div><strong>Status sekarang:</strong> {{ $order->status_label }}</div>
                @if($order->approved_at)
                    <div><strong>Diterima:</strong> {{ $order->approved_at->translatedFormat('d M Y, H:i') }}
                        &middot; {{ $order->approvedBy?->full_name ?? '—' }}</div>
                @endif
                @if($order->rejected_at)
                    <div class="text-danger"><strong>Ditolak:</strong> {{ $order->rejected_at->translatedFormat('d M Y, H:i') }}
                        &middot; {{ $order->rejectedBy?->full_name ?? '—' }}</div>
                @endif
                @if($order->cancelled_at)
                    <div class="text-danger"><strong>Dibatalkan:</strong> {{ $order->cancelled_at->translatedFormat('d M Y, H:i') }}
                        &middot; {{ $order->cancelledBy?->full_name ?? '—' }}</div>
                @endif
            </div>
        </div>

        {{-- EMPAT ANGKA, dan bedanya penting. "Disetujui" adalah keputusan saat
             penerimaan; "terkirim" adalah yang benar-benar sampai. Menampilkan
             satu saja membuat pesanan yang disetujui penuh tetapi baru
             separuh berangkat terbaca sudah beres. --}}
        <div class="row g-3 mb-4">
            @foreach([
                ['Dipesan', $totals['dipesan'], 'dark'],
                ['Disetujui', $totals['disetujui'], 'primary'],
                ['Terkirim', $totals['terkirim'], 'success'],
                ['Masih kurang', $totals['outstanding'], $totals['outstanding'] > 0 ? 'danger' : 'muted'],
            ] as [$judul, $nilai, $warna])
                <div class="col-6 col-lg-3">
                    <div class="border rounded-3 p-3">
                        <div class="text-muted small">{{ $judul }}</div>
                        <div class="fs-5 fw-bold text-{{ $warna }}">{{ number_format($nilai) }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @foreach($order->rejections as $tolak)
            <div class="alert alert-danger border-0 rounded-3 small">
                <i class="bi bi-x-circle-fill me-1"></i>
                <strong>Ditolak pada pengajuan ke-{{ $tolak->attempt_no }}</strong>
                ({{ $tolak->rejected_at?->translatedFormat('d M Y, H:i') }} &middot;
                {{ $tolak->rejectedBy?->full_name ?? '—' }}): {{ $tolak->reason }}
            </div>
        @endforeach

        @foreach($order->cancellations as $batal)
            <div class="alert alert-warning border-0 rounded-3 small">
                <i class="bi bi-slash-circle me-1"></i>
                <strong>Dibatalkan</strong>
                ({{ $batal->cancelled_at?->translatedFormat('d M Y, H:i') }} &middot;
                {{ $batal->cancelledBy?->full_name ?? '—' }}): {{ $batal->reason }}
            </div>
        @endforeach

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 border">
                <thead class="table-light">
                    <tr class="small text-secondary">
                        <th>SKU</th>
                        <th style="min-width:220px">Produk</th>
                        <th class="text-end">Dipesan</th>
                        <th class="text-end">Disetujui</th>
                        <th class="text-end">Terkirim</th>
                        <th class="text-end">Masih kurang</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($order->details as $d)
                    @php($kurang = (int) $d->outstanding_qty)
                    <tr class="{{ $kurang > 0 ? 'table-warning' : '' }}">
                        <td class="font-monospace small">{{ $d->product?->sku ?? '—' }}</td>
                        <td class="small">{{ $d->product?->name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($d->qty_ordered) }} {{ $d->product?->uom }}</td>
                        <td class="text-end fw-semibold">{{ number_format($d->qty_approved) }}</td>
                        <td class="text-end fw-semibold text-success">{{ number_format($d->qty_shipped) }}</td>
                        <td class="text-end fw-bold {{ $kurang > 0 ? 'text-danger' : 'text-muted' }}">
                            {{ number_format($kurang) }}
                        </td>
                        <td class="small text-muted">
                            {{-- Dipotong saat penerimaan adalah keterangan yang
                                 paling sering dicari di layar ini: itulah yang
                                 harus dijelaskan Logistik ke Sales. --}}
                            @if($d->qty_approved < $d->qty_ordered)
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">
                                    Dipotong {{ number_format($d->qty_ordered - $d->qty_approved) }} saat penerimaan
                                </span>
                            @endif
                            @if($d->substitution_note)
                                <div class="mt-1">{{ $d->substitution_note }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-4 text-muted">Pesanan ini tidak punya baris item.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
