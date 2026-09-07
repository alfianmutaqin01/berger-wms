@extends('layouts.wms')

@section('title', 'Denah Gudang')
@section('page_title', 'Denah Gudang')

@push('styles')
<style>
    /* Satu kotak = satu rak. Ukurannya sengaja kecil agar satu rak penuh
       (hingga 21 sel) muat dalam satu baris tanpa menggulir menyamping. */
    .rak {
        width: 42px;
        height: 34px;
        border-radius: 5px;
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        line-height: 1;
        font-size: 0.7rem;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid transparent;
        transition: transform 0.12s ease, box-shadow 0.12s ease;
        user-select: none;
    }
    .rak-cell { font-size: 0.68rem; }

    /* Jumlah unitnya kecil tetapi tetap terbaca; ia yang membedakan rak penuh
       dari rak kosong pada pandangan pertama. */
    .rak-qty {
        font-size: 0.56rem;
        font-weight: 700;
        opacity: 0.85;
        margin-top: 1px;
    }

    /* Rak yang ADA ISINYA. Warna zonanya tetap dipakai sebagai dasar — zona
       masih perlu terbaca — dan keterisian ditandai bingkai tegas plus latar
       yang lebih pekat, sehingga dua informasi itu tidak saling menutupi. */
    .rak-terisi {
        border-width: 2px;
        border-color: #0f5132;
        box-shadow: inset 0 -3px 0 rgba(15, 81, 50, 0.35);
    }
    .rak:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.18);
        z-index: 2;
    }
    .rak-zone-fast   { background: #d1e7dd; color: #0a3622; border-color: #a3cfbb; }
    .rak-zone-slow   { background: #e2e3e5; color: #2b2f32; border-color: #c4c8cb; }
    .rak-zone-middle { background: #cff4fc; color: #055160; border-color: #9eeaf9; }

    /* Rak non-aktif: dicoret agar jelas tidak boleh dipakai put-away. */
    .rak-inactive {
        background: #f8d7da !important;
        color: #842029 !important;
        border-color: #f1aeb5 !important;
        text-decoration: line-through;
        opacity: 0.75;
    }

    /* Hasil pencarian ditonjolkan agar mudah ditemukan di antara ribuan rak. */
    .rak-highlight {
        outline: 3px solid #1B4F8A;
        outline-offset: 1px;
        animation: rakPulse 1.2s ease-in-out 3;
    }
    @keyframes rakPulse {
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
{{-- ISTILAH DI HALAMAN INI, sengaja dibedakan supaya angkanya tidak
     membingungkan: RAK adalah satu kotak beralamat seperti B-01-08 — itulah
     yang disebut "rak" di layar picking dan yang ditulis operator. DERET
     adalah kumpulan rak yang berdiri dalam satu badan rak (B-01), memanjang
     ke atas menurut level. Tanpa dua kata itu, "Total Rak 2.264" dan "Jumlah
     Rak 29" berdiri bersebelahan tanpa ada yang tahu bedanya. --}}
<div class="row g-3 mb-4">
    @php
        $kartu = [
            ['Total Rak', number_format($stats['total']), 'dark', null],
            ['Rak Terisi', number_format($stats['terisi']), 'primary', number_format($stats['qty']).' unit'],
            ['Aktif', number_format($stats['active']), 'success', null],
            ['Non-aktif', number_format($stats['inactive']), 'danger', null],
            ['Jumlah Deret', count($racks), 'secondary', null],
        ];
    @endphp
    @foreach($kartu as [$judul, $nilai, $warna, $catatan])
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-{{ $warna }} border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">{{ $judul }}</h6>
                    <h3 class="mb-0 fw-bold text-{{ $warna }}">{{ $nilai }}</h3>
                    @if($catatan)
                        <small class="text-muted">{{ $catatan }}</small>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-map text-primary me-2"></i> Denah Gudang {{ $warehouse?->code }}</h5>
            <p class="text-muted small mt-1 mb-0">
                Tiap kotak adalah satu rak beralamat, mis. <span class="font-monospace">B-01-08</span>.
                Angka kecil di bawah nomornya adalah jumlah unit yang ada di sana.
                Level 5 di atas, Level 1 di bawah — sesuai letak fisiknya.
                Klik kotak untuk melihat isinya.
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
                    <input type="text" name="highlight" value="{{ $filters['highlight'] }}" class="form-control bg-white border-start-0 font-monospace text-uppercase" placeholder="Lacak rak, contoh: B-01-01">
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
            <span><span class="rak rak-zone-fast d-inline-flex align-middle" style="width:26px;height:20px;"></span> Fast Moving</span>
            <span><span class="rak rak-zone-slow d-inline-flex align-middle" style="width:26px;height:20px;"></span> Slow Moving</span>
            <span><span class="rak rak-zone-middle d-inline-flex align-middle" style="width:26px;height:20px;"></span> Middle Moving</span>
            <span><span class="rak rak-inactive d-inline-flex align-middle" style="width:26px;height:20px;"></span> Non-aktif</span>
            <span><span class="rak rak-zone-middle rak-terisi d-inline-flex align-middle" style="width:26px;height:20px;"></span> Ada isinya</span>
        </div>
    </div>
</div>

{{-- Pintasan lompat ke rak tertentu — dengan 29 rak, menggulir manual melelahkan. --}}
@if(count($racks) > 1)
<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body px-4 py-3 d-flex flex-wrap align-items-center gap-2">
        <span class="small fw-semibold text-muted me-1">Lompat ke deret:</span>
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
                    <i class="bi bi-bookshelf text-primary me-2"></i>Deret {{ $rack }}
                </h6>
                <div class="d-flex align-items-center gap-2">
                    @if($meta['inactive'] > 0)
                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger">{{ $meta['inactive'] }} non-aktif</span>
                    @endif
                    @if($meta['terisi'] > 0)
                        <span class="badge bg-success-subtle text-success-emphasis border border-success">
                            {{ $meta['terisi'] }} terisi &middot; {{ number_format($meta['qty']) }} unit
                        </span>
                    @endif
                    <span class="badge bg-light text-dark border">{{ $meta['total'] }} rak</span>
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
            {{-- Tiap rak hanya membawa id, nomor sel, dan status. Kode, rak, dan
                 zona disusun ulang di sisi JavaScript dari atribut kartu rak dan
                 baris level. Menyertakan payload lengkap di tiap kotak membuat
                 halaman membengkak lebih dari dua kali lipat pada gudang berisi
                 2.264 rak. --}}
            @foreach($levels->sortKeysDesc() as $level => $titikRak)
                <div class="d-flex align-items-center mb-1 js-level" data-level="{{ $level }}">
                    <div class="level-label">L{{ $level }}</div>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach($titikRak as $titik)
                            @php
                                $zoneClass = match($titik->zone) {
                                    \App\Models\Location::ZONE_FAST => 'rak-zone-fast',
                                    \App\Models\Location::ZONE_SLOW => 'rak-zone-slow',
                                    default => 'rak-zone-middle',
                                };
                                $isHighlighted = $filters['highlight'] !== ''
                                    && str_contains($titik->code, strtoupper($filters['highlight']));
                                $muatan = $isi[$titik->id] ?? null;
                            @endphp
                            <div class="rak {{ $titik->is_active ? $zoneClass : 'rak-inactive' }} {{ $muatan ? 'rak-terisi' : '' }} {{ $isHighlighted ? 'rak-highlight' : '' }}"
                                 data-id="{{ $titik->id }}"
                                 data-cell="{{ $titik->cell }}"
                                 @if(! $titik->is_active) data-inactive="1" @endif
                                 @if($muatan) data-qty="{{ $muatan['qty'] }}" data-sku="{{ $muatan['sku'] }}" @endif
                                 title="{{ $titik->code }}{{ $titik->is_active ? '' : ' (non-aktif)' }}{{ $muatan ? ' — '.number_format($muatan['qty']).' unit, '.$muatan['sku'].' SKU' : ' — kosong' }}">
                                {{-- Nomor sel di atas, jumlah unitnya di bawah.
                                     Angka isi ditulis langsung di kotaknya, bukan
                                     hanya di tooltip: yang dicari orang saat
                                     membuka denah adalah rak mana yang penuh dan
                                     mana yang kosong, dan itu harus terbaca
                                     tanpa menyentuh apa pun. --}}
                                <span class="rak-cell">{{ str_pad($titik->cell, 2, '0', STR_PAD_LEFT) }}</span>
                                @if($muatan)
                                    <span class="rak-qty">{{ number_format($muatan['qty']) }}</span>
                                @endif
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
<div class="modal fade" id="rakModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark font-monospace" id="rakCode">—</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <dl class="row mb-0 small">
                    <dt class="col-4 text-secondary fw-semibold">Gudang</dt>
                    <dd class="col-8" id="rakWarehouse">—</dd>

                    <dt class="col-4 text-secondary fw-semibold">Posisi</dt>
                    <dd class="col-8" id="rakPosition">—</dd>

                    <dt class="col-4 text-secondary fw-semibold">Zona</dt>
                    <dd class="col-8" id="rakZone">—</dd>

                    <dt class="col-4 text-secondary fw-semibold">Status</dt>
                    <dd class="col-8" id="rakStatus">—</dd>
                </dl>

                {{-- Bagian isi rak sengaja ditampilkan sebagai keterangan jujur,
                     bukan angka kosong yang menyesatkan. Diganti data sungguhan
                     pada Fase 4 saat inventory_stocks dibangun. --}}
                {{-- Isi rak, diambil saat kotaknya diklik. Tiga keadaan yang
                     BERBEDA dan sengaja tidak diringkas jadi satu: sedang
                     dimuat, kosong, dan gagal dimuat. Rak kosong yang terbaca
                     sama seperti rak yang datanya gagal diambil adalah cara
                     tercepat membuat orang berhenti mempercayai layar ini. --}}
                <hr class="my-4">
                <h6 class="fw-bold text-dark mb-2">
                    <i class="bi bi-box-seam text-primary me-1"></i> Isi Rak
                    <span class="badge bg-light text-dark border ms-1 d-none" id="rakTotal"></span>
                </h6>
                <div id="rakIsi" class="small text-muted">Memuat…</div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Tutup</button>
                <form id="rakStatusForm" method="POST" class="d-inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn px-4 fw-bold shadow-sm" id="rakToggleBtn"></button>
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
     * Menyusun detail rak dari atribut induknya.
     *
     * Rak & zona diambil dari kartu rak, level dari baris level, sehingga tiap
     * kotak rak cukup menyimpan id, nomor sel, dan status saja.
     */
    function readRak(el) {
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

    function showRak(rak) {
        document.getElementById('rakCode').textContent = rak.code;
        document.getElementById('rakWarehouse').textContent = WAREHOUSE_LABEL ?? '—';
        document.getElementById('rakPosition').textContent =
            'Rak ' + rak.rack + ' · Level ' + rak.level + ' · Sel ' + rak.cell;
        document.getElementById('rakZone').innerHTML = rak.zone
            ? '<span class="badge bg-light text-dark border">' + rak.zone + '</span>'
            : '<span class="text-muted">—</span>';
        document.getElementById('rakStatus').innerHTML = rak.isActive
            ? '<span class="badge bg-success-subtle text-success-emphasis border border-success">Aktif</span>'
            : '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger">Non-aktif</span>';

        document.getElementById('rakStatusForm').action =
            LOCATION_BASE_URL + '/' + rak.id + '/status';

        const btn = document.getElementById('rakToggleBtn');
        btn.className = 'btn px-4 fw-bold shadow-sm ' + (rak.isActive ? 'btn-warning' : 'btn-success');
        btn.innerHTML = '<i class="bi bi-toggle-' + (rak.isActive ? 'on' : 'off') + ' me-1"></i> ' +
            (rak.isActive ? 'Nonaktifkan Rak' : 'Aktifkan Rak');

        muatIsi(rak.id);

        new bootstrap.Modal(document.getElementById('rakModal')).show();
    }

    const lolos = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);

    const WARNA_STATUS = {
        active: 'success',
        quarantine: 'warning',
        ddp: 'danger',
        expired: 'secondary',
    };

    /** Mengambil isi satu rak dan menggambarnya di modal. */
    function muatIsi(id) {
        const kotak = document.getElementById('rakIsi');
        const total = document.getElementById('rakTotal');

        kotak.innerHTML = '<span class="text-muted">Memuat…</span>';
        total.classList.add('d-none');

        fetch(LOCATION_BASE_URL + '/' + id + '/contents', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => {
                if (! r.ok) {
                    throw new Error(r.status);
                }

                return r.json();
            })
            .then((data) => {
                if (! data.baris.length) {
                    // Kosong dikatakan apa adanya. Tabel kosong tanpa kalimat
                    // terbaca seperti data yang gagal dimuat.
                    kotak.innerHTML = '<div class="alert alert-light border small mb-0">'
                        + '<i class="bi bi-inbox me-1"></i> Rak ini kosong.</div>';

                    return;
                }

                total.textContent = data.total.toLocaleString('id-ID') + ' unit';
                total.classList.remove('d-none');

                kotak.innerHTML = '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
                    + '<thead class="table-light"><tr>'
                    + '<th>Produk</th><th>Batch</th><th class="text-end">Tersedia</th>'
                    + '<th class="text-end">Teralokasi</th><th>Status</th>'
                    + '</tr></thead><tbody>'
                    + data.baris.map((b) => '<tr>'
                        + '<td><div class="fw-semibold font-monospace small">' + lolos(b.sku) + '</div>'
                            + '<div class="text-muted" style="font-size:.72rem">' + lolos(b.nama) + '</div>'
                            + (b.masalah_kualitas
                                ? '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger">Masalah Kualitas</span>'
                                : '')
                        + '</td>'
                        + '<td><div class="font-monospace small">' + lolos(b.batch ?? '—') + '</div>'
                            + '<div class="text-muted" style="font-size:.72rem">exp ' + lolos(b.kedaluwarsa ?? '—') + '</div>'
                        + '</td>'
                        + '<td class="text-end fw-semibold">' + b.tersedia.toLocaleString('id-ID') + '</td>'
                        + '<td class="text-end text-muted">' + b.teralokasi.toLocaleString('id-ID') + '</td>'
                        + '<td><span class="badge bg-' + (WARNA_STATUS[b.status] ?? 'secondary')
                            + '-subtle text-' + (WARNA_STATUS[b.status] ?? 'secondary') + '-emphasis">'
                            + lolos(b.status_label) + '</span></td>'
                        + '</tr>').join('')
                    + '</tbody></table></div>';
            })
            .catch(() => {
                kotak.innerHTML = '<div class="alert alert-danger small mb-0">'
                    + '<i class="bi bi-exclamation-triangle me-1"></i> Isi rak gagal dimuat. '
                    + 'Ini BUKAN berarti raknya kosong — coba buka lagi.</div>';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Satu pendengar untuk seluruh rak, bukan satu handler per kotak —
        // dengan 2.264 kotak, memasang listener satu per satu memperlambat
        // pemuatan halaman tanpa manfaat.
        document.addEventListener('click', function (e) {
            const rak = e.target.closest('.rak[data-id]');
            if (rak) {
                showRak(readRak(rak));
            }
        });

        // Gulirkan ke rak hasil pencarian agar tidak perlu dicari manual.
        const firstMatch = document.querySelector('.rak-highlight');
        if (firstMatch) {
            firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
</script>
@endpush
