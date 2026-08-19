@extends('layouts.wms')
@section('page_title', 'Data Stok (Inventory)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-boxes text-primary me-2"></i> Data Stok (Inventory)</h5>
                        <p class="text-muted small mt-1">Pantau ketersediaan stok fisik dan alokasi pesanan di semua gudang.</p>
                    </div>
                    <div>
                        <button class="btn btn-success rounded-pill px-4 shadow-sm" type="button">
                            <i class="bi bi-file-earmark-excel me-1"></i> Ekspor Excel
                        </button>
                    </div>
                </div>

                <!-- Filters & Search -->
                <div class="row g-2 mb-3 align-items-center">
                    <div class="col-md-12 col-lg-3">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" class="form-control border-start-0 bg-white" placeholder="Cari Kode Produksi / SKU...">
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <select class="form-select bg-light">
                            <option selected>Kategori Produk</option>
                            <option value="1">Cat Tembok</option>
                            <option value="2">Cat Kayu</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-1">
                        <input type="text" class="form-control bg-light" list="rakOptions" placeholder="Cari Rak...">
                        <datalist id="rakOptions">
                            <option value="G-03-01">
                            <option value="G-03-02">
                            <option value="G-01-10">
                            <option value="G-05-01">
                        </datalist>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <input type="text" class="form-control bg-light" list="batchOptions" placeholder="Cari Batch...">
                        <datalist id="batchOptions">
                            <option value="BCH-202608-01">
                            <option value="BCH-202607-15">
                            <option value="BCH-202608-05">
                            <option value="BCH-202606-20">
                        </datalist>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted small">Mulai</span>
                            <input type="date" class="form-control bg-light" title="Tanggal Awal">
                            <span class="input-group-text bg-light text-muted small">s/d</span>
                            <input type="date" class="form-control bg-light" title="Tanggal Akhir">
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-1">
                        <button class="btn btn-primary w-100 shadow-sm text-nowrap d-flex align-items-center justify-content-center" style="height: 38px;"><i class="bi bi-funnel me-1"></i> Filter</button>
                    </div>
                </div>
            </div>

            <div class="card-body p-4 pt-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="white-space: nowrap;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold">NO</th>
                                <th class="text-secondary small fw-semibold">SKU</th>
                                <th class="text-secondary small fw-semibold">DESKRIPSI PRODUK</th>
                                <th class="text-secondary small fw-semibold">BATCH NO</th>
                                <th class="text-secondary small fw-semibold text-center">UOM</th>
                                <th class="text-secondary small fw-semibold">LOKASI RAK</th>
                                <th class="text-secondary small fw-semibold text-center">QTY TERSEDIA</th>
                                <th class="text-secondary small fw-semibold text-center">QTY TERALOKASI</th>
                                <th class="text-secondary small fw-semibold">TGL PRODUKSI</th>
                                <th class="text-secondary small fw-semibold">GUDANG</th>
                                <th class="text-secondary small fw-semibold text-center">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($inventories as $idx => $inv)
                            <tr>
                                <td class="text-muted">{{ $idx + 1 }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $inv['sku'] }}</span></td>
                                <td><small class="fw-bold text-dark">{{ $inv['description'] }}</small></td>
                                <td><small class="font-monospace text-muted">{{ $inv['batch_no'] }}</small></td>
                                <td class="text-center"><small class="text-muted">{{ $inv['uom'] }}</small></td>
                                <td><small class="fw-bold text-primary">{{ $inv['location'] }}</small></td>
                                <td class="text-center">
                                    <span class="badge {{ $inv['qty_available'] > 50 ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }} border rounded-pill px-3 py-2 fs-6">
                                        {{ $inv['qty_available'] }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle rounded-pill px-3 py-2">
                                        {{ $inv['qty_allocated'] }}
                                    </span>
                                </td>
                                <td><small class="text-muted">{{ $inv['production_date'] }}</small></td>
                                <td><small class="text-muted">{{ $inv['warehouse'] }}</small></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary btn-adjust" 
                                        data-sku="{{ $inv['sku'] }}" 
                                        data-batch="{{ $inv['batch_no'] }}"
                                        data-loc="{{ $inv['location'] }}"
                                        data-avail="{{ $inv['qty_available'] }}"
                                        data-alloc="{{ $inv['qty_allocated'] }}"
                                        data-bs-toggle="modal" data-bs-target="#modalAdjustment">
                                        <i class="bi bi-sliders"></i> Sesuaikan
                                    </button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
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
                    <strong id="adjSku" class="font-monospace"></strong>
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
                    <i class="bi bi-exclamation-circle"></i> Peringatan: Qty baru tidak boleh kurang dari Qty yang telah dialokasikan untuk pesanan!
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

        // Listen for modal open
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
                document.getElementById('adjNewQty').value = avail; // Default to current
                
                // Reset warning
                document.getElementById('adjNewQty').classList.remove('is-invalid');
                document.getElementById('adjWarning').style.display = 'none';
            });
        });

        // Validate Input
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

        // Save
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

            alert('Berhasil! Stok telah dikoreksi menjadi ' + newVal + '. Perubahan dicatat di Audit Log.');
            let modal = bootstrap.Modal.getInstance(document.getElementById('modalAdjustment'));
            modal.hide();
        });
    });
</script>
@endpush