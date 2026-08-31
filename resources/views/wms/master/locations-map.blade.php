@extends('layouts.wms')

@section('title', 'Denah Gudang')
@section('page_title', 'Denah Gudang')

@push('styles')
<style>
    /* Satu kotak = satu bin. Ukurannya sengaja kecil agar satu rak penuh
       (hingga 21 sel) muat dalam satu baris tanpa menggulir menyamping. */
    .bin {
        width: 42px;
        height: 34px;
        border-radius: 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid transparent;
        transition: transform 0.12s ease, box-shadow 0.12s ease;
        user-select: none;
    }
    .bin:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.18);
        z-index: 2;
    }
    .bin-zone-fast   { background: #d1e7dd; color: #0a3622; border-color: #a3cfbb; }
    .bin-zone-slow   { background: #e2e3e5; color: #2b2f32; border-color: #c4c8cb; }
    .bin-zone-middle { background: #cff4fc; color: #055160; border-color: #9eeaf9; }

    /* Bin non-aktif: dicoret agar jelas tidak boleh dipakai put-away. */
    .bin-inactive {
        background: #f8d7da !important;
        color: #842029 !important;
        border-color: #f1aeb5 !important;
        text-decoration: line-through;
        opacity: 0.75;
    }

    /* Hasil pencarian ditonjolkan agar mudah ditemukan di antara ribuan bin. */
    .bin-highlight {
        outline: 3px solid #1B4F8A;
        outline-offset: 1px;
        animation: binPulse 1.2s ease-in-out 3;
    }
    @keyframes binPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(27, 79, 138, 0.55); }
        50%      { box-shadow: 0 0 0 8px rgba(27, 79, 138, 0); }
    }

    .level-label {
        width: 46px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
        flex-shrink: 0;
    }
    .rack-card { scroll-margin-top: 90px; }
</style>
@endpush

@section('content')
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

<!-- Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Total Bin</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ number_format($stats['total']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Aktif</h6>
                <h3 class="mb-0 fw-bold text-success">{{ number_format($stats['active']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Non-aktif</h6>
                <h3 class="mb-0 fw-bold text-danger">{{ number_format($stats['inactive']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-primary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Jumlah Rak</h6>
                <h3 class="mb-0 fw-bold text-primary">{{ count($racks) }}</h3>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-map text-primary me-2"></i> Denah Gudang {{ $warehouse?->code }}</h5>
            <p class="text-muted small mt-1 mb-0">
                Tiap kotak adalah satu bin. Level 5 di atas, Level 1 di bawah — sesuai letak fisiknya di rak.
                Klik kotak untuk melihat detail.
            </p>
        </div>
        <a href="{{ route('wms.locations.index') }}" class="btn btn-outline-secondary fw-bold">
            <i class="bi bi-list-ul me-1"></i> Tampilan Tabel
        </a>
    </div>

    <div class="card-body px-4 pb-4 pt-3">
        <form method="GET" action="{{ route('wms.locations.map') }}" class="row g-2 align-items-stretch">
            <div class="col-12 col-md-4">
                <div class="input-group h-100">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-crosshair text-muted"></i></span>
                    <input type="text" name="highlight" value="{{ $filters['highlight'] }}" class="form-control bg-white border-start-0 font-monospace text-uppercase" placeholder="Lacak bin, contoh: B-01-01">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="warehouse_id" class="form-select h-100">
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($warehouse?->id === $w->id)>{{ $w->display_label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="zone" class="form-select h-100">
                    <option value="">Semua Zona</option>
                    @foreach($zones as $zone)
                        <option value="{{ $zone }}" @selected($filters['zone'] === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i> Lacak</button>
                <a href="{{ route('wms.locations.map') }}" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>

        <!-- Keterangan warna -->
        <div class="d-flex flex-wrap align-items-center gap-3 mt-3 pt-3 border-top small text-muted">
            <span class="fw-semibold">Keterangan:</span>
            <span><span class="bin bin-zone-fast d-inline-flex align-middle" style="width:26px;height:20px;"></span> Fast Moving</span>
            <span><span class="bin bin-zone-slow d-inline-flex align-middle" style="width:26px;height:20px;"></span> Slow Moving</span>
            <span><span class="bin bin-zone-middle d-inline-flex align-middle" style="width:26px;height:20px;"></span> Middle Moving</span>
            <span><span class="bin bin-inactive d-inline-flex align-middle" style="width:26px;height:20px;"></span> Non-aktif</span>
        </div>
    </div>
</div>

{{-- Pintasan lompat ke rak tertentu — dengan 29 rak, menggulir manual melelahkan. --}}
@if(count($racks) > 1)
<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body px-4 py-3 d-flex flex-wrap align-items-center gap-2">
        <span class="small fw-semibold text-muted me-1">Lompat ke rak:</span>
        @foreach($racks as $rack => $levels)
            <a href="#rak-{{ $rack }}" class="btn btn-sm btn-outline-secondary py-0 px-2 font-monospace">{{ $rack }}</a>
        @endforeach
    </div>
</div>
@endif

@forelse($racks as $rack => $levels)
    @php $meta = $rackMeta[$rack]; @endphp
    <div class="card shadow-sm border-0 rounded-4 mb-3 rack-card js-rack"
         id="rak-{{ $rack }}" data-rack="{{ $rack }}" data-zone="{{ $meta['zone'] }}">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h6 class="fw-bold text-dark mb-0">
                    <i class="bi bi-bookshelf text-primary me-2"></i>Rak {{ $rack }}
                </h6>
                <div class="d-flex align-items-center gap-2">
                    @if($meta['inactive'] > 0)
                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger">{{ $meta['inactive'] }} non-aktif</span>
                    @endif
                    <span class="badge bg-light text-dark border">{{ $meta['total'] }} bin</span>
                    @if($meta['zone'])
                        @php
                            $warna = match($meta['zone']) {
                                \App\Models\Location::ZONE_FAST => 'success',
                                \App\Models\Location::ZONE_SLOW => 'secondary',
                                default => 'info',
                            };
                        @endphp
                        <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis border border-{{ $warna }}">{{ $meta['zone'] }}</span>
                    @endif
                </div>
            </div>

            {{-- Level tertinggi digambar paling atas agar denah ini terbaca
                 sama seperti saat operator berdiri menghadap rak. --}}
            {{-- Tiap bin hanya membawa id, nomor sel, dan status. Kode, rak, dan
                 zona disusun ulang di sisi JavaScript dari atribut kartu rak dan
                 baris level. Menyertakan payload lengkap di tiap kotak membuat
                 halaman membengkak lebih dari dua kali lipat pada gudang berisi
                 2.264 bin. --}}
            @foreach($levels->sortKeysDesc() as $level => $bins)
                <div class="d-flex align-items-center mb-1 js-level" data-level="{{ $level }}">
                    <div class="level-label">L{{ $level }}</div>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach($bins as $bin)
                            @php
                                $zoneClass = match($bin->zone) {
                                    \App\Models\Location::ZONE_FAST => 'bin-zone-fast',
                                    \App\Models\Location::ZONE_SLOW => 'bin-zone-slow',
                                    default => 'bin-zone-middle',
                                };
                                $isHighlighted = $filters['highlight'] !== ''
                                    && str_contains($bin->code, strtoupper($filters['highlight']));
                            @endphp
                            <div class="bin {{ $bin->is_active ? $zoneClass : 'bin-inactive' }} {{ $isHighlighted ? 'bin-highlight' : '' }}"
                                 data-id="{{ $bin->id }}"
                                 data-cell="{{ $bin->cell }}"
                                 @if(! $bin->is_active) data-inactive="1" @endif
                                 title="{{ $bin->code }}{{ $bin->is_active ? '' : ' (non-aktif)' }}">
                                {{-- Slot isi bin (FASE 4): begitu inventory_stocks ada,
                                     total qty ditampilkan di sini dan warna kotak
                                     diganti menjadi indikator keterisian. Struktur
                                     denahnya tidak perlu diubah. --}}
                                {{ str_pad($bin->cell, 2, '0', STR_PAD_LEFT) }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@empty
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
            Belum ada lokasi untuk gudang ini.
        </div>
    </div>
@endforelse
@endsection

@push('modals')
<div class="modal fade" id="binModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark font-monospace" id="binCode">—</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <dl class="row mb-0 small">
                    <dt class="col-4 text-secondary fw-semibold">Gudang</dt>
                    <dd class="col-8" id="binWarehouse">—</dd>

                    <dt class="col-4 text-secondary fw-semibold">Posisi</dt>
                    <dd class="col-8" id="binPosition">—</dd>

                    <dt class="col-4 text-secondary fw-semibold">Zona</dt>
                    <dd class="col-8" id="binZone">—</dd>

                    <dt class="col-4 text-secondary fw-semibold">Status</dt>
                    <dd class="col-8" id="binStatus">—</dd>
                </dl>

                {{-- Bagian isi bin sengaja ditampilkan sebagai keterangan jujur,
                     bukan angka kosong yang menyesatkan. Diganti data sungguhan
                     pada Fase 4 saat inventory_stocks dibangun. --}}
                <div class="alert alert-light border small mt-4 mb-0">
                    <i class="bi bi-info-circle text-primary me-1"></i>
                    <strong>Isi bin belum tersedia.</strong> Data stok per bin (produk, batch,
                    jumlah, kedaluwarsa) dan koreksi opname akan muncul di sini setelah
                    modul Inventory dibangun.
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Tutup</button>
                <form id="binStatusForm" method="POST" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn px-4 fw-bold shadow-sm" id="binToggleBtn"></button>
                </form>
            </div>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    const LOCATION_BASE_URL = @json(url('/wms/master/locations'));
    const WAREHOUSE_LABEL = @json($warehouse?->display_label);

    const pad2 = (n) => String(n).padStart(2, '0');

    /**
     * Menyusun detail bin dari atribut induknya.
     *
     * Rak & zona diambil dari kartu rak, level dari baris level, sehingga tiap
     * kotak bin cukup menyimpan id, nomor sel, dan status saja.
     */
    function readBin(el) {
        const rackCard = el.closest('.js-rack');
        const levelRow = el.closest('.js-level');
        const rack = rackCard.dataset.rack;
        const level = parseInt(levelRow.dataset.level, 10);
        const cell = parseInt(el.dataset.cell, 10);

        return {
            id: el.dataset.id,
            rack: rack,
            level: level,
            cell: cell,
            code: rack + '-' + pad2(level) + '-' + pad2(cell),
            zone: rackCard.dataset.zone || null,
            isActive: el.dataset.inactive !== '1',
        };
    }

    function showBin(bin) {
        document.getElementById('binCode').textContent = bin.code;
        document.getElementById('binWarehouse').textContent = WAREHOUSE_LABEL ?? '—';
        document.getElementById('binPosition').textContent =
            'Rak ' + bin.rack + ' · Level ' + bin.level + ' · Sel ' + bin.cell;
        document.getElementById('binZone').innerHTML = bin.zone
            ? '<span class="badge bg-light text-dark border">' + bin.zone + '</span>'
            : '<span class="text-muted">—</span>';
        document.getElementById('binStatus').innerHTML = bin.isActive
            ? '<span class="badge bg-success-subtle text-success-emphasis border border-success">Aktif</span>'
            : '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger">Non-aktif</span>';

        document.getElementById('binStatusForm').action =
            LOCATION_BASE_URL + '/' + bin.id + '/status';

        const btn = document.getElementById('binToggleBtn');
        btn.className = 'btn px-4 fw-bold shadow-sm ' + (bin.isActive ? 'btn-warning' : 'btn-success');
        btn.innerHTML = '<i class="bi bi-toggle-' + (bin.isActive ? 'on' : 'off') + ' me-1"></i> ' +
            (bin.isActive ? 'Nonaktifkan Bin' : 'Aktifkan Bin');

        new bootstrap.Modal(document.getElementById('binModal')).show();
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Satu pendengar untuk seluruh bin, bukan satu handler per kotak —
        // dengan 2.264 kotak, memasang listener satu per satu memperlambat
        // pemuatan halaman tanpa manfaat.
        document.addEventListener('click', function (e) {
            const bin = e.target.closest('.bin[data-id]');
            if (bin) {
                showBin(readBin(bin));
            }
        });

        // Gulirkan ke bin hasil pencarian agar tidak perlu dicari manual.
        const firstMatch = document.querySelector('.bin-highlight');
        if (firstMatch) {
            firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
</script>
@endpush
