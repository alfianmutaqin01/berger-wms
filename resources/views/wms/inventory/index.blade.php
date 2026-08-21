@extends('layouts.wms')
@section('title', 'Data Stok')
@section('page_title', 'Master Data Stok (FIFO & Pallet)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Pantau ketersediaan stok secara real-time. Klik pada setiap SKU untuk melihat rincian lokasi Pallet dan Kadaluarsa (Batch). <br><strong>Catatan:</strong> Masa kadaluarsa adalah 30 bulan dari Tanggal Produksi.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-layers text-primary me-2"></i>Ketersediaan Stok Gudang</h6>
        <div class="input-group input-group-sm w-25">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" class="form-control bg-light border-start-0" placeholder="Cari SKU / Produk...">
        </div>
    </div>
    <div class="card-body p-4">
        
        <!-- Filter Bar -->
        <div class="d-flex flex-wrap gap-2 mb-4 bg-light p-3 rounded-3 border">
            <select class="form-select form-select-sm w-auto">
                <option selected>Semua Gudang</option>
                <option value="WH-01">WH-01 (Karawang)</option>
                <option value="WH-02">WH-02 (Cikarang)</option>
            </select>
            <select class="form-select form-select-sm w-auto">
                <option selected>Kategori Kemasan</option>
                <option value="PAIL">PAIL</option>
                <option value="TIN">TIN</option>
                <option value="CAN">CAN</option>
            </select>
            <div class="input-group input-group-sm w-auto">
                <span class="input-group-text bg-white">Dari</span>
                <input type="date" class="form-control" title="Tanggal Masuk Awal">
                <span class="input-group-text bg-white border-start-0 border-end-0">S/d</span>
                <input type="date" class="form-control" title="Tanggal Masuk Akhir">
            </div>
            <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Terapkan</button>
            <button class="btn btn-sm btn-outline-danger ms-auto"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
            <button class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
        </div>

        <div class="accordion" id="inventoryAccordion">
            @foreach($inventories as $sku => $data)
            <div class="accordion-item border-0 mb-3 rounded-4 shadow-sm bg-white overflow-hidden">
                <h2 class="accordion-header" id="heading{{ Str::slug($sku) }}">
                    <button class="accordion-button collapsed px-4 py-3 bg-light text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapse{{ Str::slug($sku) }}" aria-expanded="false" aria-controls="collapse{{ Str::slug($sku) }}">
                        <div class="d-flex justify-content-between align-items-center w-100 me-3">
                            <div>
                                <span class="font-monospace fw-bold text-primary me-2">{{ $sku }}</span>
                                <span class="fw-semibold">{{ $data['name'] }}</span>
                            </div>
                            <div class="d-flex align-items-center gap-4">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $data['uom'] }}</span>
                                <div class="text-end">
                                    <small class="text-muted d-block" style="font-size: 0.7rem;">Good Stock</small>
                                    <span class="fw-bold text-success">{{ $data['good_qty'] }} <small class="text-muted fw-normal">Pcs</small></span>
                                </div>
                                <div class="text-end" style="min-width: 60px;">
                                    <small class="text-muted d-block" style="font-size: 0.7rem;">DDP Stock</small>
                                    @if($data['ddp_qty'] > 0)
                                        <span class="fw-bold text-danger">{{ $data['ddp_qty'] }} <small class="text-muted fw-normal">Pcs</small></span>
                                    @else
                                        <span class="fw-bold text-muted">0 <small class="fw-normal">Pcs</small></span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </button>
                </h2>
                <div id="collapse{{ Str::slug($sku) }}" class="accordion-collapse collapse" aria-labelledby="heading{{ Str::slug($sku) }}" data-bs-parent="#inventoryAccordion">
                    <div class="accordion-body p-0">
                        <!-- GOOD STOCK SECTION -->
                        @if(count($data['good_batches']) > 0)
                            <div class="bg-success-subtle px-4 py-2 border-bottom">
                                <h6 class="fw-bold text-success mb-0 small"><i class="bi bi-check-circle-fill me-1"></i> GOOD STOCK (Layak Jual)</h6>
                            </div>
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">No. Batch</th>
                                        <th>Tgl Produksi</th>
                                        <th>Expired Date</th>
                                        <th>Lokasi Rak/Pallet</th>
                                        <th class="text-end">Tersedia</th>
                                        <th class="text-end pe-4">Di-book (Alloc)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($data['good_batches'] as $batch)
                                        @foreach($batch['pallets'] as $pallet)
                                        <tr>
                                            <td class="ps-4 font-monospace small text-primary">{{ $batch['batch_no'] }}</td>
                                            <td class="small">{{ \Carbon\Carbon::parse($batch['mfg_date'])->format('d M Y') }}</td>
                                            <td class="small">{{ \Carbon\Carbon::parse($batch['exp_date'])->format('d M Y') }}</td>
                                            <td>
                                                <span class="badge border border-secondary text-secondary me-1">{{ $pallet['location'] }}</span>
                                                <small class="text-muted">{{ $pallet['pallet_no'] }}</small>
                                            </td>
                                            <td class="text-end fw-semibold">{{ $pallet['qty'] - $pallet['alloc'] }}</td>
                                            <td class="text-end pe-4 text-warning">{{ $pallet['alloc'] }}</td>
                                        </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        <!-- DDP STOCK SECTION -->
                        @if(count($data['ddp_batches']) > 0)
                            <div class="bg-danger-subtle px-4 py-2 border-bottom {{ count($data['good_batches']) > 0 ? 'border-top' : '' }}">
                                <h6 class="fw-bold text-danger mb-0 small"><i class="bi bi-exclamation-triangle-fill me-1"></i> STOK DDP (Rusak / Karantina / Expired)</h6>
                            </div>
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">No. Batch</th>
                                        <th>Tgl Produksi</th>
                                        <th>Keterangan</th>
                                        <th>Lokasi Rak/Pallet</th>
                                        <th class="text-end pe-4">Qty (Pcs)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($data['ddp_batches'] as $batch)
                                        @foreach($batch['pallets'] as $pallet)
                                        <tr>
                                            <td class="ps-4 font-monospace small text-danger">{{ $batch['batch_no'] }}</td>
                                            <td class="small">{{ \Carbon\Carbon::parse($batch['mfg_date'])->format('d M Y') }}</td>
                                            <td class="small">
                                                @if($batch['is_expired'])
                                                    <span class="badge bg-danger">EXPIRED</span>
                                                @else
                                                    <span class="badge bg-warning text-dark">BARANG RETUR/RUSAK</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge border border-danger text-danger me-1">{{ $pallet['location'] }}</span>
                                                <small class="text-muted">{{ $pallet['pallet_no'] }}</small>
                                            </td>
                                            <td class="text-end pe-4 fw-bold text-danger">{{ $pallet['qty'] }}</td>
                                        </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        
                        <div class="bg-light p-3 text-end">
                            <button class="btn btn-sm btn-outline-primary me-2" onclick="openTransferModal('{{ $sku }}')"><i class="bi bi-arrow-left-right"></i> Pindah Lokasi (Transfer)</button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="openAdjustmentModal('{{ $sku }}')"><i class="bi bi-pencil-square"></i> Penyesuaian Stok</button>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

    </div>
