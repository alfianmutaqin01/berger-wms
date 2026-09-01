@extends('layouts.wms')

@section('title', 'Data Stok')
@section('page_title', 'Data Stok')

@section('content')
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Ada isian yang perlu diperbaiki:</strong>
    <ul class="mb-0 mt-2 small">
        @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
    </ul>
</div>
@endif

<!-- Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Good Stock</h6>
                <h3 class="mb-0 fw-bold text-success">{{ number_format($stats['good']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-primary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Teralokasi</h6>
                <h3 class="mb-0 fw-bold text-primary">{{ number_format($stats['dialokasikan']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-secondary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Stok DDP</h6>
                <h3 class="mb-0 fw-bold text-secondary">{{ number_format($stats['ddp']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        {{-- Batch yang umurnya tinggal <= 90 hari; ini yang harus dijual duluan. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Hampir Kedaluwarsa</h6>
                <h3 class="mb-0 fw-bold text-danger">{{ number_format($stats['kritis']) }} <small class="fs-6 fw-normal text-muted">batch</small></h3>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-layers text-primary me-2"></i> Ketersediaan Stok Gudang</h5>
        <p class="text-muted small mt-1 mb-0">
            Satu baris = satu SKU. Klik barisnya untuk melihat rincian batch, rak, dan sisa umur simpan.
            Batch sengaja tidak dilebur agar urutan FIFO tetap terlacak.
        </p>
    </div>

    <div class="card-body p-4">
        <form method="GET" action="{{ url('/wms/inventory') }}" class="row g-2 mb-4 align-items-stretch">
            <div class="col-12 col-md-3">
                <div class="input-group h-100">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-white border-start-0" placeholder="SKU, produk, batch, no. produksi...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select h-100">
                    <option value="">Semua Gudang</option>
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($filters['warehouse_id'] == $w->id)>{{ $w->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="category_id" class="form-select h-100">
                    <option value="">Semua Kategori</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected($filters['category_id'] == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select h-100">
                    <option value="">Semua Status</option>
                    @foreach($statuses as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['status'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="production_date" value="{{ $filters['production_date'] }}" class="form-control h-100" title="Tanggal produksi">
            </div>
            <div class="col-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" title="Terapkan filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ url('/wms/inventory') }}" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="expiring" value="1" id="fExpiring"
                           @checked($filters['expiring']) onchange="this.form.submit()">
                    <label class="form-check-label small text-danger fw-semibold" for="fExpiring">
                        Hanya tampilkan yang hampir kedaluwarsa (≤ 90 hari)
                    </label>
                </div>
            </div>
        </form>

        @php
            // Saat filter status dipasang, salah satu blok memang sengaja
            // dikosongkan. Dibedakan dari "benar-benar tidak ada stok" supaya
            // blok kosong tidak terbaca sebagai data yang gagal dimuat.
            $statusDisaring = (bool) $filters['status'];
        @endphp

        @forelse($barisSku as $baris)
            @php
                $p = $baris['product'];
                $idAcc = 'sku'.$p->id;
            @endphp
            <div class="border rounded-4 mb-3 overflow-hidden {{ $baris['kritis'] ? 'border-danger border-2' : '' }}">
                <!-- Baris tertutup: hanya SKU dan angka ringkas -->
                <button class="btn w-100 text-start d-flex flex-wrap align-items-center gap-2 gap-md-3 p-3 bg-white collapsed"
                        type="button" data-bs-toggle="collapse" data-bs-target="#{{ $idAcc }}"
                        aria-expanded="false" aria-controls="{{ $idAcc }}">
                    <i class="bi bi-chevron-right chevron-sku text-muted flex-shrink-0"></i>
                    <span class="badge bg-light text-dark border font-monospace">{{ $p->sku }}</span>
                    <span class="fw-semibold text-dark flex-grow-1">{{ $p->name }}</span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary">{{ $p->uom }}</span>
                    <span class="small text-nowrap">
                        <span class="text-muted">Good Stock:</span>
                        <strong class="text-success">{{ number_format($baris['total_good']) }}</strong>
                    </span>
                    <span class="text-muted">·</span>
                    <span class="small text-nowrap">
                        <span class="text-muted">DDP Stock:</span>
                        <strong class="{{ $baris['total_ddp'] > 0 ? 'text-danger' : 'text-muted' }}">{{ number_format($baris['total_ddp']) }}</strong>
                    </span>
                    @if($baris['kritis'])
                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i>Segera Exp</span>
                    @endif
                </button>

                <div class="collapse" id="{{ $idAcc }}">
                    <!-- ============ BLOK GOOD STOCK ============ -->
                    <div class="bg-success-subtle px-3 pt-3 pb-1 border-top">
                        <h6 class="fw-bold text-success-emphasis mb-2 small">
                            <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i>GOOD STOCK (Layak Jual)
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle bg-white rounded-3 mb-3">
                                <thead>
                                    <tr>
                                        <th class="text-secondary small fw-semibold">BATCH</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">TGL PROD</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">EXP DATE</th>
                                        <th class="text-secondary small fw-semibold text-nowrap" style="min-width: 130px;">SISA UMUR SIMPAN</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">LOKASI</th>
                                        <th class="text-secondary small fw-semibold text-end">TERSEDIA</th>
                                        <th class="text-secondary small fw-semibold text-end">DI-BOOK</th>
                                        @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER])
                                        <th class="text-secondary small fw-semibold text-center">AKSI</th>
                                        @endcanany
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($baris['good'] as $stock)
                                        @php
                                            $warnaUmur = match($stock->shelf_life_urgency) {
                                                'expired' => 'text-danger fw-bold',
                                                'critical' => 'text-danger fw-semibold',
                                                'warning' => 'text-warning-emphasis fw-semibold',
                                                default => 'text-muted',
                                            };
                                        @endphp
                                        <tr>
                                            <td><small class="font-monospace">{{ $stock->batch_no }}</small></td>
                                            <td class="small text-muted text-nowrap">{{ $stock->production_date->translatedFormat('d M y') }}</td>
                                            <td class="small text-nowrap">{{ $stock->expiry_date->translatedFormat('d M y') }}</td>
                                            <td class="text-nowrap small {{ $warnaUmur }}">
                                                {{ $stock->shelf_life_label }}
                                                @if($stock->shelf_life_urgency === 'critical')
                                                    <span class="badge bg-warning text-dark ms-1">⚠ Segera Exp</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary font-monospace">{{ $stock->location?->code ?? '—' }}</span>
                                                <small class="d-block text-muted" style="font-size: 0.7rem;">{{ $stock->warehouse?->code }}</small>
                                            </td>
                                            <td class="text-end fw-bold text-nowrap">
                                                {{ number_format($stock->qty_available) }}
                                                <small class="text-muted fw-normal">{{ $p->uom }}</small>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                @if($stock->qty_allocated > 0)
                                                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary">{{ number_format($stock->qty_allocated) }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER])
                                            <td class="text-center text-nowrap">
                                                @include('wms.inventory._aksi-batch', ['stock' => $stock, 'sku' => $p->sku])
                                            </td>
                                            @endcanany
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center text-muted small py-3">
                                                {{ $statusDisaring ? 'Disembunyikan oleh filter status.' : 'Tidak ada Good Stock untuk SKU ini.' }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ============ BLOK STOK DDP ============ -->
                    {{-- Selalu dirender meski kosong (docs/4 §4.3.9): ketiadaan
                         stok rusak harus terbaca sebagai informasi. --}}
                    <div class="bg-danger-subtle px-3 pt-3 pb-1 border-top">
                        <h6 class="fw-bold text-danger-emphasis mb-2 small">
                            <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i>STOK DDP (Rusak / Karantina / Expired)
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle bg-white rounded-3 mb-3">
                                <thead>
                                    <tr>
                                        <th class="text-secondary small fw-semibold">BATCH</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">TGL PROD</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">EXP DATE</th>
                                        <th class="text-secondary small fw-semibold" style="min-width: 150px;">KETERANGAN</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">LOKASI</th>
                                        <th class="text-secondary small fw-semibold text-end">QTY</th>
                                        @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER])
                                        <th class="text-secondary small fw-semibold text-center">AKSI</th>
                                        @endcanany
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($baris['ddp'] as $stock)
                                        <tr>
                                            <td><small class="font-monospace">{{ $stock->batch_no }}</small></td>
                                            <td class="small text-muted text-nowrap">{{ $stock->production_date->translatedFormat('d M y') }}</td>
                                            <td class="small text-nowrap">{{ $stock->expiry_date->translatedFormat('d M y') }}</td>
                                            <td class="small text-nowrap">
                                                @if($stock->status === \App\Models\InventoryStock::STATUS_EXPIRED)
                                                    <span class="text-danger fw-semibold">🔴 {{ $stock->status_label }}</span>
                                                @else
                                                    <span class="text-warning-emphasis fw-semibold">🟠 {{ $stock->ddp_reason_label ?? $stock->status_label }}</span>
                                                @endif
                                                <small class="d-block text-muted">{{ $stock->shelf_life_label }}</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary font-monospace">{{ $stock->location?->code ?? '—' }}</span>
                                                <small class="d-block text-muted" style="font-size: 0.7rem;">{{ $stock->warehouse?->code }}</small>
                                            </td>
                                            <td class="text-end fw-bold text-nowrap">
                                                {{ number_format($stock->qty_available) }}
                                                <small class="text-muted fw-normal">{{ $p->uom }}</small>
                                            </td>
                                            @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER])
                                            <td class="text-center text-nowrap">
                                                @include('wms.inventory._aksi-batch', ['stock' => $stock, 'sku' => $p->sku])
                                            </td>
                                            @endcanany
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center text-muted small py-3">
                                                {{ $statusDisaring ? 'Disembunyikan oleh filter status.' : 'Tidak ada stok DDP untuk SKU ini.' }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                Belum ada stok yang cocok dengan filter ini.
                <small class="d-block mt-2">Stok muncul setelah palet inbound diverifikasi Logistik.</small>
            </div>
        @endforelse

        @if($halaman->hasPages())
            <div class="mt-4">{{ $halaman->links() }}</div>
        @endif
    </div>
</div>

@can(\App\Support\Permission::INVENTORY_ADJUST)
<!-- Koreksi stok -->
<div class="modal fade" id="modalAdjust" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ url('/wms/inventory/adjust') }}" class="modal-content border-0 rounded-4">
            @csrf
            <input type="hidden" name="stock_id" id="adjStockId">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-warning me-2"></i>Koreksi Stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded-3 p-3 mb-3 small">
                    <div><strong id="adjSku" class="font-monospace"></strong></div>
                    <div class="text-muted">Batch <span id="adjBatch" class="font-monospace"></span></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Qty Tersedia Saat Ini</label>
                    <input type="text" id="adjQtyOld" class="form-control bg-light" readonly>
                    <small class="text-muted" id="adjAllocHint"></small>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Qty Baru <span class="text-danger">*</span></label>
                    <input type="number" name="qty_new" id="adjQtyNew" class="form-control" min="0" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Tandai sebagai DDP (opsional)</label>
                    <select name="ddp_reason" class="form-select">
                        <option value="">Tetap Good Stock</option>
                        @foreach(\App\Models\InventoryStock::DDP_REASON_LABELS as $slug => $label)
                            <option value="{{ $slug }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Stok DDP tidak pernah ikut alokasi FIFO.</small>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Alasan Koreksi <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="2" minlength="5" maxlength="500" required
                              placeholder="Contoh: hasil opname 31 Agu 2026, selisih 2 pail rusak saat penurunan."></textarea>
                    <small class="text-muted">Wajib diisi — tercatat permanen di ledger stok.</small>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning fw-bold">Simpan Koreksi</button>
            </div>
        </form>
    </div>
</div>
@endcan

@can(\App\Support\Permission::INVENTORY_TRANSFER)
<!-- Pindah rak -->
<div class="modal fade" id="modalTransfer" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ url('/wms/inventory/transfer') }}" class="modal-content border-0 rounded-4">
            @csrf
            <input type="hidden" name="stock_id" id="trfStockId">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right text-primary me-2"></i>Pindah Rak</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded-3 p-3 mb-3 small">
                    <div><strong id="trfSku" class="font-monospace"></strong></div>
                    <div class="text-muted">Batch <span id="trfBatch" class="font-monospace"></span> · dari rak <strong id="trfLoc" class="font-monospace"></strong></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Qty Dipindahkan <span class="text-danger">*</span></label>
                    <input type="number" name="qty" id="trfQty" class="form-control" min="1" required>
                    <small class="text-muted" id="trfQtyHint"></small>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Rak Tujuan <span class="text-danger">*</span></label>
                    <input type="text" name="to_location_code" class="form-control text-uppercase font-monospace" placeholder="mis. B-01-05" required>
                    <small class="text-muted">Harus rak aktif di gudang yang sama.</small>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Alasan Pemindahan <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="2" minlength="5" maxlength="500" required
                              placeholder="Contoh: konsolidasi batch agar rak B-01-01 bisa dikosongkan."></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary fw-bold">Pindahkan</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection

@push('styles')
<style>
    /* Panah baris SKU berputar saat accordion terbuka. */
    .chevron-sku { transition: transform 0.2s ease; }
    [aria-expanded="true"] .chevron-sku { transform: rotate(90deg); }
</style>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Satu handler per modal, mengambil nilai dari tombol pemicunya —
        // lebih ringan daripada menyalin form ke setiap baris tabel.
        const adjust = document.getElementById('modalAdjust');
        if (adjust) {
            adjust.addEventListener('show.bs.modal', function (e) {
                const b = e.relatedTarget;
                const alloc = parseInt(b.dataset.alloc, 10) || 0;

                adjust.querySelector('#adjStockId').value = b.dataset.stock;
                adjust.querySelector('#adjSku').textContent = b.dataset.sku || '—';
                adjust.querySelector('#adjBatch').textContent = b.dataset.batch || '—';
                adjust.querySelector('#adjQtyOld').value = b.dataset.qty;
                adjust.querySelector('#adjQtyNew').value = b.dataset.qty;
                // Batas bawah dipasang di input juga, bukan cuma di server,
                // supaya kesalahannya ketahuan sebelum dikirim.
                adjust.querySelector('#adjQtyNew').min = alloc;
                adjust.querySelector('#adjAllocHint').textContent = alloc > 0
                    ? alloc + ' sudah dialokasikan untuk pesanan — qty baru tidak boleh di bawah angka itu.'
                    : '';
            });
        }

        const transfer = document.getElementById('modalTransfer');
        if (transfer) {
            transfer.addEventListener('show.bs.modal', function (e) {
                const b = e.relatedTarget;

                transfer.querySelector('#trfStockId').value = b.dataset.stock;
                transfer.querySelector('#trfSku').textContent = b.dataset.sku || '—';
                transfer.querySelector('#trfBatch').textContent = b.dataset.batch || '—';
                transfer.querySelector('#trfLoc').textContent = b.dataset.loc || '—';
                transfer.querySelector('#trfQty').max = b.dataset.qty;
                transfer.querySelector('#trfQty').value = b.dataset.qty;
                transfer.querySelector('#trfQtyHint').textContent = 'Maksimal ' + b.dataset.qty + ' (stok tersedia di rak ini).';
            });
        }
    });
</script>
@endpush
