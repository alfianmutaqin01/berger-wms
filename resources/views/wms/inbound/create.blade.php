@extends('layouts.wms')
@section('page_title', 'Input Produksi (Inbound)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-arrow-in-right text-primary me-2"></i> Form Inbound Produksi</h5>
                <p class="text-muted small mt-1">Role: Tim Produksi. Unggah file hasil produksi untuk memecah palet secara otomatis.</p>
            </div>
            <div class="card-body p-4">
                <form id="inboundForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">No. Dokumen Produksi</label>
                            <input type="text" class="form-control" id="doc_no" placeholder="Contoh: PROD-202608-001" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Tanggal Produksi</label>
                            <input type="date" class="form-control" id="prod_date" required>
                        </div>
                        <div class="col-12 mt-4">
                            <label class="form-label fw-semibold text-secondary small">Upload File Excel (Rincian Item)</label>
                            <div class="border border-2 border-dashed rounded-3 p-5 text-center bg-light" id="dropZone" style="cursor: pointer; border-style: dashed !important; border-color: #cbd5e1 !important;">
                                <i class="bi bi-file-earmark-excel text-success" style="font-size: 3rem;"></i>
                                <h6 class="mt-3 fw-bold text-dark">Pilih atau Tarik File Excel ke Sini</h6>
                                <p class="text-muted small mb-0">Format yang didukung: .xlsx, .xls</p>
                                <input type="file" id="excelFile" class="d-none" accept=".xlsx, .xls">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 text-end">
                        <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" id="btnPreview" disabled>
                            <i class="bi bi-eye me-1"></i> Preview Pecahan Palet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Preview Section (Hidden by default) -->
<div class="row d-none" id="previewSection">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4 border-top border-primary border-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">Preview Pecahan Palet Otomatis</h5>
                    <span class="badge bg-success-subtle text-success border border-success px-3 py-2 rounded-pill">
                        <i class="bi bi-check-circle me-1"></i> Validasi Master Data Berhasil
                    </span>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold">PALET NO</th>
                                <th class="text-secondary small fw-semibold">SKU</th>
                                <th class="text-secondary small fw-semibold">DESKRIPSI</th>
                                <th class="text-secondary small fw-semibold">UoM</th>
                                <th class="text-secondary small fw-semibold">BATCH</th>
                                <th class="text-secondary small fw-semibold text-end">QTY / MAKS</th>
                                <th class="text-secondary small fw-semibold text-center">AKSI</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody">
                            <!-- Populated by JS -->
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger px-4" id="btnReset">Batal & Ulangi</button>
                    <button type="button" class="btn btn-success px-5 fw-bold shadow-sm" id="btnSubmitFinal">
                        <i class="bi bi-check2-all me-1"></i> Submit ke Daftar Put-away
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('modals')
<!-- Modal Edit Qty -->
<div class="modal fade" id="editQtyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold text-dark">Edit Kuantitas Produk</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">SKU Produk</label>
                    <input type="text" class="form-control bg-light" id="editSku" readonly>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold text-secondary">Ubah Qty (Max: <span id="maxQtyLimit">180</span>)</label>
                    <input type="number" class="form-control fw-bold" id="editQtyInput" value="180">
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary px-3" onclick="alert('Simulasi: Kuantitas berhasil diubah!'); bootstrap.Modal.getInstance(document.getElementById('editQtyModal')).hide();"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('excelFile');
        const btnPreview = document.getElementById('btnPreview');
        const previewSection = document.getElementById('previewSection');
        const previewTableBody = document.getElementById('previewTableBody');
        
        function simulateFileUpload() {
            dropZone.innerHTML = 
                '<i class="bi bi-file-earmark-check text-primary" style="font-size: 3rem;"></i>' +
                '<h6 class="mt-3 fw-bold text-primary">Data_Produksi_Shift_Pagi.xlsx</h6>' +
                '<p class="text-muted small mb-0">File siap diproses</p>';
            dropZone.classList.replace('bg-light', 'bg-primary-subtle');
            dropZone.style.borderColor = '#93c5fd !important';
            btnPreview.disabled = false;
            
            // Auto-fill form
            document.getElementById('doc_no').value = 'PROD-202608-001';
            document.getElementById('prod_date').value = '2026-08-19';
        }

        // Otomatis terisi saat halaman dimuat
        setTimeout(() => {
            simulateFileUpload();
            btnPreview.click();
        }, 500);

        btnPreview.addEventListener('click', function() {
            // Check manual fields
            if(!document.getElementById('doc_no').value || !document.getElementById('prod_date').value) {
                alert('Mohon isi Nomor Dokumen dan Tanggal terlebih dahulu.');
                return;
            }

            const originalBtnText = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Memproses...';
            this.disabled = true;

            // Fetch mock data from Controller
            fetch('/wms/inbound/preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() ?? "" }}'
                }
            })
            .then(res => res.json())
            .then(data => {
                this.innerHTML = originalBtnText;
                
                if(data.status === 'success') {
                    // Populate Table
                    previewTableBody.innerHTML = '';
                    data.data.forEach(item => {
                        let isFull = item.qty === item.max_cap;
                        let badgeClass = isFull ? 'bg-primary-subtle text-primary border-primary' : 'bg-warning-subtle text-warning border-warning';
                        
                        let tr = document.createElement('tr');
                        tr.innerHTML = 
                            '<td class="fw-bold">#'+item.pallet_no+'</td>' +
                            '<td><span class="badge bg-light text-dark border">'+item.sku+'</span></td>' +
                            '<td><small>'+item.description+'</small></td>' +
                            '<td>'+item.uom+'</td>' +
                            '<td><small class="font-monospace text-muted">'+item.batch+'</small></td>' +
                            '<td class="text-end">' +
                                '<span class="badge border '+badgeClass+' px-2 py-1">'+item.qty+' / '+item.max_cap+'</span>' +
                            '</td>' +
                            '<td class="text-center">' +
                                '<button type="button" class="btn btn-sm btn-outline-secondary" title="Edit Qty Manual" onclick="openEditModal(\''+item.sku+'\', '+item.qty+', '+item.max_cap+')"><i class="bi bi-pencil"></i></button>' +
                            '</td>';
                        previewTableBody.appendChild(tr);
                    });

                    // Show section
                    previewSection.classList.remove('d-none');
                    // Scroll to preview
                    previewSection.scrollIntoView({ behavior: 'smooth' });
                }
            })
            .catch(err => {
                alert('Terjadi kesalahan koneksi.');
                this.innerHTML = originalBtnText;
                this.disabled = false;
            });
        });

        // Reset
        document.getElementById('btnReset').addEventListener('click', function() {
            previewSection.classList.add('d-none');
            fileInput.value = '';
            dropZone.innerHTML = 
                '<i class="bi bi-file-earmark-excel text-success" style="font-size: 3rem;"></i>' +
                '<h6 class="mt-3 fw-bold text-dark">Pilih atau Tarik File Excel ke Sini</h6>' +
                '<p class="text-muted small mb-0">Format yang didukung: .xlsx, .xls</p>';
            dropZone.classList.replace('bg-primary-subtle', 'bg-light');
            dropZone.style.borderColor = '#cbd5e1 !important';
            btnPreview.disabled = true;
        });

        // Final Submit
        document.getElementById('btnSubmitFinal').addEventListener('click', function() {
            alert('Data berhasil disimpan! Status Inbound kini: Menunggu Put-away.');
            window.location.href = '/wms/inbound/putaway';
        });

        window.openEditModal = function(sku, currentQty, maxQty) {
            document.getElementById('editSku').value = sku;
            document.getElementById('editQtyInput').value = currentQty;
            document.getElementById('maxQtyLimit').innerText = maxQty;
            document.getElementById('editQtyInput').max = maxQty;
            var myModal = new bootstrap.Modal(document.getElementById('editQtyModal'));
            myModal.show();
        }
    });
</script>
@endpush