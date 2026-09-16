@extends('layouts.wms')

@section('title', 'Laporan Stocktake '.$sesi->reference)
@section('page_title', 'Laporan Stocktake '.$sesi->reference)

@section('content')
{{-- Laporan stok global hasil stocktake — PER SKU, bukan per rak.
     Yang ditanyakan pembacanya adalah "SKU ini sekarang berapa", dan
     jawabannya tidak boleh berupa daftar rak yang harus dijumlahkan sendiri.
     Rincian per raknya tetap ada di layar penghitungan. --}}

<div class="mb-3 d-flex flex-wrap gap-2">
    <a href="{{ route('wms.stocktake.index') }}" class="btn btn-sm btn-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke daftar stocktake
    </a>
    <a href="{{ route('wms.stocktake.report.download', $sesi) }}" class="btn btn-sm btn-success rounded-3">
        <i class="bi bi-file-earmark-excel me-1"></i> Unduh Excel
    </a>
</div>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} border-0 shadow-sm rounded-3" role="alert">
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
                <strong>stok di gudang belum berubah</strong>. Kolom "sesudah" di bawah adalah
                <strong>perkiraan</strong> — angka yang akan berlaku kalau laporan ini disahkan
                sekarang juga.
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
                    ['Unit bertambah'.($sesi->sudahDisahkan() ? '' : ' (perkiraan)'), '+'.number_format($naik), 'success'],
                    ['Unit berkurang'.($sesi->sudahDisahkan() ? '' : ' (perkiraan)'), '-'.number_format($turun), 'danger'],
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
                        <th class="text-end">Stok Sesudah{{ $sesi->sudahDisahkan() ? '' : ' (perkiraan)' }}</th>
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

{{-- PENGESAHAN DIPINDAH KE SINI, di kaki laporan.
     Sebelumnya tombolnya ada di layar penghitungan, dan yang menekannya
     mengesahkan angka yang belum pernah ia lihat berjejer. Stocktake lazim
     dikerjakan beberapa orang; kesalahan satu orang baru kelihatan saat
     seluruh SKU berbaris dalam satu halaman seperti ini.

     Urutannya sekarang: hitung -> periksa laporan ini -> baru sahkan. --}}
@unless($sesi->sudahDisahkan())
    @if($sesi->sedangDihitung())
        @can(\App\Support\Permission::STOCKTAKE_MANAGE)
        <div class="card border-0 shadow-sm rounded-4 mt-3">
            <div class="card-body p-4">
                <h6 class="fw-bold text-dark mb-2">
                    <i class="bi bi-clipboard-check text-success me-2"></i>Sudah diperiksa?
                </h6>
                <p class="small text-muted mb-3">
                    Periksa dulu tiap baris di atas. Setelah disahkan, seluruh selisih
                    <strong>diterapkan ke stok</strong> dan tercatat di ledger — dan tidak ada tombol
                    untuk menariknya kembali. Kalau ada angka yang meleset,
                    <a href="{{ route('wms.stocktake.show', $sesi) }}">kembali ke layar penghitungan</a>
                    dan perbaiki dulu.
                </p>

                @if($ringkasan['belum'] > 0)
                    <div class="alert alert-warning border-0 rounded-3 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Masih ada <strong>{{ number_format($ringkasan['belum']) }} baris yang belum dihitung</strong>.
                        Mengesahkan sekarang membuat baris itu tidak disentuh sama sekali — angkanya tetap
                        seperti sebelumnya, dan itu ikut tertulis di laporan.
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('wms.stocktake.show', $sesi) }}" class="btn btn-outline-secondary rounded-3">
                        <i class="bi bi-arrow-left me-1"></i> Kembali Menghitung
                    </a>
                    <form method="POST" action="{{ route('wms.stocktake.finalize', $sesi) }}"
                          onsubmit="return confirm('Sahkan laporan {{ $sesi->reference }}? Seluruh selisih akan diterapkan ke stok dan tidak bisa ditarik kembali.');">
                        @csrf
                        <button class="btn btn-success fw-bold rounded-3">
                            <i class="bi bi-check2-circle me-1"></i> Sahkan Laporan
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @else
        <div class="alert alert-secondary border-0 rounded-3 mt-3 small">
            <i class="bi bi-info-circle me-1"></i>
            Laporan ini masih pratinjau. Pengesahannya wewenang Manager atau Super Admin —
            beritahu mereka setelah seluruh rak selesai dihitung.
        </div>
        @endcan
    @endif
@endunless

@endsection
