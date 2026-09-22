{{-- ================================================ SECTION: PAPAN PERINGKAT

     Angkanya dihitung ReportRunner yang sama dengan halaman Laporan, jadi
     urutan di sini dan di berkas Excel tidak akan pernah berbeda. --}}
@isset($m['terlaris'])
<div class="row g-4 mb-4">
    {{-- PRODUK TERLARIS --}}
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100 rounded-4" style="background:#fff;border:1px solid rgba(226,232,240,.85);">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-1 rounded-2 bg-warning-subtle text-warning-emphasis">
                            <i class="bi bi-trophy fs-6"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">Produk Terlaris</h6>
                    </div>
                    {{-- "Terkirim", bukan "dipesan". Pesanan yang masuk tetapi
                         barangnya kosong bukan penjualan. --}}
                    <small class="text-muted">Qty terkirim {{ $m['terlaris']['hari'] }} hari terakhir</small>
                </div>
                <a href="{{ route('wms.reports.show', 'produk-terlaris') }}"
                   class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1">
                    Laporan <i class="bi bi-chevron-right ms-1 small"></i>
                </a>
            </div>
            <div class="card-body pt-0 px-4 pb-4">
                @forelse($m['terlaris']['produk'] as $i => $p)
                    <div class="d-flex align-items-center gap-3 py-2 {{ $i > 0 ? 'border-top border-light-subtle' : '' }}">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center flex-shrink-0
                                     {{ $i === 0 ? 'bg-warning text-dark' : 'bg-light text-secondary border' }}"
                              style="width:28px;height:28px;">{{ $i + 1 }}</span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem">{{ $p['nama'] }}</div>
                            <small class="text-muted font-monospace" style="font-size:.72rem">{{ $p['sku'] }}</small>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold text-dark">{{ number_format($p['terkirim']) }}</div>
                            <small class="text-muted" style="font-size:.7rem">
                                {{ $p['satuan'] }} · {{ $p['pesanan'] }} pesanan
                            </small>
                        </div>
                    </div>
                @empty
                    <p class="text-muted small text-center mb-0 py-4">
                        Belum ada pengiriman dalam {{ $m['terlaris']['hari'] }} hari terakhir.
                    </p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- PELANGGAN TERATAS --}}
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100 rounded-4" style="background:#fff;border:1px solid rgba(226,232,240,.85);">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-3">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-1 rounded-2 bg-info-subtle text-info-emphasis">
                            <i class="bi bi-people fs-6"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-0">Pelanggan Teratas</h6>
                    </div>
                    <small class="text-muted">Qty diterima {{ $m['terlaris']['hari'] }} hari terakhir</small>
                </div>
                <a href="{{ route('wms.reports.show', 'pelanggan-teratas') }}"
                   class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1">
                    Laporan <i class="bi bi-chevron-right ms-1 small"></i>
                </a>
            </div>
            <div class="card-body pt-0 px-4 pb-4">
                @forelse($m['terlaris']['pelanggan'] as $i => $c)
                    <div class="d-flex align-items-center gap-3 py-2 {{ $i > 0 ? 'border-top border-light-subtle' : '' }}">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center flex-shrink-0
                                     {{ $i === 0 ? 'bg-info text-dark' : 'bg-light text-secondary border' }}"
                              style="width:28px;height:28px;">{{ $i + 1 }}</span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem">{{ $c['nama'] }}</div>
                            <small class="text-muted font-monospace" style="font-size:.72rem">{{ $c['kode'] }}</small>
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold text-dark">{{ number_format($c['terkirim']) }}</div>
                            <small class="text-muted" style="font-size:.7rem">{{ $c['pesanan'] }} pesanan</small>
                        </div>
                    </div>
                @empty
                    <p class="text-muted small text-center mb-0 py-4">
                        Belum ada pengiriman dalam {{ $m['terlaris']['hari'] }} hari terakhir.
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endisset
