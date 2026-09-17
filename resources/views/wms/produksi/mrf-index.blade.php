@extends('layouts.wms')

@section('title', 'Permintaan Material (MRF)')
@section('page_title', 'Permintaan Material Produksi')

@section('content')
{{-- DAFTAR MRF — dibaca empat peran dengan kepentingan yang berbeda:
     Produksi memantau permintaannya, Logistik mencari yang menunggu
     keputusannya, Operator memastikan tugas yang ia pegang milik siapa, dan
     Manager melihat keseluruhannya. Karena itu kolomnya menyebut STATUS dan
     SIAPA YANG DITUNGGU, bukan cuma tanggal. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

<div class="row g-3 mb-3">
    @foreach([
        ['Menunggu Atasan', $stats['menunggu_atasan'], 'secondary', 'hourglass-split', \App\Models\MaterialRequisition::STATUS_PENDING_APPROVAL],
        ['Menunggu Logistik', $stats['menunggu_logistik'], 'warning', 'clipboard2-plus', \App\Models\MaterialRequisition::STATUS_PENDING_LOGISTICS],
        ['Menunggu Picking', $stats['menunggu_picking'], 'info', 'list-check', \App\Models\MaterialRequisition::STATUS_PENDING_PICKING],
        ['Siap Diambil', $stats['siap_diambil'], 'primary', 'box-seam', \App\Models\MaterialRequisition::STATUS_READY_FOR_PICKUP],
    ] as [$judul, $angka, $warna, $ikon, $status])
    <div class="col-6 col-lg-3">
        <a href="{{ request()->fullUrlWithQuery(['status' => $status, 'page' => null]) }}"
           class="text-decoration-none">
            <div class="card border-0 shadow-sm rounded-4 h-100 {{ $filters['status'] === $status ? 'border border-2 border-'.$warna : '' }}">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis d-flex align-items-center justify-content-center"
                         style="width:44px;height:44px">
                        <i class="bi bi-{{ $ikon }} fs-5"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-body">{{ $angka }}</div>
                        <small class="text-muted">{{ $judul }}</small>
                    </div>
                </div>
            </div>
        </a>
    </div>
    @endforeach
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h6 class="fw-bold mb-0">Daftar Permintaan</h6>
            @can(\App\Support\Permission::MRF_CREATE)
            <a href="{{ route('wms.mrf.create') }}" class="btn btn-primary btn-sm rounded-3">
                <i class="bi bi-plus-lg me-1"></i> Buat Permintaan
            </a>
            @endcan
        </div>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-4">
                <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm rounded-3"
                       placeholder="Cari nomor MRF, keperluan, atau nama atasan…">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm rounded-3">
                    <option value="">Semua status</option>
                    @foreach(\App\Models\MaterialRequisition::STATUS_LABELS as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($filters['status'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="jenis" class="form-select form-select-sm rounded-3">
                    <option value="">Semua jenis</option>
                    @foreach(\App\Models\MaterialRequisition::TYPES as $nilai => $jenis)
                        <option value="{{ $nilai }}" @selected($filters['jenis'] === $nilai)>{{ $jenis['label'] }}</option>
                    @endforeach
                </select>
            </div>
            @if($gudangOptions->count() > 1)
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select form-select-sm rounded-3">
                    <option value="">Semua gudang</option>
                    @foreach($gudangOptions as $g)
                        <option value="{{ $g->id }}" @selected($filters['warehouse_id'] == $g->id)>{{ $g->code }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-12 col-md-auto d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary rounded-3">Terapkan</button>
                <a href="{{ route('wms.mrf.index') }}" class="btn btn-sm btn-link text-decoration-none">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Jenis &amp; Keperluan</th>
                        <th>Pemohon</th>
                        <th class="text-end">Diminta</th>
                        <th>Status</th>
                        <th>Yang Ditunggu</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($halaman as $mrf)
                    <tr>
                        <td>
                            <span class="font-monospace fw-semibold">{{ $mrf->mrf_number }}</span>
                            <div class="small text-muted">{{ $mrf->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $mrf->jenis_label }}</div>
                            <div class="small text-muted text-truncate" style="max-width:280px">{{ $mrf->purpose }}</div>
                        </td>
                        <td>
                            {{ $mrf->nama_pemohon }}
                            <div class="small text-muted">{{ $mrf->department_name ?? $mrf->warehouse?->code }}</div>
                        </td>
                        <td class="text-end">
                            <span class="fw-semibold">{{ number_format($mrf->total_diminta) }}</span>
                            <div class="small text-muted">{{ $mrf->items_count }} SKU</div>
                        </td>
                        <td><span class="badge {{ $mrf->status_badge }}">{{ $mrf->status_label }}</span></td>
                        <td class="small text-muted">
                            {{-- SIAPA yang ditunggu, bukan sekadar status. Inilah kolom yang
                                 membuat orang tahu apakah bolanya ada di tangannya sendiri. --}}
                            @switch($mrf->status)
                                @case(\App\Models\MaterialRequisition::STATUS_PENDING_APPROVAL)
                                    {{ $mrf->approver_name }} (WhatsApp) @break
                                @case(\App\Models\MaterialRequisition::STATUS_PENDING_LOGISTICS)
                                    Logistik memilih batch @break
                                @case(\App\Models\MaterialRequisition::STATUS_PENDING_PICKING)
                                    Operator mengambil dari rak @break
                                @case(\App\Models\MaterialRequisition::STATUS_READY_FOR_PICKUP)
                                    <span class="text-primary fw-semibold">
                                        Produksi, di {{ $mrf->handoverLocation?->code ?? 'rak serah terima' }}
                                    </span> @break
                                @default —
                            @endswitch
                        </td>
                        <td class="text-end">
                            <a href="{{ route('wms.mrf.show', $mrf) }}" class="btn btn-sm btn-outline-secondary rounded-3">
                                Detail
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-clipboard2 fs-1 d-block mb-2 opacity-25"></i>
                            Belum ada permintaan material yang cocok dengan penyaring ini.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $halaman->links() }}</div>
    </div>
</div>
@endsection
