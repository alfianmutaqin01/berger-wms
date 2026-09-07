@extends('layouts.wms')

@section('title', 'Hitung Opname '.$sesi->reference)
@section('page_title', 'Hitung Opname '.$sesi->reference)

@section('content')
{{-- Disusun DERET -> RAK, sama seperti denah, supaya orang yang menghitung
     membaca layar dengan urutan yang sama seperti saat ia berjalan menyusuri
     gudang. Mengurutkannya menurut SKU akan menyuruh operator bolak-balik
     melintasi gudang untuk satu produk. --}}

<a href="{{ route('wms.stocktake.index') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke daftar opname
</a>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h5 class="fw-bold text-dark mb-1 font-monospace">{{ $sesi->reference }}</h5>
                <div class="text-muted small">
                    {{ $sesi->warehouse?->name }} &middot; {{ $sesi->scope_label }} &middot;
                    dibuka {{ $sesi->opened_at?->translatedFormat('d M Y, H:i') }}
                    oleh {{ $sesi->openedBy?->full_name ?? '—' }}
                </div>
                @if($sesi->note)
                    <div class="text-muted small fst-italic mt-1">{{ $sesi->note }}</div>
                @endif
            </div>

            @if($sesi->sedangDihitung())
                @can(\App\Support\Permission::STOCKTAKE_MANAGE)
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('wms.stocktake.cancel', $sesi) }}"
                          onsubmit="return confirm('Batalkan sesi {{ $sesi->reference }}? Tidak ada angka stok yang berubah.');">
                        @csrf
                        <button class="btn btn-outline-secondary rounded-3">Batalkan Sesi</button>
                    </form>
                    <button type="button" class="btn btn-success fw-bold rounded-3"
                            data-bs-toggle="modal" data-bs-target="#modalSahkan">
                        <i class="bi bi-printer me-1"></i> Sahkan &amp; Cetak Laporan
                    </button>
                </div>
                @endcan
            @else
                <a href="{{ route('wms.stocktake.report', $sesi) }}" class="btn btn-outline-secondary rounded-3">
                    <i class="bi bi-file-earmark-text me-1"></i> Lihat Laporan
                </a>
            @endif
        </div>

        <div class="row g-3 mt-1">
            @php($kartu = [
                ['Baris dihitung', $ringkasan['dihitung'].' / '.$ringkasan['baris'], 'primary'],
                ['Cocok', $ringkasan['cocok'], 'success'],
                ['Ada selisih', $ringkasan['selisih'], 'danger'],
                ['Belum dihitung', $ringkasan['belum'], 'secondary'],
            ])
            @foreach($kartu as [$judul, $nilai, $warna])
                <div class="col-6 col-lg-3">
                    <div class="border rounded-3 p-3">
                        <div class="text-muted small">{{ $judul }}</div>
                        <div class="fs-5 fw-bold text-{{ $warna }}">{{ $nilai }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($sesi->sedangDihitung())
            <div class="alert alert-info border-0 rounded-3 small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Menyimpan hitungan <strong>belum mengubah stok</strong>. Angka gudang baru berubah saat
                laporannya disahkan — dan rak yang belum sempat dihitung tidak akan disentuh sama sekali.
            </div>
        @endif
    </div>
</div>

@forelse($deret as $namaDeret => $perRak)
    <div class="card shadow-sm border-0 rounded-4 mb-3">
        <div class="card-body p-4">
            <h6 class="fw-bold text-dark mb-3">
                <i class="bi bi-bookshelf text-primary me-2"></i>Deret {{ $namaDeret }}
                <span class="badge bg-light text-dark border ms-1">{{ $perRak->count() }} rak</span>
            </h6>

            @foreach($perRak as $kodeRak => $baris)
                <div class="border rounded-3 mb-2">
                    <div class="px-3 py-2 bg-light-subtle border-bottom d-flex flex-wrap justify-content-between gap-2">
                        <span class="fw-semibold font-monospace">{{ $kodeRak }}</span>
                        <span class="small text-muted">{{ $baris->count() }} batch</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr class="small text-muted">
                                    <th>Produk</th>
                                    <th>Batch</th>
                                    <th class="text-end">Sistem</th>
                                    <th style="width:220px">Hitungan fisik</th>
                                    <th class="text-end">Selisih</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($baris as $item)
                                <tr>
                                    <td>
                                        <div class="fw-semibold font-monospace small">{{ $item->product?->sku ?? '—' }}</div>
                                        <div class="text-muted" style="font-size:.72rem">{{ $item->product?->name }}</div>
                                    </td>
                                    <td class="font-monospace small">{{ $item->batch_no ?? '—' }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($item->qty_system) }}</td>
                                    <td>
                                        @if($sesi->sedangDihitung())
                                            <form method="POST" action="{{ route('wms.stocktake.count', $item) }}"
                                                  class="d-flex gap-1">
                                                @csrf
                                                <input type="number" name="qty_physical" min="0" required
                                                       value="{{ $item->qty_physical }}"
                                                       class="form-control form-control-sm" style="max-width:90px">
                                                <input type="text" name="count_note" maxlength="500"
                                                       value="{{ $item->count_note }}"
                                                       class="form-control form-control-sm" placeholder="catatan">
                                                <button class="btn btn-sm btn-primary" title="Simpan hitungan">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        @else
                                            {{ $item->sudahDihitung() ? number_format($item->qty_physical) : '—' }}
                                        @endif
                                        @if($item->sudahDihitung())
                                            <div class="text-muted" style="font-size:.7rem">
                                                {{ $item->countedBy?->full_name ?? '—' }},
                                                {{ $item->counted_at?->format('d M H:i') }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        {{-- Tiga keadaan BERBEDA. "Belum dihitung"
                                             bukan hal yang sama dengan "cocok", dan
                                             menampilkan keduanya sebagai 0 membuat
                                             rak yang terlewat terbaca sudah beres. --}}
                                        @if(! $item->sudahDihitung())
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Belum</span>
                                        @elseif($item->selisih === 0)
                                            <span class="badge bg-success-subtle text-success-emphasis">Cocok</span>
                                        @else
                                            <span class="badge bg-danger-subtle text-danger-emphasis">
                                                {{ $item->selisih > 0 ? '+' : '' }}{{ number_format($item->selisih) }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@empty
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
            Tidak ada baris stok dalam cakupan sesi ini.
        </div>
    </div>
@endforelse

@can(\App\Support\Permission::STOCKTAKE_MANAGE)
<div class="modal fade" id="modalSahkan" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.stocktake.finalize', $sesi) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Sahkan Laporan Opname</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Seluruh selisih akan <strong>diterapkan ke stok</strong> dan tercatat di ledger sebagai
                    koreksi opname. Sesudah ini hitungannya tidak bisa diubah lagi.
                </p>
                @if($ringkasan['belum'] > 0)
                    <div class="alert alert-warning border-0 rounded-3 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>{{ number_format($ringkasan['belum']) }} baris belum dihitung.</strong>
                        Baris itu <strong>tidak akan disentuh</strong> — stoknya tetap seperti sekarang, dan
                        laporannya akan menyebutkan bahwa cakupan opname ini belum penuh.
                    </div>
                @endif
                <div class="border rounded-3 p-3 small">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Baris cocok</span>
                        <span class="fw-semibold">{{ number_format($ringkasan['cocok']) }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Baris berselisih</span>
                        <span class="fw-semibold text-danger">{{ number_format($ringkasan['selisih']) }}</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-success rounded-3 fw-bold">
                    <i class="bi bi-printer me-1"></i> Sahkan &amp; Cetak
                </button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
