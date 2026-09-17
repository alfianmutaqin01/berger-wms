@extends('layouts.wms')

@section('title', 'Riwayat Pemakaian MRF')
@section('page_title', 'Riwayat Pemakaian MRF')

@section('content')
{{-- CATATAN YANG TIDAK IKUT HILANG.

     Daftar MRF Picked menjawab "apa yang masih ada di tangan Produksi", dan
     material yang sudah habis wajar menghilang dari sana. Tetapi pertanyaan
     yang datang berbulan-bulan kemudian berbentuk lain — "batch ini dulu
     dipakai siapa, kapan, untuk apa" — dan jawabannya tidak boleh ikut hilang
     bersama material yang sudah selesai.

     Tiap pemakaian memang sudah dicatat sebagai baris sendiri sejak awal; yang
     belum ada hanyalah tempat membacanya. --}}

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('wms.material-produksi.index') }}" class="btn btn-sm btn-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke MRF Picked
    </a>
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['Pencatatan pemakaian', number_format($stats['baris']).'×', 'primary'],
        ['Unit terpakai', number_format($stats['unit']), 'dark'],
    ] as [$judul, $nilai, $warna])
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ $judul }}</div>
                    <div class="fs-4 fw-bold text-{{ $warna }}">{{ $nilai }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold text-secondary mb-1" for="cari">
                    Cari SKU, batch, nomor MRF, area, atau nama orang
                </label>
                <input type="search" name="search" id="cari" value="{{ $filters['search'] }}"
                       class="form-control form-control-sm rounded-3" placeholder="mis. I126080037 atau Budi">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1" for="dari">Dari tanggal</label>
                <input type="date" name="dari" id="dari" value="{{ $filters['dari'] }}"
                       class="form-control form-control-sm rounded-3">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1" for="sampai">Sampai</label>
                <input type="date" name="sampai" id="sampai" value="{{ $filters['sampai'] }}"
                       class="form-control form-control-sm rounded-3">
            </div>
            @if($gudangOptions->count() > 1)
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1" for="gudang">Gudang</label>
                <select name="warehouse_id" id="gudang" class="form-select form-select-sm rounded-3">
                    <option value="">Semua gudang</option>
                    @foreach($gudangOptions as $g)
                        <option value="{{ $g->id }}" @selected($filters['warehouse_id'] == $g->id)>{{ $g->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-12 col-md-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary rounded-3">Terapkan</button>
                <a href="{{ route('wms.material-produksi.riwayat') }}"
                   class="btn btn-sm btn-link text-decoration-none">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr class="small text-muted">
                        <th class="text-nowrap">Waktu</th>
                        <th>Produk</th>
                        <th>Batch</th>
                        <th>MRF</th>
                        <th class="text-end">Dipakai</th>
                        <th>Dicatat oleh</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($halaman as $pakai)
                    <tr>
                        <td class="small text-nowrap">{{ $pakai->consumed_at?->format('d/m/Y H:i') }}</td>
                        <td class="small">
                            {{-- SKU dan deskripsi berdampingan, sama seperti di
                                 daftar MRF Picked: mata membacanya sebagai satu
                                 kalimat, kode lalu namanya. --}}
                            <span class="font-monospace fw-semibold">{{ $pakai->holding?->product?->sku ?? '—' }}</span>
                            <span class="text-muted">—</span>
                            {{ $pakai->holding?->product?->name }}
                            @if($pakai->holding?->finished_at)
                                <span class="badge bg-success-subtle text-success-emphasis ms-1">Habis</span>
                            @endif
                        </td>
                        <td class="small font-monospace">{{ $pakai->holding?->batch_no ?? '—' }}</td>
                        <td class="small">
                            @if($pakai->holding?->material_requisition_id)
                                <a href="{{ route('wms.mrf.show', $pakai->holding->material_requisition_id) }}"
                                   class="font-monospace">{{ $pakai->holding->requisition?->mrf_number ?? '—' }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end fw-semibold text-nowrap">
                            {{ number_format($pakai->qty) }} {{ $pakai->holding?->product?->uom }}
                        </td>
                        {{-- SIAPA YANG MENCATAT. Produksi bukan satu orang, dan
                             inilah kolom yang membuat "dari 20 baru terpakai 10"
                             bisa ditelusuri sampai ke orangnya. --}}
                        <td class="small">{{ $pakai->consumedBy?->full_name ?? '—' }}</td>
                        <td class="small text-muted">{{ $pakai->note ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clock-history display-6 d-block mb-2 opacity-50"></i>
                            @if($filters['search'] || $filters['dari'] || $filters['sampai'])
                                Tidak ada pemakaian yang cocok dengan penyaring ini.
                                <a href="{{ route('wms.material-produksi.riwayat') }}" class="d-block mt-2">Tampilkan semua</a>
                            @else
                                Belum ada pemakaian material yang tercatat.
                            @endif
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
