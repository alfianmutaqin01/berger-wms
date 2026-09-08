@extends('layouts.wms')
@section('title', 'Area Kerja Operator')

{{--
    DASHBOARD OPERATOR — daftar pekerjaan, bukan laporan.

    Operator berdiri di depan rak sambil memegang telepon. Yang berguna
    baginya adalah pekerjaan yang bisa langsung ditekan, bukan angka untuk
    direnungkan — karena itu tiap kartu di sini punya tombol, dan yang tidak
    ada pekerjaannya tidak digambar sama sekali.

    Alasan pemilihan isinya ada di App\Support\Reporting\OperatorDashboard.
--}}

@push('styles')
<style>
    .dashboard-hero-card {
        background: linear-gradient(135deg, #0d2540 0%, #123962 60%, #1a4f85 100%);
        border-radius: 1.25rem;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px -8px rgba(13, 37, 64, .25);
    }
    .dashboard-hero-card::before {
        content: '';
        position: absolute;
        top: -60px; right: -60px;
        width: 320px; height: 320px;
        background: radial-gradient(circle, rgba(232, 135, 30, .22) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .section-header-tag {
        font-size: .72rem;
        letter-spacing: .08em;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
    }

    .stat-card {
        background: #fff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, .85);
        box-shadow: 0 2px 8px rgba(18, 57, 98, .04);
        position: relative;
        overflow: hidden;
        transition: transform .22s cubic-bezier(.16, 1, .3, 1), box-shadow .22s ease;
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 14px 28px -6px rgba(18, 57, 98, .12);
    }
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: transparent;
    }

    .stat-card-primary::before { background: linear-gradient(90deg, #123962, #1e5692); }
    .stat-card-info::before    { background: linear-gradient(90deg, #0284c7, #38bdf8); }
    .stat-card-purple::before  { background: linear-gradient(90deg, #7c3aed, #a78bfa); }

    .stat-icon-badge {
        width: 46px; height: 46px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-card-primary .stat-icon-badge { background: #e0e9f5; color: #123962; }
    .stat-card-info .stat-icon-badge    { background: #e0f2fe; color: #0369a1; }
    .stat-card-purple .stat-icon-badge  { background: #ede9fe; color: #6d28d9; }

    /* Tugas yang sedang dipegang: sengaja paling mencolok di layar. Yang
       paling merugikan bukan tugas yang belum diambil, melainkan tugas yang
       sudah diambil lalu terlupakan — pesanannya terkunci atas nama
       seseorang dan tidak ada yang bisa melanjutkan. */
    .tugas-saya-card {
        background: linear-gradient(135deg, #123962 0%, #1a4f85 100%);
        border-radius: 1.15rem;
        color: #fff;
        box-shadow: 0 10px 26px -8px rgba(18, 57, 98, .4);
    }

    .panel-card {
        background: #fff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, .9);
        box-shadow: 0 2px 8px rgba(18, 57, 98, .04);
    }
</style>
@endpush

@section('content')
{{-- ------------------------------------------------------------- HERO --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="dashboard-hero-card p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <span class="text-uppercase small fw-bold opacity-75" style="letter-spacing:.06em;">
                        Area Kerja Operator Gudang
                    </span>
                    <h3 class="fw-bold mb-1 mt-2 text-white">Pekerjaan Anda Hari Ini</h3>
                    <p class="text-white-50 mb-0 small">
                        Hanya yang menuntut tindakan yang ditampilkan di sini.
                    </p>
                </div>
                <span class="badge bg-white text-dark rounded-pill px-3 py-2 shadow-sm fw-medium">
                    <i class="bi bi-building me-1 text-primary"></i>
                    {{ $gudang ?? 'Seluruh gudang' }}
                </span>
            </div>
        </div>
    </div>
</div>

{{-- ------------------------------------------- TUGAS YANG SEDANG DIPEGANG.
     Hanya digambar kalau memang ada. --}}
@if($m['tugas_saya']->isNotEmpty())
    <div class="d-flex align-items-center gap-2 mb-3">
        <div class="p-1 rounded-2 bg-primary-subtle text-primary"><i class="bi bi-person-check fs-6"></i></div>
        <h6 class="section-header-tag mb-0">Sedang Anda Kerjakan</h6>
    </div>

    <div class="row g-3 mb-4">
        @foreach($m['tugas_saya'] as $tugas)
            <div class="col-12 col-lg-6">
                <div class="tugas-saya-card p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <h5 class="fw-bold mb-0 text-white">{{ $tugas->list_number }}</h5>
                                <small class="text-white-50">
                                    Diambil {{ $tugas->claimed_at?->diffForHumans() }}
                                </small>
                            </div>
                            <span class="badge bg-white text-primary rounded-pill px-3">
                                {{ $tugas->items_count - $tugas->items_pending_count }}/{{ $tugas->items_count }} baris
                            </span>
                        </div>
                        <div class="progress bg-white bg-opacity-25 mt-3" style="height:6px;">
                            <div class="progress-bar bg-warning"
                                 style="width: {{ $tugas->items_count > 0 ? round(($tugas->items_count - $tugas->items_pending_count) / $tugas->items_count * 100) : 0 }}%"></div>
                        </div>
                    </div>
                    <a href="{{ route('wms.picking.show', $tugas) }}"
                       class="btn btn-light fw-semibold rounded-pill mt-4 w-100">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Lanjutkan Picking
                    </a>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- --------------------------------------------------------- ANTREAN TETAP.
     Dua kartu ini SELALU tampil walau isinya nol — di sini nol adalah kabar
     yang berguna: berarti tidak ada yang menunggu dikerjakan. --}}
<div class="d-flex align-items-center gap-2 mb-3">
    <div class="p-1 rounded-2 bg-info-subtle text-info"><i class="bi bi-list-task fs-6"></i></div>
    <h6 class="section-header-tag mb-0">Antrean Gudang</h6>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 {{ $m['stocktake'] ? 'col-xl-4' : '' }}">
        <div class="card h-100 stat-card stat-card-info">
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div>
                    <div class="stat-icon-badge mb-3"><i class="bi bi-box-arrow-in-down"></i></div>
                    <h6 class="text-muted fw-normal mb-1">Tugas Put-away</h6>
                    <div class="d-flex align-items-baseline gap-2">
                        <h2 class="mb-0 fw-bold text-dark">{{ $m['putaway']['dokumen'] }}</h2>
                        <span class="text-muted small">dokumen &middot; {{ $m['putaway']['palet'] }} palet</span>
                    </div>
                </div>
                <a href="{{ route('wms.inbound.putaway') }}"
                   class="btn btn-sm rounded-pill mt-3 {{ $m['putaway']['dokumen'] > 0 ? 'btn-info text-white' : 'btn-outline-secondary' }}">
                    {{ $m['putaway']['dokumen'] > 0 ? 'Kerjakan Sekarang' : 'Tidak ada yang menunggu' }}
                </a>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 {{ $m['stocktake'] ? 'col-xl-4' : '' }}">
        <div class="card h-100 stat-card stat-card-primary">
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div>
                    <div class="stat-icon-badge mb-3"><i class="bi bi-clipboard2-check"></i></div>
                    <h6 class="text-muted fw-normal mb-1">Antrean Picking Tersedia</h6>
                    <div class="d-flex align-items-baseline gap-2">
                        <h2 class="mb-0 fw-bold text-dark">{{ $m['picking_tersedia']['jumlah'] }}</h2>
                        <span class="text-muted small">belum diambil siapa pun</span>
                    </div>
                </div>
                <a href="{{ route('wms.picking.queue') }}"
                   class="btn btn-sm rounded-pill mt-3 {{ $m['picking_tersedia']['jumlah'] > 0 ? 'btn-primary' : 'btn-outline-secondary' }}">
                    {{ $m['picking_tersedia']['jumlah'] > 0 ? 'Ambil Tugas' : 'Antrean kosong' }}
                </a>
            </div>
        </div>
    </div>

    {{-- STOCKTAKE hanya muncul kalau sesinya sedang berjalan. Bukan pekerjaan
         harian: menampilkan "0 sesi" sepanjang tahun membuat orang berhenti
         membacanya, justru pada minggu ia benar-benar berisi. --}}
    @if($m['stocktake'])
        <div class="col-12 col-xl-4">
            <div class="card h-100 stat-card stat-card-purple">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="stat-icon-badge mb-3"><i class="bi bi-ui-checks"></i></div>
                        <h6 class="text-muted fw-normal mb-1">Stocktake Berjalan</h6>
                        <h5 class="mb-0 fw-bold text-dark">{{ $m['stocktake']->reference }}</h5>
                        <small class="text-muted">
                            Dibuka {{ $m['stocktake']->opened_at?->diffForHumans() }}
                        </small>
                    </div>
                    <a href="{{ route('wms.stocktake.show', $m['stocktake']) }}"
                       class="btn btn-sm rounded-pill mt-3" style="background:#7c3aed;color:#fff;">
                        <i class="bi bi-pencil-square me-1"></i> Masukkan Hitungan
                    </a>
                </div>
            </div>
        </div>
    @endif
</div>

{{-- ------------------------------------------------- RIWAYAT TUGAS SENDIRI.
     Menjawab "tadi saya sudah mengerjakan yang mana" — pertanyaan yang muncul
     tiap kali seseorang kembali dari istirahat. --}}
<div class="card panel-card">
    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
        <h6 class="fw-bold mb-0"><i class="bi bi-check2-square me-2 text-success"></i>Tugas Terakhir yang Anda Selesaikan</h6>
    </div>
    <div class="card-body px-4 pb-4 pt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small">
                    <tr>
                        <th>NO. DAFTAR</th>
                        <th class="text-center">BARIS</th>
                        <th>SELESAI</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($m['riwayat'] as $selesai)
                        <tr>
                            <td class="fw-semibold">{{ $selesai->list_number }}</td>
                            <td class="text-center">{{ $selesai->items_count }}</td>
                            <td class="small text-muted">{{ $selesai->completed_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <a href="{{ route('wms.picking.show', $selesai) }}"
                                   class="btn btn-sm btn-outline-secondary rounded-pill">Lihat</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-5">
                                Belum ada tugas picking yang Anda selesaikan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
