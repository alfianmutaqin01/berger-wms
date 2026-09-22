{{-- ============================================ SECTION: ALUR OPERASIONAL (Semua Peran) --}}
<div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-2">
        <div class="p-1 rounded-2 bg-primary-subtle text-primary">
            <i class="bi bi-arrow-repeat fs-6"></i>
        </div>
        <h6 class="section-header-tag mb-0">Alur Operasional Harian</h6>
    </div>
    <span class="text-muted small">Klik kartu untuk membuka modul terkait</span>
</div>

<div class="row g-3 mb-4">
    {{-- 1. BUTUH DITERIMA / OUTBOUND APPROVAL --}}
    @isset($m['menunggu_diterima'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.approval.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-warning">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-clipboard-check"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Butuh Diterima</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['menunggu_diterima']['jumlah'] }}</h2>
                                <span class="text-muted small">pesanan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            @if($m['menunggu_diterima']['tertua_hari'] !== null)
                                <div class="d-flex align-items-center gap-1 text-warning-emphasis small">
                                    <i class="bi bi-hourglass-split"></i>
                                    <span>Terlama menunggu <strong>{{ $m['menunggu_diterima']['tertua_hari'] }} hari</strong></span>
                                </div>
                            @else
                                <div class="text-muted small">
                                    <i class="bi bi-check2-circle text-success me-1"></i>Tidak ada antrean tertunda
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 2. VERIFIKASI INBOUND --}}
    @isset($m['inbound_menunggu'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.inbound.verify') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-indigo">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Verifikasi Inbound</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['inbound_menunggu']['jumlah'] }}</h2>
                                <span class="text-muted small">dokumen</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Barang di rak, stok belum resmi</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 3. SIAP DIPICKING --}}
    @isset($m['siap_dipicking'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.picking.batching') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-primary">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-boxes"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Siap Dipicking</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['siap_dipicking']['jumlah'] }}</h2>
                                <span class="text-muted small">pesanan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Belum masuk daftar picking</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 4. DAFTAR PICKING --}}
    @isset($m['picking_berjalan'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.picking.queue') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-info">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-list-task"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Daftar Picking</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['picking_berjalan']['dikerjakan'] }}</h2>
                                <span class="text-muted small">dikerjakan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="badge bg-info-subtle text-info-emphasis rounded-pill px-2 py-1 small">
                                {{ $m['picking_berjalan']['terbuka'] }} antrean terbuka
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 5. DALAM PENGIRIMAN --}}
    @isset($m['dalam_pengiriman'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.delivery.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-secondary">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-truck"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Dalam Pengiriman</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['dalam_pengiriman']['jumlah'] }}</h2>
                                <span class="text-muted small">surat jalan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Sudah berangkat, belum sampai</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 6. BUKTI KIRIM --}}
    @isset($m['bukti_menunggu'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.verification.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-success">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-camera"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Bukti Kirim</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-dark">{{ $m['bukti_menunggu']['jumlah'] }}</h2>
                                <span class="text-muted small">menunggu</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Foto diunggah, butuh verifikasi</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 7. OUTSTANDING --}}
    @isset($m['outstanding'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.outstanding.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-danger">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Outstanding</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-danger">{{ number_format($m['outstanding']['qty']) }}</h2>
                                <span class="text-muted small">unit</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">Terutang di <strong>{{ $m['outstanding']['pesanan'] }}</strong> pesanan</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 8. SEGERA KEDALUWARSA --}}
    @isset($m['segera_kedaluwarsa'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.inventory.index') }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-danger">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-calendar-x"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Segera Kedaluwarsa</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-danger">{{ $m['segera_kedaluwarsa']['batch'] }}</h2>
                                <span class="text-muted small">batch</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">
                                {{ number_format($m['segera_kedaluwarsa']['qty']) }} unit &le; {{ $m['segera_kedaluwarsa']['ambang'] }} hari lagi
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset

    {{-- 9. KARANTINA --}}
    @isset($m['karantina'])
        <div class="col-6 col-xl-3">
            <a href="{{ route('wms.inventory.index', ['status' => 'quarantine']) }}" class="stat-card-link">
                <div class="card h-100 stat-card stat-card-warning">
                    <div class="card-body p-3 p-xl-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div class="stat-icon-badge">
                                    <i class="bi bi-shield-exclamation"></i>
                                </div>
                                <div class="action-chevron">
                                    <i class="bi bi-arrow-right"></i>
                                </div>
                            </div>
                            <h6 class="text-muted fw-normal mb-1">Karantina</h6>
                            <div class="d-flex align-items-baseline gap-2">
                                <h2 class="mb-0 fw-bold text-warning-emphasis">{{ number_format($m['karantina']['qty']) }}</h2>
                                <span class="text-muted small">unit ditahan</span>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <span class="text-muted small">
                                {{ $m['karantina']['batch'] }} batch &middot; 
                                <strong class="text-primary">{{ $m['karantina']['lepas_pekan_ini'] }} selesai pekan ini</strong>
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    @endisset
</div>
