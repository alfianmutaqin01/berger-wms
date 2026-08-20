@extends('layouts.wms')
@section('title', 'Data Stok')
@section('page_title', 'Master Data Stok (FIFO & Pallet)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Pantau ketersediaan stok secara real-time. Klik pada setiap SKU untuk melihat rincian lokasi Pallet dan Kadaluarsa (Batch) sesuai aturan FIFO.</p>
    </div>
</div>

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
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i> Terapkan Filter</button>
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
                                <div class="text-end" style="min-width: 120px;">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Stok Tersedia</small>
                                    <span class="fw-bold text-success fs-5">{{ $data['total_qty'] }}</span>
                                </div>
                                <div class="text-end" style="min-width: 120px;">
                                    <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Teralokasi (Booking)</small>
                                    <span class="fw-bold text-warning fs-5">{{ $data['total_alloc'] }}</span>
                                </div>
                            </div>
                        </div>
                    </button>
                </h2>
                <div id="collapse{{ Str::slug($sku) }}" class="accordion-collapse collapse" aria-labelledby="heading{{ Str::slug($sku) }}" data-bs-parent="#inventoryAccordion">
                    <div class="accordion-body p-0 border-top">
                        <!-- Rincian Batch & Pallet -->
                        <div class="table-responsive">
                            <table class="table table-hover table-sm align-middle mb-0 text-center" style="font-size: 0.85rem;">
                                <thead class="table-light text-muted">
                                    <tr>
                                        <th class="text-start ps-4 py-2">Batch No</th>
                                        <th>Exp Date</th>
                                        <th>Pallet No</th>
                                        <th>Lokasi Rak</th>
                                        <th class="text-success">Qty Aktual</th>
                                        <th class="text-warning">Qty Booking</th>
                                        <th class="text-end pe-4">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($data['batches'] as $batch)
                                        @foreach($batch['pallets'] as $pallet)
                                        <tr>
                                            <td class="text-start ps-4"><span class="font-monospace text-secondary fw-semibold">{{ $batch['batch_no'] }}</span></td>
                                            <td>{{ $batch['exp_date'] }}</td>
                                            <td><span class="badge bg-light text-dark border">{{ $pallet['pallet_no'] }}</span></td>
                                            <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-geo-alt-fill me-1"></i>{{ $pallet['location'] }}</span></td>
                                            <td class="fw-bold text-success">{{ $pallet['qty'] }}</td>
                                            <td class="fw-bold text-warning">{{ $pallet['alloc'] }}</td>
                                            <td class="text-end pe-4">
                                                                                                <button class="btn btn-sm btn-outline-secondary rounded-pill py-0 mb-1 d-block w-100" style="font-size: 0.7rem;" onclick="alert('Fitur pemindahan pallet antar rak')"><i class="bi bi-arrow-left-right me-1"></i>Move</button>
                                                <button class="btn btn-sm btn-outline-primary rounded-pill py-0 d-block w-100 btn-adjust" style="font-size: 0.7rem;" 
                                                    data-sku="{{ $sku }}" 
                                                    data-batch="{{ $batch['batch_no'] }}"
                                                    data-loc="{{ $pallet['location'] }}"
                                                    data-avail="{{ $pallet['qty'] }}"
                                                    data-alloc="{{ $pallet['alloc'] }}"
                                                    data-bs-toggle="modal" data-bs-target="#modalAdjustment">
                                                    <i class="bi bi-sliders"></i> Sesuaikan
                                                </button>
                                            </td>
                                        </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

    </div>
</div>
@endsection
@push('modals')
<!-- Modal Adjustment -->
<div class="modal fade" id="modalAdjustment" tabindex="-1" aria-labelledby="modalAdjustmentLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
        <h1 class="modal-title fs-5 fw-bold text-dark" id="modalAdjustmentLabel"><i class="bi bi-sliders text-primary me-2"></i> Penyesuaian Stok (Adjustment)</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="alert bg-light border text-dark mb-4">
            <div class="row text-center">
                <div class="col-4 border-end">
                    <small class="text-muted d-block mb-1">SKU</small>
                    <strong id="adjSku" class="font-monospace small"></strong>
                </div>
                <div class="col-4 border-end">
                    <small class="text-muted d-block mb-1">Lokasi</small>
                    <strong id="adjLoc" class="text-primary"></strong>
                </div>
                <div class="col-4">
                    <small class="text-muted d-block mb-1">Batch</small>
                    <strong id="adjBatch" class="font-monospace small"></strong>
                </div>
            </div>
        </div>

        <form id="formAdjustment">
            <div class="row mb-3">
                <div class="col-6">
                    <label class="form-label small text-muted fw-semibold">Stok Saat Ini</label>
                    <input type="text" class="form-control bg-light" id="adjCurrent" readonly>
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted fw-semibold text-danger">Telah Dialokasikan</label>
                    <input type="text" class="form-control bg-danger-subtle text-danger fw-bold border-danger-subtle" id="adjAllocated" readonly>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label fw-bold">Kuantitas Fisik Baru (Qty Baru) <span class="text-danger">*</span></label>
                <input type="number" class="form-control form-control-lg fw-bold text-primary" id="adjNewQty" required min="0">
                <div class="form-text text-danger" id="adjWarning" style="display: none;">
                    <i class="bi bi-exclamation-circle"></i> Peringatan: Qty baru tidak boleh kurang dari Qty yang telah dialokasikan!
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Alasan Penyesuaian (Audit Log) <span class="text-danger">*</span></label>
                <select class="form-select" id="adjReason" required>
                    <option value="" selected disabled>Pilih alasan...</option>
                    <option value="1">Barang Rusak / Cacat</option>
                    <option value="2">Kehilangan (Lost)</option>
                    <option value="3">Koreksi Opname Fisik</option>
                    <option value="4">Pemusnahan (Disposal)</option>
                </select>
            </div>
        </form>
      </div>
      <div class="modal-footer border-top-0 px-4 pb-4">
        <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary px-4 rounded-pill shadow-sm" id="btnSaveAdjustment">Simpan Koreksi</button>
      </div>
    </div>
  </div>
</div>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        let minAllocated = 0;

        document.querySelectorAll('.btn-adjust').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('adjSku').textContent = this.getAttribute('data-sku');
                document.getElementById('adjBatch').textContent = this.getAttribute('data-batch');
                document.getElementById('adjLoc').textContent = this.getAttribute('data-loc');
                
                let avail = parseInt(this.getAttribute('data-avail'));
                let alloc = parseInt(this.getAttribute('data-alloc'));
                minAllocated = alloc;

                document.getElementById('adjCurrent').value = avail;
                document.getElementById('adjAllocated').value = alloc;
                document.getElementById('adjNewQty').value = avail;
                
                document.getElementById('adjNewQty').classList.remove('is-invalid');
                document.getElementById('adjWarning').style.display = 'none';
            });
        });

        document.getElementById('adjNewQty').addEventListener('input', function() {
            let newVal = parseInt(this.value) || 0;
            if(newVal < minAllocated) {
                this.classList.add('is-invalid');
                document.getElementById('adjWarning').style.display = 'block';
            } else {
                this.classList.remove('is-invalid');
                document.getElementById('adjWarning').style.display = 'none';
            }
        });

        document.getElementById('btnSaveAdjustment').addEventListener('click', function() {
            let newVal = parseInt(document.getElementById('adjNewQty').value) || 0;
            let reason = document.getElementById('adjReason').value;

            if(newVal < minAllocated) {
                alert('Eror: Tidak dapat mengurangi stok di bawah batas alokasi pesanan!');
                return;
            }

            if(!reason) {
                alert('Mohon pilih Alasan Penyesuaian untuk keperluan Audit Log.');
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Stok Terkoreksi',
                text: 'Kuantitas fisik telah disesuaikan menjadi ' + newVal + '. Perubahan dicatat di Log.',
                confirmButtonColor: '#198754'
            });
            let modal = bootstrap.Modal.getInstance(document.getElementById('modalAdjustment'));
            modal.hide();
        });
    });
</script>
@endpush