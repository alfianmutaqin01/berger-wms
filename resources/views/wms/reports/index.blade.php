@extends('layouts.wms')

@section('title', 'Laporan & Analisis')
@section('page_title', 'Laporan & Analisis')

@section('content')
<div class="mb-4">
    <h4 class="fw-bold text-dark mb-1">Laporan & Analisis</h4>
    <p class="text-muted mb-0">
        Pilih laporan untuk melihat pratinjaunya lebih dulu, lalu unduh sebagai Excel.
    </p>
</div>

{{-- DIKATAKAN DI DEPAN, bukan ditunggu sampai orang membuka berkasnya dan
     mencari kolom nilai. Tidak ada satu pun kolom harga di seluruh basis data
     ini, jadi "penjualan" di sini selalu berarti barang yang keluar. --}}
<div class="alert alert-light border rounded-4 small d-flex gap-3 align-items-start">
    <i class="bi bi-info-circle text-primary fs-5 mt-1"></i>
    <div>
        <strong class="d-block mb-1">Semua angka di sini berupa kuantitas, bukan rupiah.</strong>
        Sistem ini tidak menyimpan harga sama sekali, jadi "penjualan" berarti jumlah barang
        yang keluar. Kalau butuh nilai rupiah, gabungkan berkas ini dengan daftar harga dari
        Finance memakai kolom SKU.
    </div>
</div>

<div class="row g-4">
    @foreach($laporan as $key => $m)
        <div class="col-12 col-md-6 col-xl-4">
            <a href="{{ route('wms.reports.show', $key) }}"
               class="card h-100 shadow-sm border-0 rounded-4 text-decoration-none kartu-laporan">
                <div class="card-body p-4 d-flex flex-column">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="rounded-3 bg-{{ $m['warna'] }} bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:48px;height:48px;">
                            <i class="bi {{ $m['ikon'] }} text-{{ $m['warna'] }} fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-dark mb-0">{{ $m['nama'] }}</h6>
                            {{-- Sifatnya disebut di kartu, bukan baru ketahuan setelah
                                 dibuka: laporan POTRET tidak menghormati rentang tanggal
                                 sama sekali. --}}
                            <span class="badge rounded-pill {{ $m['berkala'] ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }} mt-1"
                                  style="font-size:.68rem">
                                {{ $m['berkala'] ? 'Per periode' : 'Potret saat ini' }}
                            </span>
                        </div>
                    </div>
                    <p class="text-muted small mb-3 flex-grow-1">{{ $m['ringkas'] }}</p>
                    <div class="d-flex align-items-center justify-content-between">
                        <small class="text-muted">
                            <i class="bi bi-calendar3 me-1"></i>{{ ucfirst($m['dasar']) }}
                        </small>
                        <span class="text-{{ $m['warna'] }} fw-semibold small">
                            Buka <i class="bi bi-arrow-right ms-1"></i>
                        </span>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>

@push('styles')
<style>
    .kartu-laporan { transition: transform .18s ease, box-shadow .18s ease; }
    .kartu-laporan:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,.08) !important;
    }
</style>
@endpush
@endsection
