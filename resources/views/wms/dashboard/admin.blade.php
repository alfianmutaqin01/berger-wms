@extends('layouts.wms')
@section('title', 'Dashboard WMS')

{{--
    DASHBOARD UTAMA — Super Admin, Manager, dan Logistik.

    KARTU DIPERIKSA LEWAT KEBERADAAN DATANYA, BUKAN @can.

    Tiap blok di bawah dibungkus @isset($m['...']). Kuncinya hanya ada kalau
    izin pemiliknya lolos di App\Support\Reporting\AdminDashboard — dan kalau
    tidak lolos, angkanya memang tidak pernah dihitung dan tidak pernah ikut
    terkirim ke halaman ini.

    Memakai @can di sini justru lebih lemah: datanya tetap dikirim, cuma
    kotaknya yang disembunyikan. Dan dua daftar izin yang harus sepakat
    (sini dan sana) suatu hari akan berbeda pendapat.
--}}

@section('content')
<div class="row mb-4">
    <div class="col-12 d-flex flex-wrap justify-content-between align-items-end gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-0">Dashboard</h4>
            <p class="text-muted mb-0">Keadaan gudang dan alur pesanan hari ini.</p>
        </div>
        {{-- Batas gudang dikatakan terang-terangan. Manager yang melihat
             angka kecil perlu tahu itu angka gudangnya, bukan angka
             perusahaan yang sedang sepi. --}}
        <span class="badge bg-light text-dark border rounded-pill px-3 py-2">
            <i class="bi bi-building me-1"></i>
            {{ $gudang ?? 'Seluruh gudang' }}
        </span>
    </div>
</div>

