@extends('layouts.wms')

@section('title', 'Laporan Stocktake '.$sesi->reference)
@section('page_title', 'Laporan Stocktake '.$sesi->reference)

@push('styles')
<style>
    /* Yang dicetak hanya laporannya. Sidebar, tombol, dan menu tidak punya
       arti di atas kertas dan hanya memakan halaman. */
    @media print {
        .sidebar, .navbar, .btn, .alert-dismissible .btn-close, .no-print { display: none !important; }
        .card { border: 0 !important; box-shadow: none !important; }
        main, .main-content, body { margin: 0 !important; padding: 0 !important; }
        table { font-size: 11px; }
    }
</style>
@endpush

@section('content')
{{-- Laporan stok global hasil stocktake — PER SKU, bukan per rak.
     Yang ditanyakan pembacanya adalah "SKU ini sekarang berapa", dan
     jawabannya tidak boleh berupa daftar rak yang harus dijumlahkan sendiri.
     Rincian per raknya tetap ada di layar penghitungan. --}}

<div class="no-print mb-3 d-flex flex-wrap gap-2">
    <a href="{{ route('wms.stocktake.index') }}" class="btn btn-sm btn-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke daftar stocktake
    </a>
    <button type="button" class="btn btn-sm btn-primary rounded-3" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> Cetak
    </button>
</div>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} border-0 shadow-sm rounded-3 no-print" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
    </div>
    @endif
@endforeach

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 border-bottom pb-3 mb-3">
            <div>
                <h4 class="fw-bold text-dark mb-1">Laporan Stocktake</h4>
                <div class="fs-5 font-monospace">{{ $sesi->reference }}</div>
            </div>
            <div class="small text-muted text-md-end">
                <div><strong>Gudang:</strong> {{ $sesi->warehouse?->name }} ({{ $sesi->warehouse?->code }})</div>
                <div><strong>Cakupan:</strong> {{ $sesi->scope_label }}</div>
                <div><strong>Dibuka:</strong> {{ $sesi->opened_at?->translatedFormat('d F Y, H:i') }}
                    &middot; {{ $sesi->openedBy?->full_name ?? '—' }}</div>
                {{-- TANGGAL SELESAI, yang diminta pemilik produk. Inilah saat
                     stok terbaru mulai berlaku. --}}
                <div><strong>Selesai &amp; disahkan:</strong>
                    @if($sesi->sudahDisahkan())
                        {{ $sesi->finalized_at?->translatedFormat('d F Y, H:i') }}
                        &middot; {{ $sesi->finalizedBy?->full_name ?? '—' }}
                    @else
                        <span class="text-danger">BELUM DISAHKAN</span>
                    @endif
                </div>
            </div>
        </div>

        @unless($sesi->sudahDisahkan())
            {{-- Pratinjau. Dikatakan keras-keras: angka "sesudah" di sini
                 belum berlaku di gudang, dan lembar seperti ini tidak boleh
                 beredar sebagai laporan resmi. --}}
            <div class="alert alert-warning border-0 rounded-3">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Ini baru pratinjau.</strong> Sesi belum disahkan, sehingga
                <strong>stok di gudang belum berubah</strong> dan kolom "sesudah" di bawah
                belum berlaku.
            </div>
        @endunless

        @if($ringkasan['belum'] > 0)
            <div class="alert alert-secondary border-0 rounded-3">
                <i class="bi bi-info-circle me-2"></i>
                <strong>{{ number_format($ringkasan['belum']) }} dari {{ number_format($ringkasan['baris']) }} baris
                tidak dihitung</strong> dalam sesi ini dan tidak disentuh sama sekali. Cakupan stocktake ini
                belum penuh — angkanya tetap seperti sebelumnya.
            </div>
        @endif

        <div class="row g-3 mb-4">
            @php
                $naik = collect($baris)->where('selisih', '>', 0)->sum('selisih');
                $turun = abs(collect($baris)->where('selisih', '<', 0)->sum('selisih'));
                $kartu = [
                    ['SKU diperiksa', number_format(count($baris)), 'dark'],
                    ['Baris dihitung', number_format($ringkasan['dihitung']).' / '.number_format($ringkasan['baris']), 'primary'],
                    ['Unit bertambah', '+'.number_format($naik), 'success'],
                    ['Unit berkurang', '-'.number_format($turun), 'danger'],
                ];
            @endphp
            @foreach($kartu as [$judul, $nilai, $warna])
                <div class="col-6 col-lg-3">
                    <div class="border rounded-3 p-3">
                        <div class="text-muted small">{{ $judul }}</div>
                        <div class="fs-5 fw-bold text-{{ $warna }}">{{ $nilai }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>SKU</th>
                        <th>Deskripsi</th>
                        <th class="text-end">Stok Sebelum</th>
                        <th class="text-end">Stok Sesudah</th>
                        <th class="text-end">Selisih</th>
                        <th class="text-center">Ket.</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($baris as $b)
                    <tr class="{{ $b['selisih'] !== 0 ? 'table-warning' : '' }}">
                        <td class="font-monospace">{{ $b['sku'] }}</td>
                        <td>{{ $b['nama'] }}</td>
                        <td class="text-end">{{ number_format($b['sebelum']) }} {{ $b['uom'] }}</td>
                        <td class="text-end fw-semibold">{{ number_format($b['sesudah']) }} {{ $b['uom'] }}</td>
                        <td class="text-end fw-bold {{ $b['selisih'] > 0 ? 'text-success' : ($b['selisih'] < 0 ? 'text-danger' : 'text-muted') }}">
                            {{ $b['selisih'] > 0 ? '+' : '' }}{{ number_format($b['selisih']) }}
                        </td>
                        <td class="text-center small">
                            @if($b['belum'] > 0)
                                <span class="text-muted">{{ $b['belum'] }} dari {{ $b['baris'] }} baris belum dihitung</span>
                            @else
                                <i class="bi bi-check-lg text-success"></i>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">Tidak ada baris stok dalam cakupan sesi ini.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="row mt-5 pt-3 small">
            <div class="col-6 text-center">
                <div class="text-muted mb-5">Dihitung oleh</div>
                <div class="border-top pt-1 mx-4">Tim Gudang</div>
            </div>
            <div class="col-6 text-center">
                <div class="text-muted mb-5">Disahkan oleh</div>
                <div class="border-top pt-1 mx-4">{{ $sesi->finalizedBy?->full_name ?? '—' }}</div>
            </div>
        </div>
    </div>
</div>

@if($cetakOtomatis && $sesi->sudahDisahkan())
<script>
    // Dibuka langsung setelah pengesahan: menurut pemilik produk, stok
    // terbaru berlaku bersamaan dengan terbitnya laporan ini.
    window.addEventListener('load', function () {
        window.print();
    });
</script>
@endif
@endsection
