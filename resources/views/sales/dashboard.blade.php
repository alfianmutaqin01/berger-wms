@extends('layouts.soms')

@section('title', 'Dashboard Sales')
@section('page_title', 'Pekerjaan & Pesanan Anda')

{{--
    DASHBOARD SALES — disusun menurut siapa yang harus bergerak berikutnya.

    Versi lama menyusun angka menurut status pesanan seolah keempatnya
    setara. Bagi Sales keempatnya sangat tidak setara: draft, pesanan
    ditolak, dan bukti kirim MACET DI TANGANNYA SENDIRI; sisanya menunggu
    gudang. Yang pertama harus dikerjakan hari ini juga.

    Dua hal dari versi lama sengaja dibuang seluruhnya:
      - Grafik "Target vs Realisasi" dengan garis target 700/minggu yang
        ditulis tangan di JavaScript. Tidak ada tabel target di sistem ini.
      - Tombol "Upload Bukti" yang membuka jendela unggah PALSU: ia
        menampilkan "Berhasil! Bukti pengiriman berhasil diunggah" tanpa
        mengirim apa pun ke mana pun. Unggahan sungguhan ada di halaman
        detail pesanan, dan di sini kita hanya menunjuk ke sana.

    Alasan lengkapnya di App\Support\Reporting\SalesDashboard.
--}}