{{-- ============================================ ALUR HARIAN (semua peran) --}}
<div class="row g-3 mb-4">
    @isset($m['menunggu_diterima'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.approval.index') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-warning border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Butuh Diterima</h6>
                        <h3 class="mb-0 fw-bold text-warning">
                            {{ $m['menunggu_diterima']['jumlah'] }}
                            <span class="fs-6 fw-normal text-muted">pesanan</span>
                        </h3>
                        @if($m['menunggu_diterima']['tertua_hari'] !== null)
                            <small class="text-muted">
                                Terlama menunggu {{ $m['menunggu_diterima']['tertua_hari'] }} hari
                            </small>
                        @else
                            <small class="text-muted">Tidak ada antrean</small>
                        @endif
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['siap_dipicking'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.picking.batching') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-primary border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Siap Dipicking</h6>
                        <h3 class="mb-0 fw-bold text-primary">
                            {{ $m['siap_dipicking']['jumlah'] }}
                            <span class="fs-6 fw-normal text-muted">pesanan</span>
                        </h3>
                        <small class="text-muted">Belum masuk daftar picking</small>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['picking_berjalan'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.picking.queue') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-info border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Daftar Picking</h6>
                        <h3 class="mb-0 fw-bold text-info">
                            {{ $m['picking_berjalan']['dikerjakan'] }}
                            <span class="fs-6 fw-normal text-muted">dikerjakan</span>
                        </h3>
                        <small class="text-muted">
                            {{ $m['picking_berjalan']['terbuka'] }} menunggu diambil operator
                        </small>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['dalam_pengiriman'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.delivery.index') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-secondary border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Dalam Pengiriman</h6>
                        <h3 class="mb-0 fw-bold text-secondary">
                            {{ $m['dalam_pengiriman']['jumlah'] }}
                            <span class="fs-6 fw-normal text-muted">surat jalan</span>
                        </h3>
                        <small class="text-muted">Sudah berangkat, belum sampai</small>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['bukti_menunggu'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.verification.index') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-info border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Bukti Kirim</h6>
                        <h3 class="mb-0 fw-bold text-info">
                            {{ $m['bukti_menunggu']['jumlah'] }}
                            <span class="fs-6 fw-normal text-muted">menunggu</span>
                        </h3>
                        <small class="text-muted">Foto sudah diunggah, belum diverifikasi</small>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['inbound_menunggu'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.inbound.verify') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-warning border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Verifikasi Inbound</h6>
                        <h3 class="mb-0 fw-bold text-warning">
                            {{ $m['inbound_menunggu']['jumlah'] }}
                            <span class="fs-6 fw-normal text-muted">dokumen</span>
                        </h3>
                        <small class="text-muted">Barang di rak, stok belum resmi</small>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['outstanding'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.outstanding.index') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-danger border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Outstanding</h6>
                        <h3 class="mb-0 fw-bold text-danger">
                            {{ number_format($m['outstanding']['qty']) }}
                            <span class="fs-6 fw-normal text-muted">unit</span>
                        </h3>
                        <small class="text-muted">
                            Terutang di {{ $m['outstanding']['pesanan'] }} pesanan
                        </small>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['segera_kedaluwarsa'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.inventory.index') }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-danger border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Segera Kedaluwarsa</h6>
                        <h3 class="mb-0 fw-bold text-danger">
                            {{ $m['segera_kedaluwarsa']['batch'] }}
                            <span class="fs-6 fw-normal text-muted">batch</span>
                        </h3>
                        <small class="text-muted">
                            {{ number_format($m['segera_kedaluwarsa']['qty']) }} unit,
                            &le; {{ $m['segera_kedaluwarsa']['ambang'] }} hari lagi
                        </small>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    @isset($m['karantina'])
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('wms.inventory.index', ['status' => 'quarantine']) }}" class="text-decoration-none">
                <div class="card h-100 shadow-sm border-0 border-start border-warning border-4 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Karantina</h6>
                        <h3 class="mb-0 fw-bold text-warning">
                            {{ number_format($m['karantina']['qty']) }}
                            <span class="fs-6 fw-normal text-muted">unit ditahan</span>
                        </h3>
                        <small class="text-muted">
                            {{ $m['karantina']['batch'] }} batch &middot;
                            {{ $m['karantina']['lepas_pekan_ini'] }} lepas pekan ini
                        </small>
                    </div>
                </div>
            </a>
        </div>
    @endisset
</div>

{{-- ================================ PENGAWASAN — Manager & Super Admin saja.
     Logistik tidak melihat baris ini sama sekali: ini angka yang dipakai
     untuk MENILAI pekerjaan gudang. --}}
@if(isset($m['koreksi_stok']) || isset($m['stocktake']) || isset($m['pengguna']))
    <div class="d-flex align-items-center gap-2 mb-3 mt-1">
        <i class="bi bi-shield-lock text-muted"></i>
        <h6 class="fw-bold text-muted mb-0 text-uppercase small">Pengawasan</h6>
        <hr class="flex-grow-1 my-0 opacity-25">
    </div>

    <div class="row g-3 mb-4">
        @isset($m['koreksi_stok'])
            <div class="col-12 col-md-4">
                <div class="card h-100 shadow-sm border-0 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">
                            Koreksi Stok
                            <span class="text-muted small">&mdash; {{ $m['koreksi_stok']['hari'] }} hari terakhir</span>
                        </h6>
                        <h3 class="mb-0 fw-bold">
                            {{ $m['koreksi_stok']['jumlah'] }}
                            <span class="fs-6 fw-normal text-muted">kali</span>
                        </h3>
                        <small class="{{ $m['koreksi_stok']['neto'] < 0 ? 'text-danger' : 'text-muted' }}">
                            Neto {{ $m['koreksi_stok']['neto'] > 0 ? '+' : '' }}{{ number_format($m['koreksi_stok']['neto']) }} unit
                        </small>
                    </div>
                </div>
            </div>
        @endisset

        @isset($m['stocktake'])
            <div class="col-12 col-md-4">
                <div class="card h-100 shadow-sm border-0 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Stocktake</h6>
                        @if($m['stocktake']['terakhir'])
                            <h3 class="mb-0 fw-bold">
                                {{ number_format($m['stocktake']['terakhir']['selisih_qty']) }}
                                <span class="fs-6 fw-normal text-muted">unit selisih</span>
                            </h3>
                            <small class="text-muted">
                                {{ $m['stocktake']['terakhir']['selisih_baris'] }} baris meleset &middot;
                                {{ $m['stocktake']['terakhir']['referensi'] }},
                                {{ $m['stocktake']['terakhir']['tanggal'] }}
                            </small>
                        @else
                            <h3 class="mb-0 fw-bold text-muted">&mdash;</h3>
                            <small class="text-muted">Belum ada yang disahkan</small>
                        @endif
                        @if($m['stocktake']['berjalan'] > 0)
                            <div class="mt-2">
                                <span class="badge bg-info-subtle text-info-emphasis rounded-pill">
                                    {{ $m['stocktake']['berjalan'] }} sesi sedang berjalan
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endisset

        @isset($m['pengguna'])
            <div class="col-12 col-md-4">
                <div class="card h-100 shadow-sm border-0 rounded-4">
                    <div class="card-body">
                        <h6 class="text-muted fw-normal mb-2">Pengguna</h6>
                        <h3 class="mb-0 fw-bold">
                            {{ $m['pengguna']['aktif'] }}
                            <span class="fs-6 fw-normal text-muted">aktif</span>
                        </h3>
                        <small class="text-muted">
                            {{ $m['pengguna']['sesi_hidup'] }} sedang online &middot;
                            {{ $m['pengguna']['nonaktif'] }} dinonaktifkan
                        </small>
                    </div>
                </div>
            </div>
        @endisset
    </div>
@endif

<div class="row g-4">
    @isset($m['tren'])
        <div class="col-12 {{ isset($m['aktivitas']) ? 'col-xl-8' : '' }}">
            <div class="card shadow-sm border-0 h-100 rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h6 class="fw-bold mb-0">Pesanan Masuk vs Selesai</h6>
                    <small class="text-muted">{{ count($m['tren']['label']) }} bulan terakhir</small>
                </div>
                <div class="card-body px-4" style="min-height: 300px;">
                    <canvas id="grafikTren"></canvas>
                </div>
            </div>
        </div>
    @endisset

    {{-- LOG AKTIVITAS — SUPER ADMIN SAJA. Log ini merekam tindakan Manager
         juga; alasan lengkapnya di Permission::ADMIN_AUDIT. --}}
    @isset($m['aktivitas'])
        <div class="col-12 col-xl-4">
            <div class="card shadow-sm border-0 h-100 rounded-4">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-0">
                    <h6 class="fw-bold mb-0">Aktivitas Terbaru</h6>
                    <a href="{{ route('wms.admin.activity-log') }}" class="btn btn-sm btn-link text-decoration-none">Semua</a>
                </div>
                <div class="card-body p-0 mt-3">
                    @forelse($m['aktivitas'] as $log)
                        <div class="px-4 py-2 border-bottom">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="badge bg-light text-dark border rounded-pill">{{ $log->action_label }}</span>
                                <small class="text-muted text-nowrap">{{ $log->created_at?->diffForHumans(short: true) }}</small>
                            </div>
                            <div class="small mt-1 text-truncate" title="{{ $log->description }}">{{ $log->description }}</div>
                            <small class="text-muted">{{ $log->pelaku }}</small>
                        </div>
                    @empty
                        <p class="text-muted text-center py-5 mb-0">Belum ada aktivitas tercatat.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endisset
</div>
@endsection

@push('scripts')
@isset($m['tren'])
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    new Chart(document.getElementById('grafikTren'), {
        type: 'line',
        data: {
            labels: @json($m['tren']['label']),
            datasets: [
                {
                    label: 'Masuk',
                    data: @json($m['tren']['masuk']),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,.08)',
                    fill: true,
                    tension: .35,
                },
                {
                    label: 'Selesai',
                    data: @json($m['tren']['selesai']),
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25,135,84,.08)',
                    fill: true,
                    tension: .35,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            // Sumbu mulai dari nol. Chart.js secara bawaan memotong sumbu di
            // angka terendah, dan selisih 3 pesanan bisa terlihat seperti
            // jurang.
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });
</script>
@endisset
@endpush
