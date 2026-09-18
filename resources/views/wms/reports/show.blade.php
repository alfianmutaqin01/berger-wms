@extends('layouts.wms')

@section('title', $meta['nama'])
@section('page_title', 'Laporan — '.$meta['nama'])

@section('content')
@php
    $terpotong = $tabel['total'] > count($tabel['baris']);
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <a href="{{ route('wms.reports.index') }}" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i>Semua laporan
        </a>
        <h4 class="fw-bold text-dark mb-1 mt-1">
            <i class="bi {{ $meta['ikon'] }} text-{{ $meta['warna'] }} me-2"></i>{{ $meta['nama'] }}
        </h4>
        <p class="text-muted small mb-0">{{ $meta['ringkas'] }}</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <select class="form-select form-select-sm" style="width:auto"
                onchange="if(this.value) window.location = this.value">
            @foreach($laporan as $k => $m)
                <option value="{{ route('wms.reports.show', $k) }}" @selected($k === $key)>{{ $m['nama'] }}</option>
            @endforeach
        </select>
        <a class="btn btn-success btn-sm px-3 fw-semibold"
           href="{{ route('wms.reports.download', array_merge(['key' => $key], array_filter($filter))) }}" data-tanpa-pemuat>
            <i class="bi bi-file-earmark-excel me-1"></i>Unduh Excel
        </a>
    </div>
</div>

{{-- Penjelasan cara membaca laporan ini ditaruh DI ATAS tabelnya, bukan di
     bawah. Yang membuka halaman ini biasanya langsung menyalin angkanya. --}}
<div class="alert alert-light border rounded-4 small d-flex gap-3 align-items-start mb-3">
    <i class="bi bi-lightbulb text-warning fs-5 mt-1"></i>
    <div>
        {{ $meta['bantuan'] }}
        <div class="text-muted mt-1">
            Rentang tanggal dihitung dari <strong>{{ $meta['dasar'] }}</strong>.
            Semua angka berupa kuantitas — sistem ini tidak menyimpan harga.
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            @if($meta['berkala'])
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Dari tanggal</label>
                    <input type="date" name="dari" value="{{ $filter['dari'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Sampai tanggal</label>
                    <input type="date" name="sampai" value="{{ $filter['sampai'] }}" class="form-control form-control-sm">
                </div>
            @else
                {{-- Kolom tanggal tidak digambar sama sekali untuk laporan potret.
                     Menggambarnya dalam keadaan mati masih mengundang orang mengisinya
                     lalu heran kenapa angkanya tidak berubah. --}}
                <div class="col-12 col-md-6">
                    <div class="alert alert-secondary border-0 rounded-3 small mb-0 py-2 px-3">
                        <i class="bi bi-clock-history me-1"></i>
                        Laporan ini menampilkan <strong>keadaan saat ini</strong>, jadi tidak
                        ada rentang tanggal yang bisa dipilih.
                    </div>
                </div>
            @endif

            @if($gudangPilihan->count() > 1)
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Gudang</label>
                    <select name="warehouse_id" class="form-select form-select-sm">
                        <option value="">Semua gudang</option>
                        @foreach($gudangPilihan as $w)
                            <option value="{{ $w->id }}" @selected((string) $filter['warehouse_id'] === (string) $w->id)>
                                {{ $w->code }} — {{ $w->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-dark px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
                <a href="{{ route('wms.reports.show', $key) }}" class="btn btn-sm btn-outline-secondary"
                   title="Kembalikan ke penyaring bawaan">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

@if($ringkas)
    <div class="row g-3 mb-3">
        @foreach($ringkas as $label => $nilai)
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body py-3 px-4">
                        <div class="text-muted small">{{ $label }}</div>
                        <div class="fw-bold fs-4 text-dark">{{ $nilai }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif

@if($terpotong)
    {{-- Peringatannya menyebut ANGKA SEBENARNYA, bukan sekadar "data terlalu
         banyak". Yang tahu ada 43.000 baris tapi cuma 20.000 yang terbawa akan
         mempersempit rentangnya; yang cuma dibilang "terlalu banyak" mengunduh
         apa adanya. --}}
    <div class="alert alert-warning border-0 rounded-4 small">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Penyaring ini menghasilkan <strong>{{ number_format($tabel['total']) }}</strong> baris,
        sementara satu berkas unduhan memuat maksimal
        <strong>{{ number_format($maksBaris) }}</strong> baris. Persempit rentang tanggalnya —
        atau unduh per gudang — kalau butuh datanya lengkap.
    </div>
@endif

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white border-0 pt-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold mb-0">Pratinjau</h6>
        <small class="text-muted">
            {{ number_format($tabel['total']) }} baris ditemukan,
            {{ number_format(count($tabel['baris'])) }} ditampilkan di sini —
            berkas unduhan berisi seluruhnya.
        </small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0" style="font-size:.82rem">
            <thead class="table-light">
                <tr>
                    @foreach($tabel['kolom'] as $i => $judul)
                        <th class="text-nowrap {{ in_array($i, $tabel['angka'], true) ? 'text-end' : '' }}">
                            {{ $judul }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @forelse($tabel['baris'] as $baris)
                <tr>
                    @foreach(array_values($baris) as $i => $nilai)
                        <td class="{{ in_array($i, $tabel['angka'], true) ? 'text-end font-monospace' : '' }}">
                            @if($nilai === null || $nilai === '')
                                <span class="text-muted">—</span>
                            @elseif(is_int($nilai))
                                {{ number_format($nilai) }}
                            @else
                                {{ $nilai }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(count($tabel['kolom']), 1) }}" class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                        Tidak ada data pada penyaring ini.
                        @if($meta['berkala'])
                            Coba lebarkan rentang tanggalnya.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
