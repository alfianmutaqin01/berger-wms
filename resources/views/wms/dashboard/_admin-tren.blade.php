{{-- ============================================ SECTION: TREN & AKTIVITAS --}}
<div class="row g-4 mb-4">
    {{-- GRAFIK TREN PESANAN --}}
    @isset($m['tren'])
        <div class="col-12 {{ isset($m['aktivitas']) ? 'col-xl-8' : '' }}">
            <div class="card shadow-sm border-0 h-100 rounded-4" style="background: #ffffff; border: 1px solid rgba(226, 232, 240, 0.85);">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="p-1 rounded-2 bg-primary-subtle text-primary">
                                    <i class="bi bi-graph-up-arrow fs-6"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Pesanan Masuk vs Selesai</h6>
                            </div>
                            <small class="text-muted">Perbandingan volume pesanan masuk dan pemenuhan {{ count($m['tren']['label']) }} bulan terakhir</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-dark border rounded-pill px-3 py-1 small">
                                <span class="d-inline-block rounded-circle me-1" style="width: 8px; height: 8px; background-color: #123962;"></span>
                                Masuk: <strong>{{ array_sum($m['tren']['masuk']) }}</strong>
                            </span>
                            <span class="badge bg-light text-dark border rounded-pill px-3 py-1 small">
                                <span class="d-inline-block rounded-circle me-1" style="width: 8px; height: 8px; background-color: #10b981;"></span>
                                Selesai: <strong>{{ array_sum($m['tren']['selesai']) }}</strong>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body px-4 pb-4 pt-2">
                    <div style="min-height: 320px; height: 320px; width: 100%;">
                        <canvas id="grafikTren"></canvas>
                    </div>
                </div>
            </div>
        </div>
    @endisset

    {{-- LOG AKTIVITAS (Super Admin Saja) --}}
    @isset($m['aktivitas'])
        <div class="col-12 col-xl-4">
            <div class="card shadow-sm border-0 h-100 rounded-4" style="background: #ffffff; border: 1px solid rgba(226, 232, 240, 0.85);">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-1 rounded-2 bg-primary-subtle text-primary">
                            <i class="bi bi-activity fs-6"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">Aktivitas Terbaru</h6>
                    </div>
                    <a href="{{ route('wms.admin.activity-log') }}" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1">
                        Semua <i class="bi bi-chevron-right ms-1 small"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush border-0">
                        @forelse($m['aktivitas'] as $log)
                            <div class="list-group-item px-4 py-3 border-0 border-bottom border-light-subtle activity-row">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 small fw-semibold">
                                        {{ $log->action_label }}
                                    </span>
                                    <small class="text-muted text-nowrap" style="font-size: 0.75rem;">
                                        <i class="bi bi-clock me-1"></i>{{ $log->created_at?->diffForHumans(short: true) }}
                                    </small>
                                </div>
                                <p class="mb-1 text-dark small fw-medium" style="line-height: 1.4;">
                                    {{ $log->description }}
                                </p>
                                <div class="d-flex align-items-center gap-1 text-muted" style="font-size: 0.75rem;">
                                    <i class="bi bi-person text-secondary"></i>
                                    <span>{{ $log->pelaku }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-5 px-4">
                                <div class="text-muted mb-2"><i class="bi bi-inbox fs-1"></i></div>
                                <p class="text-muted mb-0 small">Belum ada aktivitas tercatat.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endisset
</div>
