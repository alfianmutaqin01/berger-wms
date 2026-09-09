@extends('layouts.wms')

@section('title', 'Pengaturan Sistem')
@section('page_title', 'Pengaturan Sistem')

@section('content')
<div class="row mb-3">
    <div class="col-12 col-lg-8">
        <h4 class="fw-bold text-dark mb-1">Pengaturan Sistem</h4>
        <p class="text-muted small mb-0">
            Aturan operasional yang berlaku untuk seluruh gudang. Perubahannya langsung berlaku
            dan tercatat di Log Aktivitas.
        </p>
    </div>
</div>

@foreach(['success', 'error'] as $jenis)
    @if(session($jenis))
        <div class="alert alert-{{ $jenis === 'error' ? 'danger' : 'success' }} border-0 rounded-4 small">
            {{ session($jenis) }}
        </div>
    @endif
@endforeach

@if($errors->any())
    <div class="alert alert-danger border-0 rounded-4 small">
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $galat)<li>{{ $galat }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('wms.admin.settings.update') }}">
    @csrf

    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3 p-md-4">
            @foreach($daftar as $key => $meta)
                <div class="row align-items-start g-3 {{ $loop->last ? '' : 'border-bottom pb-3 mb-3' }}">
                    <div class="col-12 col-md-7">
                        <label for="setelan-{{ $key }}" class="fw-semibold text-dark mb-1 d-block">
                            {{ $meta['label'] }}
                        </label>
                        <div class="text-muted small">{{ $meta['bantuan'] }}</div>

                        {{-- Peringatan ditaruh DI SINI, bukan di bawah tombol Simpan.
                             Yang perlu tahu bahwa menurunkan angka ini menghapus data
                             adalah orang yang sedang mengetik angkanya, bukan orang
                             yang sudah menekan Simpan. --}}
                        @if(! empty($meta['peringatan']))
                            <div class="alert alert-warning border-0 small py-2 mt-2 mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $meta['peringatan'] }}
                            </div>
                        @endif
                    </div>

                    <div class="col-12 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white small text-muted">{{ $meta['satuan'] }}</span>
                            <input type="number" class="form-control fw-bold text-center"
                                   id="setelan-{{ $key }}" name="{{ $key }}"
                                   value="{{ old($key, $nilai[$key]) }}"
                                   min="{{ $meta['min'] }}" max="{{ $meta['max'] }}" required>
                        </div>
                        <div class="form-text">
                            Batas {{ $meta['min'] }}–{{ $meta['max'] }} &middot; bawaan {{ $meta['bawaan'] }}
                            @if($nilai[$key] !== $meta['bawaan'])
                                <span class="badge bg-info-subtle text-info-emphasis rounded-pill ms-1">diubah</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card-footer bg-white border-0 p-3 p-md-4 pt-0 d-flex flex-column flex-md-row gap-2 align-items-md-center">
            <button class="btn btn-primary rounded-3 px-4 fw-bold">
                <i class="bi bi-save me-1"></i> Simpan Pengaturan
            </button>
            @if($terakhir)
                <small class="text-muted">
                    Terakhir diubah {{ $terakhir->updated_at?->translatedFormat('d M Y, H:i') }}
                    @if($terakhir->updatedBy) oleh {{ $terakhir->updatedBy->full_name }}@endif.
                </small>
            @endif
        </div>
    </div>
</form>

{{-- Dikatakan apa adanya, supaya tidak ada yang mencari tombol yang memang
     sengaja tidak dibuat. --}}
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-3 p-md-4">
        <h6 class="fw-bold text-dark mb-2">
            <i class="bi bi-info-circle text-primary me-2"></i>Yang sengaja TIDAK bisa diubah dari sini
        </h6>
        <ul class="text-muted small mb-0 ps-3">
            <li class="mb-1">
                <strong>Format &amp; nomor urut dokumen.</strong> Mengganti awalan di tengah jalan
                memecah riwayat menjadi dua bentuk yang tidak bisa dicari sekaligus, dan menggeser
                nomor mundur menghasilkan nomor kembar yang menghentikan pembuatan pesanan untuk
                semua orang. Keadaannya bisa dilihat di
                <a href="{{ route('wms.admin.sequence') }}">Penomoran Dokumen</a>.
            </li>
            <li class="mb-1">
                <strong>Hak akses per peran.</strong> Diatur di kode bersama halaman yang dijaganya,
                supaya menu dan halamannya tidak pernah berbeda pendapat.
            </li>
            <li>
                <strong>Batas percobaan login.</strong> Aturan keamanan, bukan aturan operasional
                gudang.
            </li>
        </ul>
    </div>
</div>
@endsection
