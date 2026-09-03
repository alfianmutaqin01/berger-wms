@extends('layouts.wms')

@section('title', 'Proses Picking')
@section('page_title', 'Tugas Pengambilan Barang')

@section('content')
{{-- LAYAR OPERATOR. Dipakai sambil berdiri di gudang, sering di layar kecil,
     kadang dengan sarung tangan — karena itu tombolnya besar dan tiap tugas
     satu kartu, bukan satu baris tabel yang harus digulir ke samping. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@if($milikSaya)
<div class="alert alert-primary border-0 shadow-sm rounded-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <i class="bi bi-person-walking me-2"></i>
        Anda sedang mengerjakan <strong class="font-monospace">{{ $milikSaya->list_number }}</strong>.
    </div>
    <a href="{{ route('wms.picking.show', $milikSaya) }}" class="btn btn-sm btn-primary rounded-3">
        Lanjutkan <i class="bi bi-arrow-right ms-1"></i>
    </a>
</div>
@endif

<div class="row g-3">
@forelse($tugas as $daftar)
    @php($dipegangSaya = $daftar->claimed_by === auth()->id())
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 rounded-4 h-100 {{ $dipegangSaya ? 'border border-2 border-primary' : '' }}">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <h5 class="fw-bold font-monospace mb-0">{{ $daftar->list_number }}</h5>
                    <span class="badge bg-{{ $daftar->status_color }}-subtle text-{{ $daftar->status_color }}-emphasis">
                        {{ $daftar->status_label }}
                    </span>
                </div>

                <div class="d-flex gap-2 flex-wrap mb-3">
                    <span class="badge bg-light text-dark border">
                        <i class="bi bi-receipt text-primary me-1"></i> {{ $daftar->orders_count }} pesanan
                    </span>
                    <span class="badge bg-light text-dark border">
                        <i class="bi bi-geo-alt text-primary me-1"></i> {{ $daftar->items_count }} baris ambil
                    </span>
                </div>

                @if($daftar->notes)
                    <p class="small text-muted mb-3"><i class="bi bi-sticky me-1"></i>{{ $daftar->notes }}</p>
                @endif

                <div class="small text-muted mb-3">
                    Disusun {{ $daftar->created_at?->format('d M Y, H:i') }}
                    @if($daftar->claimed_by)
                        <div>
                            <i class="bi bi-person-badge me-1"></i>
                            {{ $dipegangSaya ? 'Dipegang Anda' : 'Dipegang '.($daftar->claimedBy?->full_name ?? 'operator lain') }}
                        </div>
                    @endif
                </div>

                <div class="mt-auto d-grid gap-2">
                    @if($daftar->status === \App\Models\PickingList::STATUS_OPEN)
                        <form method="POST" action="{{ route('wms.picking.claim', $daftar) }}" class="d-grid">
                            @csrf
                            <button class="btn btn-primary btn-lg rounded-3">
                                <i class="bi bi-hand-index-thumb me-1"></i> Ambil Tugas
                            </button>
                        </form>
                    @elseif($dipegangSaya)
                        <a href="{{ route('wms.picking.show', $daftar) }}" class="btn btn-primary btn-lg rounded-3">
                            <i class="bi bi-arrow-right-circle me-1"></i> Lanjutkan
                        </a>
                    @else
                        {{-- Tugas orang lain tetap TERLIHAT, tapi tidak bisa
                             diambil. Menyembunyikannya membuat operator
                             bertanya-tanya ke mana perginya satu daftar. --}}
                        <a href="{{ route('wms.picking.show', $daftar) }}" class="btn btn-outline-secondary rounded-3">
                            <i class="bi bi-eye me-1"></i> Lihat saja
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@empty
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-check2-circle display-5 d-block mb-3 opacity-50"></i>
                <h6 class="fw-bold text-dark">Tidak ada tugas pengambilan</h6>
                <p class="mb-0 small">Daftar picking disusun tim Logistik. Kalau belum ada, berarti belum ada yang perlu diambil.</p>
            </div>
        </div>
    </div>
@endforelse
</div>
@endsection
