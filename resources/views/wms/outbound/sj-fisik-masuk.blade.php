@extends('layouts.wms')

@section('title', __('Terima SJ Fisik'))
@section('page_title', __('Terima Surat Jalan Fisik'))

@section('content')
{{-- HALAMAN AWAL SEBUAH PERAN.

     Inilah yang dilihat Customer Account begitu masuk — bukan dasbor berisi
     angka, sebab hanya ada satu pekerjaan di sini dan daftar ini sudah
     merupakan pekerjaan itu. Yang paling lama di jalan berada di atas. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Tutup') }}"></button>
    </div>
    @endif
@endforeach

@php
    $R = \App\Http\Controllers\Wms\SjHandoverReceiptController::class;
    $tabs = [
        $R::TAB_MASUK => ['judul' => __('Menunggu Diperiksa'), 'warna' => 'danger', 'sub' => __('Belum ada amplop yang menunggu.')],
        $R::TAB_RIWAYAT => ['judul' => __('Riwayat'), 'warna' => 'secondary', 'sub' => __('Belum ada paket yang selesai diperiksa.')],
    ];
@endphp

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-1">
            <i class="bi bi-envelope-open text-primary me-2"></i> {{ __('Amplop Surat Jalan dari gudang') }}
        </h5>
        <small class="text-muted d-block mb-3">
            {{ __('Buka amplopnya, cocokkan lembarnya dengan daftar, lalu tandai satu per satu.') }}
        </small>

        <ul class="nav nav-tabs border-0 gap-1">
            @foreach($tabs as $kunci => $t)
            <li class="nav-item">
                <a class="nav-link rounded-top-3 {{ $tab === $kunci ? 'active fw-semibold' : 'text-muted' }}"
                   href="{{ route('wms.sj-fisik.masuk', array_filter(['tab' => $kunci, 'search' => $filters['search']])) }}">
                    {{ $t['judul'] }}
                    @if($jumlah[$kunci] > 0)
                        <span class="badge bg-{{ $tab === $kunci ? $t['warna'] : 'secondary' }}-subtle text-{{ $tab === $kunci ? $t['warna'] : 'secondary' }}-emphasis ms-1">
                            {{ $jumlah[$kunci] }}
                        </span>
                    @endif
                </a>
            </li>
            @endforeach
        </ul>
    </div>

    <div class="card-body p-4">
        <form method="GET" class="row g-2 mb-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-12 col-md-6">
                <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control rounded-3"
                       placeholder="{{ __('Cari nomor paket, nomor Surat Jalan, pembawa, atau resi…') }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary rounded-3"><i class="bi bi-search me-1"></i> {{ __('Cari') }}</button>
            </div>
            @if($filters['search'])
            <div class="col-auto d-flex align-items-center">
                <a href="{{ route('wms.sj-fisik.masuk', ['tab' => $tab]) }}" class="small">{{ __('Tampilkan semua') }}</a>
            </div>
            @endif
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Paket') }}</th>
                        <th>{{ __('Gudang asal') }}</th>
                        <th>{{ __('Isi') }}</th>
                        <th>{{ __('Dikirim lewat') }}</th>
                        <th>{{ __('Berangkat') }}</th>
                        <th class="text-end">{{ __('Tindakan') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($paket as $p)
                    <tr>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $p->code }}</span>
                            @if($p->terlambat())
                                <div class="small text-danger">
                                    <i class="bi bi-clock-history me-1"></i>
                                    {{ __(':n hari di jalan', ['n' => $p->umurHari()]) }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $p->warehouse?->name ?? '—' }}</td>
                        <td>{{ $p->items_count }} {{ __('lembar') }}</td>
                        <td>
                            <div>{{ $p->carrier_name }}</div>
                            <small class="text-muted">
                                {{ $p->carrier_label }}{{ $p->tracking_no ? ' · '.$p->tracking_no : '' }}
                            </small>
                        </td>
                        <td>
                            {{ $p->sent_at?->translatedFormat('d M Y') }}
                            <div class="small text-muted">{{ $p->sentBy?->full_name }}</div>
                        </td>
                        <td class="text-end">
                            @if($p->dalamPerjalanan())
                                <a href="{{ route('wms.sj-fisik.masuk.show', $p) }}" class="btn btn-sm btn-primary rounded-3">
                                    {{ __('Periksa') }}
                                </a>
                            @else
                                <a href="{{ route('wms.sj-fisik.masuk.show', $p) }}" class="btn btn-sm btn-outline-secondary rounded-3">
                                    {{ __('Lihat') }}
                                </a>
                                @if($p->jumlahBermasalah() > 0)
                                    <div class="small text-warning-emphasis mt-1">
                                        {{ $p->jumlahBermasalah() }} {{ __('catatan') }}
                                    </div>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                            {{ $tabs[$tab]['sub'] }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $paket->links() }}</div>
    </div>
</div>
@endsection