@section('content')
<style>
    .sales-hero {
        background: linear-gradient(135deg, #123962 0%, #1B4F8A 100%);
        border-radius: 1.15rem;
        color: #fff;
        position: relative;
        overflow: hidden;
    }
    .sales-hero::before {
        content: '';
        position: absolute;
        top: -70px; right: -50px;
        width: 260px; height: 260px;
        background: radial-gradient(circle, rgba(232, 135, 30, .25) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
    }

    .section-tag {
        font-size: .72rem;
        letter-spacing: .08em;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
    }

    .kartu {
        background: #fff;
        border-radius: 1.1rem;
        border: 1px solid rgba(226, 232, 240, .9);
        box-shadow: 0 2px 8px rgba(18, 57, 98, .04);
        transition: transform .2s ease, box-shadow .2s ease;
        position: relative;
        overflow: hidden;
        height: 100%;
    }
    a.kartu-link { text-decoration: none; color: inherit; display: block; height: 100%; }
    a.kartu-link:hover .kartu {
        transform: translateY(-3px);
        box-shadow: 0 12px 24px -6px rgba(18, 57, 98, .13);
    }
    .kartu::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: transparent;
    }
    .kartu-danger::before  { background: linear-gradient(90deg, #dc2626, #f87171); }
    .kartu-warning::before { background: linear-gradient(90deg, #E8871E, #fbbf24); }
    .kartu-primary::before { background: linear-gradient(90deg, #1B4F8A, #3b82f6); }
    .kartu-info::before    { background: linear-gradient(90deg, #0284c7, #38bdf8); }
    .kartu-success::before { background: linear-gradient(90deg, #059669, #34d399); }

    .ikon {
        width: 44px; height: 44px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem;
    }
    .kartu-danger .ikon  { background: #fee2e2; color: #b91c1c; }
    .kartu-warning .ikon { background: #fef3c7; color: #b45309; }
    .kartu-primary .ikon { background: #e0e9f5; color: #1B4F8A; }
    .kartu-info .ikon    { background: #e0f2fe; color: #0369a1; }
    .kartu-success .ikon { background: #d1fae5; color: #047857; }
</style>

{{-- ------------------------------------------------------------- HERO --}}
<div class="sales-hero p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-uppercase small fw-bold opacity-75" style="letter-spacing:.06em;">
                Portal Sales
            </span>
            <h4 class="fw-bold mb-1 mt-2 text-white">Pekerjaan &amp; Pesanan Anda</h4>
            <p class="text-white-50 mb-0 small">
                Hanya pesanan milik Anda sendiri yang ditampilkan di sini.
            </p>
        </div>
        <div class="text-lg-end">
            {{-- Batas jam submit. Inilah yang mengubah "ada 3 draft" dari
                 catatan kecil menjadi hal yang mendesak. --}}
            @if($cutoffOpen)
                <span class="badge bg-white text-success rounded-pill px-3 py-2 fw-semibold">
                    <i class="bi bi-unlock me-1"></i> Masih bisa submit sampai {{ $cutoffLabel }}
                </span>
            @else
                <span class="badge bg-warning text-dark rounded-pill px-3 py-2 fw-semibold">
                    <i class="bi bi-lock me-1"></i> Lewat {{ $cutoffLabel }} &mdash; masuk antrean besok
                </span>
            @endif
            <div class="mt-2">
                <a href="/sales/new-order" class="btn btn-light btn-sm rounded-pill px-3 fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i> Pesanan Baru
                </a>
            </div>
        </div>
    </div>
</div>

{{-- ========================================== BUTUH TINDAKAN ANDA.
     Bagian ini hanya digambar kalau memang ada yang tertahan. Tiga kotak
     kosong bertuliskan nol bukan kabar baik — ia hanya membuat yang benar
     benar mendesak tenggelam saat suatu hari muncul. --}}
@if($m['perlu_tindakan']['total'] > 0)
    <div class="d-flex align-items-center gap-2 mb-3">
        <div class="p-1 rounded-2 bg-danger-subtle text-danger"><i class="bi bi-exclamation-circle fs-6"></i></div>
        <h6 class="section-tag mb-0">Butuh Tindakan Anda</h6>
    </div>

    <div class="row g-3 mb-4">
        @if($m['perlu_tindakan']['draft'] > 0)
            <div class="col-12 col-md-4">
                <a href="/sales/my-orders?status=draft" class="kartu-link">
                    <div class="kartu kartu-danger p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="ikon mb-3"><i class="bi bi-pencil-square"></i></div>
                            <h6 class="text-muted fw-normal mb-1">Draft Belum Dikirim</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-danger">{{ $m['perlu_tindakan']['draft'] }}</h2>
                                <span class="text-muted small">pesanan</span>
                            </div>
                        </div>
                        {{-- Kalimat ini yang penting, bukan angkanya: draft
                             tidak sedang mengantre, ia tidak ada. --}}
                        <p class="text-muted small mb-0 mt-3 pt-2 border-top">
                            Belum terlihat oleh Logistik sama sekali.
                            @unless($cutoffOpen)
                                <span class="text-danger fw-semibold">Batas hari ini sudah lewat.</span>
                            @endunless
                        </p>
                    </div>
                </a>
            </div>
        @endif

        @if($m['perlu_tindakan']['ditolak'] > 0)
            <div class="col-12 col-md-4">
                <a href="/sales/my-orders?status=rejected" class="kartu-link">
                    <div class="kartu kartu-warning p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="ikon mb-3"><i class="bi bi-arrow-counterclockwise"></i></div>
                            <h6 class="text-muted fw-normal mb-1">Ditolak</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-warning">{{ $m['perlu_tindakan']['ditolak'] }}</h2>
                                <span class="text-muted small">pesanan</span>
                            </div>
                        </div>
                        <p class="text-muted small mb-0 mt-3 pt-2 border-top">
                            Masih bisa diperbaiki lalu diajukan ulang.
                        </p>
                    </div>
                </a>
            </div>
        @endif

        @if($m['perlu_tindakan']['bukti'] > 0)
            <div class="col-12 col-md-4">
                <div class="kartu kartu-primary p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="ikon mb-3"><i class="bi bi-camera"></i></div>
                        <h6 class="text-muted fw-normal mb-1">Menunggu Foto Bukti</h6>
                        <div class="d-flex align-items-baseline gap-2">
                            <h2 class="mb-0 fw-bold text-primary">{{ $m['perlu_tindakan']['bukti'] }}</h2>
                            <span class="text-muted small">pesanan</span>
                        </div>
                    </div>
                    <p class="text-muted small mb-0 mt-3 pt-2 border-top">
                        Barang sudah jalan. Pesanan belum dianggap selesai sampai buktinya masuk.
                    </p>
                </div>
            </div>
        @endif
    </div>

    {{-- Daftar yang menunggu bukti, dengan TAUTAN SUNGGUHAN ke halaman
         detail pesanan tempat formulir unggahnya menempel. --}}
    @if($m['daftar_bukti']->isNotEmpty())
        <div class="kartu p-0 mb-4">
            <div class="px-4 pt-4 pb-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-camera me-2 text-primary"></i>Unggah Bukti Kirim</h6>
                <a href="/sales/my-orders?status=shipping" class="btn btn-sm btn-link text-decoration-none">Semua</a>
            </div>
            <ul class="list-group list-group-flush">
                @foreach($m['daftar_bukti'] as $pesanan)
                    <li class="list-group-item px-4 py-3 d-flex justify-content-between align-items-center gap-3">
                        <div class="min-w-0">
                            <h6 class="mb-0 fw-bold text-truncate">{{ $pesanan->customer?->name ?? '—' }}</h6>
                            <small class="text-muted">
                                {{ $pesanan->order_number }}
                                @if($pesanan->shipped_at)
                                    &middot; berangkat {{ $pesanan->shipped_at->diffForHumans(short: true) }}
                                @endif
                            </small>
                        </div>
                        <a href="/sales/orders/{{ $pesanan->id }}"
                           class="btn btn-sm btn-primary rounded-pill px-3 flex-shrink-0">
                            <i class="bi bi-upload me-1"></i> Unggah
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Daftar draft: satu ketukan dari sini ke halaman yang bisa
         mengirimnya, karena itulah tindakan yang sedang ditagih. --}}
    @if($m['daftar_draft']->isNotEmpty())
        <div class="kartu p-0 mb-4">
            <div class="px-4 pt-4 pb-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-pencil-square me-2 text-danger"></i>Draft Menunggu Dikirim</h6>
                <a href="/sales/my-orders?status=draft" class="btn btn-sm btn-link text-decoration-none">Semua</a>
            </div>
            <ul class="list-group list-group-flush">
                @foreach($m['daftar_draft'] as $draft)
                    <li class="list-group-item px-4 py-3 d-flex justify-content-between align-items-center gap-3">
                        <div class="min-w-0">
                            <h6 class="mb-0 fw-bold text-truncate">{{ $draft->customer?->name ?? 'Tanpa customer' }}</h6>
                            <small class="text-muted">
                                {{ $draft->details_count }} item &middot;
                                diubah {{ $draft->updated_at?->diffForHumans(short: true) }}
                            </small>
                        </div>
                        <a href="/sales/orders/{{ $draft->id }}"
                           class="btn btn-sm btn-outline-danger rounded-pill px-3 flex-shrink-0">Buka</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@else
    {{-- Dikatakan sekali dengan tenang, bukan lewat tiga kotak nol. --}}
    <div class="kartu p-4 mb-4 d-flex align-items-center gap-3">
        <i class="bi bi-check-circle-fill text-success fs-3"></i>
        <div>
            <h6 class="fw-bold mb-0">Tidak ada yang menunggu tindakan Anda</h6>
            <small class="text-muted">
                Tidak ada draft yang belum dikirim, tidak ada pesanan ditolak, dan
                semua bukti kirim sudah diunggah.
            </small>
        </div>
    </div>
@endif

{{-- ================================================= PESANAN BERJALAN --}}
<div class="d-flex align-items-center gap-2 mb-3">
    <div class="p-1 rounded-2 bg-primary-subtle text-primary"><i class="bi bi-truck fs-6"></i></div>
    <h6 class="section-tag mb-0">Pesanan Anda di Gudang</h6>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders?status=pending" class="kartu-link">
            <div class="kartu kartu-warning p-3 p-lg-4">
                <div class="ikon mb-3"><i class="bi bi-hourglass-split"></i></div>
                <h6 class="text-muted fw-normal mb-1 small">Menunggu Diterima</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ $m['menunggu_gudang']['jumlah'] }}</h3>
                <small class="text-muted">
                    @if($m['menunggu_gudang']['tertua_hari'] !== null)
                        Terlama {{ $m['menunggu_gudang']['tertua_hari'] }} hari
                    @else
                        Tidak ada antrean
                    @endif
                </small>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders" class="kartu-link">
            <div class="kartu kartu-info p-3 p-lg-4">
                <div class="ikon mb-3"><i class="bi bi-box-seam"></i></div>
                <h6 class="text-muted fw-normal mb-1 small">Sedang Diproses</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ $m['berjalan']['jumlah'] }}</h3>
                <small class="text-muted">Disetujui sampai dalam pengiriman</small>
            </div>
        </a>
    </div>

    {{-- Outstanding ada di sini karena SALES yang ditelepon pelanggan saat
         barang tidak lengkap, bukan gudang. --}}
    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders" class="kartu-link">
            <div class="kartu kartu-danger p-3 p-lg-4">
                <div class="ikon mb-3"><i class="bi bi-exclamation-diamond"></i></div>
                <h6 class="text-muted fw-normal mb-1 small">Masih Kurang</h6>
                <h3 class="mb-0 fw-bold {{ $m['outstanding']['qty'] > 0 ? 'text-danger' : 'text-dark' }}">
                    {{ number_format($m['outstanding']['qty']) }}
                </h3>
                <small class="text-muted">
                    unit di {{ $m['outstanding']['pesanan'] }} pesanan
                </small>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a href="/sales/my-orders?status=completed" class="kartu-link">
            <div class="kartu kartu-success p-3 p-lg-4">
                <div class="ikon mb-3"><i class="bi bi-check2-circle"></i></div>
                <h6 class="text-muted fw-normal mb-1 small">Selesai Bulan Ini</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ $m['selesai_bulan_ini']['jumlah'] }}</h3>
                <small class="text-muted">Pesanan tuntas</small>
            </div>
        </a>
    </div>
</div>

{{-- ============================================================= GRAFIK --}}
<div class="kartu p-0">
    <div class="px-4 pt-4 pb-0">
        <h6 class="fw-bold mb-0">Pesanan Dibuat vs Selesai</h6>
        <small class="text-muted">{{ count($m['tren']['label']) }} bulan terakhir</small>
    </div>
    <div class="px-4 pb-4 pt-3" style="min-height: 280px;">
        <canvas id="grafikSales"></canvas>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    new Chart(document.getElementById('grafikSales'), {
        type: 'line',
        data: {
            labels: @json($m['tren']['label']),
            datasets: [
                {
                    label: 'Dibuat',
                    data: @json($m['tren']['dibuat']),
                    borderColor: '#1B4F8A',
                    backgroundColor: 'rgba(27,79,138,.10)',
                    borderWidth: 3,
                    fill: true,
                    tension: .35,
                },
                {
                    label: 'Selesai',
                    data: @json($m['tren']['selesai']),
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,.10)',
                    borderWidth: 3,
                    fill: true,
                    tension: .35,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            // Sumbu mulai dari nol, dan tanpa angka pecahan: yang dihitung
            // adalah banyaknya pesanan, dan "2,5 pesanan" tidak ada artinya.
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
</script>
@endpush
