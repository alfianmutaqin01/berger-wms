@extends('layouts.wms')

@section('title', 'Transfer Antar Gudang')
@section('page_title', 'Transfer Antar Gudang')

@section('content')
{{-- PRD F-INV-05. Satu dokumen dibaca DUA gudang: yang mengirim dan yang
     menerima. Kolom Arah di bawah dihitung dari sudut pandang gudang pembaca,
     jadi kiriman yang sama tampil "Keluar" di Karawang dan "Masuk" di
     Pekanbaru. Bagi Super Admin yang tidak terikat gudang, kolom itu tidak
     punya makna dan diganti nama kedua gudangnya. --}}
@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

<div class="row g-3 mb-4">
    @if($gudangSaya)
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Menuju Gudang Anda</h6>
                    <h3 class="mb-0 fw-bold text-warning">{{ number_format($stats['masuk']) }}</h3>
                    <small class="text-muted">menunggu diterima</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-info border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Dikirim Gudang Anda</h6>
                    <h3 class="mb-0 fw-bold text-info">{{ number_format($stats['keluar']) }}</h3>
                    <small class="text-muted">masih di perjalanan</small>
                </div>
            </div>
        </div>
    @else
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">Dalam Perjalanan</h6>
                    <h3 class="mb-0 fw-bold text-warning">{{ number_format($stats['menunggu']) }}</h3>
                    <small class="text-muted">seluruh gudang</small>
                </div>
            </div>
        </div>
    @endif

    <div class="col-12 col-md-6 d-flex align-items-stretch">
        <div class="card border-0 shadow-sm rounded-4 h-100 w-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="text-muted fw-normal mb-1">Kirim Stok</h6>
                    <small class="text-muted">
                        {{ $gudangSaya ? 'Dari gudang '.$gudangSaya->name.' ke gudang lain' : 'Pilih gudang asal lebih dulu' }}
                    </small>
                </div>
                @can(\App\Support\Permission::TRANSFER_SEND)
                    <a href="{{ route('wms.transfers.create') }}" class="btn btn-primary rounded-3">
                        <i class="bi bi-truck me-1"></i> Buat Transfer
                    </a>
                @endcan
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-arrow-left-right text-primary me-2"></i> Daftar Transfer
        </h5>
        <small class="text-muted">Yang masih di perjalanan selalu di atas — hanya itu yang menunggu tindakan.</small>
    </div>

    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-4">
                <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control"
                       placeholder="Cari nomor transfer...">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select">
                    <option value="">Semua status</option>
                    @foreach($statuses as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['status'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($gudangSaya)
                <div class="col-6 col-md-3">
                    <select name="arah" class="form-select">
                        <option value="">Masuk & keluar</option>
                        <option value="masuk" @selected($filters['arah'] === 'masuk')>Masuk ke gudang saya</option>
                        <option value="keluar" @selected($filters['arah'] === 'keluar')>Keluar dari gudang saya</option>
                    </select>
                </div>
            @endif
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Rute</th>
                        <th class="text-center">Batch</th>
                        <th class="text-center">Status</th>
                        <th>Dikirim</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($transfers as $t)
                    <tr>
                        <td class="font-monospace fw-semibold">{{ $t->transfer_number }}</td>
                        <td>
                            {{ $t->fromWarehouse?->name }}
                            <i class="bi bi-arrow-right mx-1 text-muted"></i>
                            {{ $t->toWarehouse?->name }}
                            @if($gudangSaya)
                                @if($t->to_warehouse_id === $gudangSaya->id)
                                    <span class="badge bg-success-subtle text-success-emphasis ms-1">Masuk</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Keluar</span>
                                @endif
                            @endif
                        </td>
                        <td class="text-center">{{ $t->details_count }}</td>
                        <td class="text-center">
                            <span class="badge {{ $t->status_badge }}">{{ $t->status_label }}</span>
                        </td>
                        <td>
                            <div class="small">{{ $t->shipped_at?->format('d M Y H:i') ?? '—' }}</div>
                            <div class="small text-muted">{{ $t->shippedBy?->full_name }}</div>
                        </td>
                        <td class="text-end">
                            @if($t->isInTransit() && $gudangSaya && $t->to_warehouse_id === $gudangSaya->id)
                                @can(\App\Support\Permission::TRANSFER_RECEIVE)
                                    <a href="{{ route('wms.transfers.receive.form', $t) }}" class="btn btn-sm btn-success rounded-3">
                                        <i class="bi bi-box-arrow-in-down me-1"></i> Terima
                                    </a>
                                @endcan
                            @endif
                            <a href="{{ route('wms.transfers.show', $t) }}" class="btn btn-sm btn-outline-secondary rounded-3">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                            Belum ada transfer yang menyangkut gudang Anda.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $transfers->links() }}</div>
    </div>
</div>
@endsection