</div>

@push('modals')
<!-- Modal Penyesuaian -->
<div class="modal fade" id="adjustmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-light border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Penyesuaian Stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formAdjustment" action="/wms/inventory/adjust" method="POST">
                @csrf
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">SKU Produk</label>
                        <input type="text" name="sku" class="form-control bg-light" id="adjSku" readonly>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small text-muted">Aksi</label>
                            <select name="action" class="form-select">
                                <option value="add">Tambah (+) Kuantitas</option>
                                <option value="deduct">Kurangi (-) Kuantitas</option>
                                <option value="writeoff">Write-off / Musnahkan (DDP)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small text-muted">Kuantitas</label>
                            <input type="number" name="qty" class="form-control" min="1" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Alasan / Catatan</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="Cth: Selisih stock opname..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" onclick="saveAdjustment()">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Transfer -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-light border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">Transfer Lokasi Stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formTransfer" action="/wms/inventory/transfer" method="POST">
                @csrf
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">SKU Produk</label>
                        <input type="text" name="sku" class="form-control bg-light" id="tfSku" readonly>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small text-muted">Lokasi Awal (Dari)</label>
                            <select name="from_loc" class="form-select" required>
                                <option value="Rak A">Rak A</option>
                                <option value="Rak B">Rak B</option>
                                <option value="Rak C">Rak C</option>
                                <option value="Rak G">Rak G</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small text-muted">Lokasi Tujuan (Ke)</label>
                            <select name="to_loc" class="form-select" required>
                                <option value="Rak A">Rak A</option>
                                <option value="Rak B">Rak B</option>
                                <option value="Rak C">Rak C</option>
                                <option value="Rak G">Rak G</option>
                                <option value="Rak DDP-1">Rak DDP-1</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small text-muted">Kuantitas Transfer</label>
                            <input type="number" name="qty" class="form-control" min="1" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" onclick="saveTransfer()">Proses Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush
@endsection

@push('scripts')
<script>
    let adjustmentModal, transferModal;
    
    document.addEventListener("DOMContentLoaded", function() {
        adjustmentModal = new bootstrap.Modal(document.getElementById('adjustmentModal'));
        transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
    });
    
    function openAdjustmentModal(sku) {
        document.getElementById('adjSku').value = sku;
        adjustmentModal.show();
    }

    function openTransferModal(sku) {
        document.getElementById('tfSku').value = sku;
        transferModal.show();
    }
    
    function saveAdjustment() {
        if(!document.getElementById('formAdjustment').checkValidity()) {
            document.getElementById('formAdjustment').reportValidity();
            return;
        }
        Swal.fire({
            title: 'Menyimpan...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        document.getElementById('formAdjustment').submit();
    }

    function saveTransfer() {
        if(!document.getElementById('formTransfer').checkValidity()) {
            document.getElementById('formTransfer').reportValidity();
            return;
        }
        Swal.fire({
            title: 'Memproses Transfer...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        document.getElementById('formTransfer').submit();
    }
</script>
@endpush