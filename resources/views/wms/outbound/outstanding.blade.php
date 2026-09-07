@extends('layouts.wms')

@section('title', 'Riwayat Outstanding')
@section('page_title', 'Riwayat Outstanding')

@section('content')
{{-- Permintaan pemilik produk: setiap kekurangan yang pernah terjadi punya
     barisnya sendiri di sini — pesan 10 disetujui 5, maka 5 yang tidak
     terpenuhi itu tercatat dan tidak hilang begitu kekurangannya tertutup.

     KOLOM "SISA SEKARANG" SENGAJA BUKAN SALINAN. Angka di kolom Outstanding
     adalah cuplikan saat peristiwanya terjadi; sisa sekarang dibaca hidup dari
     baris pesanannya. Baris yang sudah terpenuhi tetap berdiri di sini,
     ditandai hijau — riwayat yang menghilang begitu masalahnya beres tidak
     bisa dipakai menjawab "dulu kurang berapa". --}}

<div class="row g-3 mb-3">
    @php($kartu = [
        ['Baris masih kurang', $stats['baris_berjalan'], 'warning', 'list-ul'],
        ['Total unit kurang', $stats['qty_berjalan'], 'danger', 'box-seam'],
        ['Pesanan terdampak', $stats['pesanan_berjalan'], 'primary', 'receipt'],
    ])
    @foreach($kartu as [$judul, $nilai, $warna, $ikon])
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis d-flex align-items-center justify-content-center"
                         style="width:44px;height:44px">
                        <i class="bi bi-{{ $ikon }} fs-5"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-dark lh-1">{{ number_format($nilai) }}</div>
                        <small class="text-muted">{{ $judul }}</small>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-exclamation-diamond text-warning me-2"></i> Kekurangan yang Pernah Terjadi
        </h5>
        <small class="text-muted">Terbaru di atas. Baris hijau berarti kekurangannya sudah tertutup.</small>
    </div>

    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-lg-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                           placeholder="Cari nomor PO, nomor SO, customer, atau SKU...">
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <select name="keadaan" class="form-select">
                    <option value="">Semua keadaan</option>
                    <option value="berjalan" @selected($filters['keadaan'] === 'berjalan')>Masih kurang</option>
                    <option value="selesai" @selected($filters['keadaan'] === 'selesai')>Sudah terpenuhi</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <select name="sebab" class="form-select">
                    <option value="">Semua sebab</option>
                    @foreach(\App\Models\SalesOrderOutstanding::CAUSE_LABELS as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['sebab'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($warehouses->count() > 1)
                <div class="col-6 col-lg-2">
                    <select name="warehouse" class="form-select">
                        <option value="">Semua gudang</option>
                        @foreach($warehouses as $w)
                            <option value="{{ $w->id }}" @selected($filters['warehouse'] === $w->id)>{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-6 col-lg-2 d-grid">
                <button class="btn btn-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PO</th>
                        <th>Customer</th>
                        <th>Produk</th>
                        <th class="text-end">Pesan</th>
                        <th class="text-end">Terpenuhi</th>
                        <th class="text-end">Kurang</th>
                        <th>Sebab</th>
                        <th>Sisa sekarang</th>
                        <th>Dicatat</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($baris as $b)
                    <tr class="{{ $b->sudah_tertutup ? 'table-success' : '' }}">
                        <td>
                            <span class="fw-semibold font-monospace">{{ $b->salesOrder?->order_number ?? '—' }}</span>
                            @if($b->salesOrder?->customer_po_number)
                                <div class="small text-muted">PO customer: {{ $b->salesOrder->customer_po_number }}</div>
                            @endif
                            @if($b->salesOrder?->bc_so_number)
                                <div class="small text-muted font-monospace">SO {{ $b->salesOrder->bc_so_number }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $b->salesOrder?->customer?->name ?? '—' }}</div>
                            <small class="text-muted">{{ $b->warehouse?->name }}</small>
                        </td>
                        <td>
                            <div class="fw-semibold font-monospace small">{{ $b->product?->sku ?? '—' }}</div>
                            <small class="text-muted">{{ $b->product?->name }}</small>
                        </td>
                        <td class="text-end">{{ number_format($b->qty_ordered) }}</td>
                        <td class="text-end">{{ number_format($b->qty_fulfilled) }}</td>
                        <td class="text-end fw-bold text-danger">{{ number_format($b->qty_outstanding) }}</td>
                        <td>
                            <span class="badge bg-{{ $b->cause === \App\Models\SalesOrderOutstanding::CAUSE_APPROVAL ? 'warning' : 'info' }}-subtle
                                         text-{{ $b->cause === \App\Models\SalesOrderOutstanding::CAUSE_APPROVAL ? 'warning' : 'info' }}-emphasis">
                                {{ $b->cause_label }}
                            </span>
                            @if($b->note)
                                <div class="small text-muted mt-1">{{ $b->note }}</div>
                            @endif
                        </td>
                        <td>
                            {{-- Tiga keadaan yang BERBEDA, sengaja tidak
                                 diringkas jadi angka saja: barisnya dicabut
                                 dari pesanan bukan hal yang sama dengan
                                 kewajiban yang sudah dipenuhi. --}}
                            @if($b->sisa_sekarang === null)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Baris dicabut</span>
                            @elseif($b->sisa_sekarang > 0)
                                <span class="badge bg-danger-subtle text-danger-emphasis">
                                    Masih kurang {{ number_format($b->sisa_sekarang) }}
                                </span>
                                <div class="small text-muted mt-1">{{ $b->salesOrder?->status_label }}</div>
                            @else
                                <span class="badge bg-success-subtle text-success-emphasis">
                                    <i class="bi bi-check-circle me-1"></i>Sudah terpenuhi
                                </span>
                            @endif
                        </td>
                        <td>
                            <div>{{ $b->created_at?->format('d M Y') }}</div>
                            <small class="text-muted">{{ $b->recordedBy?->full_name ?? 'Sistem' }}</small>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-check2-circle display-6 d-block mb-2 opacity-50"></i>
                            Belum ada kekurangan yang tercatat.
                            <div class="small">Setiap pesanan yang disetujui atau dikirim kurang dari yang diminta akan muncul di sini.</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $baris->links() }}</div>
    </div>
</div>
@endsection
