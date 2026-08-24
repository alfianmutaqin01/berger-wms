@extends('layouts.wms')

@section('title', 'Master Produk')
@section('page_title', 'Master Data Produk')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i> Master Data Produk</h5>
                    <p class="text-muted small mt-1 mb-0">Daftar referensi seluruh SKU produk tanpa memuat informasi jumlah stok.</p>
                </div>
                <div>
                    
                    <button type="button" class="btn btn-outline-secondary fw-bold shadow-sm me-2" data-bs-toggle="modal" data-bs-target="#importExcelModal">
                        <i class="bi bi-upload me-1"></i> Import Excel
                    </button>
                    <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="bi bi-plus-circle me-1"></i> Tambah Produk
                    </button>
                </div>
            </div>
            <div class="card-body p-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="input-group" style="max-width: 350px;">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Cari SAPSKU atau Description...">
                    </div>
                    <div class="d-flex gap-2">
                        <select class="form-select bg-light border-0" style="width: 150px;">
                            <option value="">Semua Type</option>
                            <option value="Alk Primer">Alk Primer</option>
                            <option value="AMC">AMC</option>
                            <option value="Apex Emulsion">Apex Emulsion</option>
                        </select>
                        <select class="form-select bg-light border-0" style="width: 130px;">
                            <option value="">Semua UoM</option>
                            <option value="TIN">TIN</option>
                            <option value="PAIL">PAIL</option>
                            <option value="CAN">CAN</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-center" style="width: 50px;">NO</th>
                                <th class="text-secondary small fw-semibold">SAPSKU</th>
                                <th class="text-secondary small fw-semibold" style="min-width: 280px;">DESCRIPTION</th>
                                <th class="text-secondary small fw-semibold">PRODUCT TYPE</th>
                                <th class="text-secondary small fw-semibold text-center">UoM</th>
                                <th class="text-secondary small fw-semibold text-end">GROSS WEIGHT</th>
                                <th class="text-secondary small fw-semibold text-end">NET WEIGHT</th>
                                <th class="text-secondary small fw-semibold text-center pe-3">AKSI</th>
                            </tr>
                        </thead>
                        <tbody id="productTableBody">
                            <tr>
                                <td class="text-center text-muted">1</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F03603202804</td>
                                <td class="text-dark">Trucare Alkali Resist Primer White 4Kg</td>
                                <td><span class="badge bg-secondary text-white">Alk Primer</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">TIN</span></td>
                                <td class="text-end font-monospace text-muted">4.60</td>
                                <td class="text-end font-monospace fw-bold text-dark">4.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editProduct(this)"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">2</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F03603202820</td>
                                <td class="text-dark">Trucare Alkali Resist Primer White 20Kg</td>
                                <td><span class="badge bg-secondary text-white">Alk Primer</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">PAIL</span></td>
                                <td class="text-end font-monospace text-muted">21.20</td>
                                <td class="text-end font-monospace fw-bold text-dark">20.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editProduct(this)"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">3</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F13150111210</td>
                                <td class="text-dark">AMC Fast Blue 1Ltr</td>
                                <td><span class="badge bg-info text-dark">AMC</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">CAN</span></td>
                                <td class="text-end font-monospace text-muted">1.30</td>
                                <td class="text-end font-monospace fw-bold text-dark">1.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editProduct(this)"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">4</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F13150216210</td>
                                <td class="text-dark">AMC Fast Green 1Ltr</td>
                                <td><span class="badge bg-info text-dark">AMC</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">CAN</span></td>
                                <td class="text-end font-monospace text-muted">1.37</td>
                                <td class="text-end font-monospace fw-bold text-dark">1.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editProduct(this)"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">5</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F00123202225</td>
                                <td class="text-dark">Apex Emulsion White 2.5Ltr</td>
                                <td><span class="badge bg-primary text-white">Apex Emulsion</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">TIN</span></td>
                                <td class="text-end font-monospace text-muted">4.21</td>
                                <td class="text-end font-monospace fw-bold text-dark">2.500</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editProduct(this)"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-center text-muted">6</td>
                                <td class="font-monospace fw-bold text-dark">ID1-F00123708320</td>
                                <td class="text-dark">Apex Emulsion Harvest Cream 20Ltr</td>
                                <td><span class="badge bg-primary text-white">Apex Emulsion</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border">PAIL</span></td>
                                <td class="text-end font-monospace text-muted">28.06</td>
                                <td class="text-end font-monospace fw-bold text-dark">20.000</td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="editProduct(this)"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('modals')
<!-- Modal Tambah Produk -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-box-seam text-primary me-2"></i> Tambah Master Produk</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">SAPSKU *</label>
                    <input type="text" class="form-control font-monospace" id="inpSku" placeholder="e.g., ID1-F036...">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Description *</label>
                    <input type="text" class="form-control" id="inpDesc" placeholder="Nama lengkap produk...">
                </div>
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Product Type</label>
                        <select class="form-select" id="inpType" onchange="checkNewOption(this, 'Product Type')">
                            <option value="" disabled selected>Pilih Type...</option>
                            <option value="Alk Primer">Alk Primer</option>
                            <option value="AMC">AMC</option>
                            <option value="Apex Emulsion">Apex Emulsion</option>
                            <option value="ADD_NEW" class="text-primary fw-bold">+ Tambah Type Baru...</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">UoM (Kemasan)</label>
                        <select class="form-select" id="inpUom" onchange="checkNewOption(this, 'UoM (Kemasan)')">
                            <option value="PAIL">PAIL</option>
                            <option value="TIN">TIN</option>
                            <option value="CAN">CAN</option>
                            <option value="ADD_NEW" class="text-primary fw-bold">+ Tambah Kemasan Baru...</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-0">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Gross Weight</label>
                        <input type="number" step="0.01" class="form-control" id="inpGross" placeholder="0.00">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Net Weight</label>
                        <input type="number" step="0.01" class="form-control" id="inpNet" placeholder="0.000">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" id="btnSaveProduct" onclick="saveProduct()"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </div>
    </div>
</div>
<!-- Modal Import Excel -->
<div class="modal fade" id="importExcelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Import Produk dari Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="row align-items-end mb-4">
                    <div class="col-md-9">
                        <label class="form-label small fw-semibold text-secondary">Pilih File Excel (.xlsx / .xls)</label>
                        <input class="form-control" type="file" id="modalFileExcel" accept=".xlsx, .xls">
                        <p class="small text-muted mt-1 mb-0">Format urutan kolom: SAPSKU | Description | Product Type | UoM | Gross Weight | Net Weight</p>
                    </div>
                    <div class="col-md-3 text-end">
                        <button type="button" class="btn btn-primary w-100 fw-bold shadow-sm" id="btnPreviewExcel">
                            <i class="bi bi-search me-1"></i> Preview Data
                        </button>
                    </div>
                </div>

                <!-- Preview Area -->
                <div id="previewArea" class="d-none">
                    <hr class="mb-4">
                    <h6 class="fw-bold mb-3">Preview Data <span class="badge bg-secondary rounded-pill ms-2" id="previewCount">0 baris</span></h6>
                    
                    <div class="table-responsive border rounded-3" style="max-height: 350px;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th class="small">NO</th>
                                    <th class="small">SAPSKU</th>
                                    <th class="small">DESCRIPTION</th>
                                    <th class="small">TYPE</th>
                                    <th class="small">UOM</th>
                                    <th class="small text-end">GROSS</th>
                                    <th class="small text-end">NET</th>
                                    <th class="small text-center">STATUS</th>
                                </tr>
                            </thead>
                            <tbody id="previewTableBody">
                                <!-- Preview rows will be injected here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success px-4 fw-bold shadow-sm" id="btnSaveImport" disabled><i class="bi bi-database-add me-1"></i> Simpan ke Database</button>
            </div>
        </div>
    </div>
</div>
<!-- Modal Edit Produk -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i> Edit Master Produk</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">SAPSKU *</label>
                    <input type="text" class="form-control font-monospace bg-light" id="editSku" readonly>
                    <small class="text-muted" style="font-size: 0.7rem;">SAPSKU tidak dapat diubah (Primary Key)</small>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Description *</label>
                    <input type="text" class="form-control" id="editDesc">
                </div>
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Product Type</label>
                        <select class="form-select" id="editType" onchange="checkNewOption(this, 'Product Type')">
                            <option value="Alk Primer">Alk Primer</option>
                            <option value="AMC">AMC</option>
                            <option value="Apex Emulsion">Apex Emulsion</option>
                            <option value="ADD_NEW" class="text-primary fw-bold">+ Tambah Type Baru...</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">UoM (Kemasan)</label>
                        <select class="form-select" id="editUom" onchange="checkNewOption(this, 'UoM (Kemasan)')">
                            <option value="PAIL">PAIL</option>
                            <option value="TIN">TIN</option>
                            <option value="CAN">CAN</option>
                            <option value="ADD_NEW" class="text-primary fw-bold">+ Tambah Kemasan Baru...</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-0">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Gross Weight</label>
                        <input type="number" step="0.01" class="form-control" id="editGross">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Net Weight</label>
                        <input type="number" step="0.01" class="form-control" id="editNet">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" id="btnUpdateProduct"><i class="bi bi-save me-1"></i> Update Data</button>
            </div>
        </div>
    </div>
</div>
@endpush
@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
    let rowCount = 6;
    window.rowCount = rowCount;

    // --- IMPORT EXCEL MODAL WORKFLOW ---
    window.excelPreviewData = [];

    document.getElementById('btnPreviewExcel').addEventListener('click', function() {
        const fileInput = document.getElementById('modalFileExcel');
        const file = fileInput.files[0];
        
        if (!file) {
            Swal.fire('File Kosong', 'Harap pilih file Excel terlebih dahulu.', 'warning');
            return;
        }

        const btn = this;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Membaca...';
        btn.disabled = true;

        const reader = new FileReader();
        reader.onload = function(event) {
            try {
                const data = new Uint8Array(event.target.result);
                const workbook = XLSX.read(data, {type: 'array'});
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                const json = XLSX.utils.sheet_to_json(worksheet, {header: 1});
                
                if (json.length > 0) json.shift();

                window.excelPreviewData = [];
                const tbody = document.getElementById('previewTableBody');
                tbody.innerHTML = '';
                
                let count = 0;
                
                json.forEach((row, index) => {
                    if (row && row[0] && row[1]) {
                        count++;
                        const sku = row[0] || '';
                        const desc = row[1] || '';
                        const type = row[2] || '-';
                        const uom = row[3] || 'PAIL';
                        const gross = row[4] || '0.00';
                        const net = row[5] || '0.000';
                        
                        window.excelPreviewData.push({sku, desc, type, uom, gross, net});
                        
                        let typeColor = 'secondary';
                        if(type.includes('AMC')) typeColor = 'info text-dark';
                        if(type.includes('Emulsion')) typeColor = 'primary';
                        
                        tbody.innerHTML += `
                            <tr>
                                <td class="small text-muted">${count}</td>
                                <td class="small font-monospace fw-bold">${sku}</td>
                                <td class="small">${desc}</td>
                                <td><span class="badge bg-${typeColor}">${type}</span></td>
                                <td class="small font-monospace">${uom}</td>
                                <td class="small text-end font-monospace">${Number(gross).toFixed(2)}</td>
                                <td class="small text-end font-monospace fw-bold">${Number(net).toFixed(3)}</td>
                                <td class="text-center"><i class="bi bi-check-circle-fill text-success"></i></td>
                            </tr>
                        `;
                    }
                });

                document.getElementById('previewCount').textContent = count + ' baris valid';
                document.getElementById('previewArea').classList.remove('d-none');
                
                if (count > 0) {
                    document.getElementById('btnSaveImport').disabled = false;
                } else {
                    document.getElementById('btnSaveImport').disabled = true;
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">Tidak ada data valid ditemukan.</td></tr>';
                }

            } catch (err) {
                console.error(err);
                Swal.fire('Gagal Membaca Excel', 'Pastikan file memiliki format tabel yang valid (.xlsx/.xls).', 'error');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        };
        
        reader.readAsArrayBuffer(file);
    });

    document.getElementById('btnSaveImport').addEventListener('click', function() {
        if(window.excelPreviewData.length === 0) return;
        
        const btn = this;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...';
        btn.disabled = true;
        
        setTimeout(() => {
            let added = 0;
            window.excelPreviewData.forEach(item => {
                appendRow(item.sku, item.desc, item.type, item.uom, item.gross, item.net);
                added++;
            });
            
            const modalEl = document.getElementById('importExcelModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.hide();
            
            document.getElementById('modalFileExcel').value = '';
            document.getElementById('previewArea').classList.add('d-none');
            document.getElementById('previewTableBody').innerHTML = '';
            btn.innerHTML = originalText;
            btn.disabled = true;
            window.excelPreviewData = [];

            Swal.fire({
                icon: 'success',
                title: 'Import Berhasil!',
                html: `<b>${added} Produk</b> baru berhasil di-import dan tersimpan di database.`,
                confirmButtonColor: '#198754'
            });
        }, 1200);
    });

    // --- TAMBAH PRODUK MANUAL ---
    function saveProduct() {
        const sku = document.getElementById('inpSku').value;
        const desc = document.getElementById('inpDesc').value;
        const type = document.getElementById('inpType').value || '-';
        const uom = document.getElementById('inpUom').value;
        const gross = document.getElementById('inpGross').value || '0.00';
        const net = document.getElementById('inpNet').value || '0.000';

        if (!sku || !desc) {
            Swal.fire('Form Tidak Lengkap', 'Kolom SAPSKU dan Description wajib diisi.', 'warning');
            return;
        }

        const btn = document.getElementById('btnSaveProduct');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...';
        btn.disabled = true;

        setTimeout(() => {
            appendRow(sku, desc, type, uom, gross, net);
            
            const modalEl = document.getElementById('addProductModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.hide();

            document.getElementById('inpSku').value = '';
            document.getElementById('inpDesc').value = '';
            document.getElementById('inpType').selectedIndex = 0;
            document.getElementById('inpUom').selectedIndex = 0;
            document.getElementById('inpGross').value = '';
            document.getElementById('inpNet').value = '';

            btn.innerHTML = originalText;
            btn.disabled = false;

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Produk berhasil disimpan',
                showConfirmButton: false,
                timer: 2000
            });
        }, 800);
    }

    function appendRow(sku, desc, type, uom, gross, net) {
        window.rowCount++;
        
        let typeBadgeColor = 'secondary';
        if (type.includes('AMC')) typeBadgeColor = 'info text-dark';
        if (type.includes('Emulsion')) typeBadgeColor = 'primary';
        
        const tr = document.createElement('tr');
        tr.className = 'animate__animated animate__fadeIn bg-success-subtle';
        tr.innerHTML = `
            <td class="text-center text-muted">${window.rowCount}</td>
            <td class="font-monospace fw-bold text-dark">${sku}</td>
            <td class="text-dark">${desc}</td>
            <td><span class="badge bg-${typeBadgeColor}">${type}</span></td>
            <td class="text-center"><span class="badge bg-light text-dark border">${uom}</span></td>
            <td class="text-end font-monospace text-muted">${Number(gross).toFixed(2)}</td>
            <td class="text-end font-monospace fw-bold text-dark">${Number(net).toFixed(3)}</td>
            <td class="text-center pe-3">
                <button class="btn btn-sm btn-outline-secondary" onclick="editProduct(this)"><i class="bi bi-pencil"></i></button>
            </td>
        `;
        
        document.getElementById('productTableBody').appendChild(tr);

        setTimeout(() => {
            tr.classList.remove('bg-success-subtle', 'animate__animated', 'animate__fadeIn');
        }, 1500);
    }

    // --- DYNAMIC OPTION UNTUK TYPE & UOM ---
    function checkNewOption(selectObj, label) {
        if (selectObj.value === 'ADD_NEW') {
            selectObj.selectedIndex = 0;
            
            Swal.fire({
                title: 'Tambah ' + label,
                input: 'text',
                inputPlaceholder: 'Masukkan nama ' + label + ' baru...',
                showCancelButton: true,
                confirmButtonText: 'Tambahkan',
                cancelButtonText: 'Batal',
                inputValidator: (value) => {
                    if (!value) return 'Nama tidak boleh kosong!';
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    const newValue = result.value.trim().toUpperCase();
                    let exists = false;
                    for (let i = 0; i < selectObj.options.length; i++) {
                        if (selectObj.options[i].value.toUpperCase() === newValue) {
                            exists = true;
                            selectObj.selectedIndex = i;
                            break;
                        }
                    }
                    
                    if (!exists) {
                        const addOption = selectObj.querySelector('option[value="ADD_NEW"]');
                        const newOpt = document.createElement('option');
                        newOpt.value = result.value;
                        newOpt.text = result.value;
                        selectObj.insertBefore(newOpt, addOption);
                        selectObj.value = result.value;
                        
                        Swal.fire({
                            toast: true, position: 'top-end', icon: 'success', title: label + ' baru ditambahkan!', showConfirmButton: false, timer: 2000
                        });
                    }
                }
            });
        }
    }

    // --- EDIT PRODUK MANUAL ---
    let currentRowBeingEdited = null;

    window.editProduct = function(btn) {
        const tr = btn.closest('tr');
        currentRowBeingEdited = tr;
        
        const sku = tr.cells[1].innerText.trim();
        const desc = tr.cells[2].innerText.trim();
        const type = tr.cells[3].innerText.trim();
        const uom = tr.cells[4].innerText.trim();
        const gross = tr.cells[5].innerText.trim();
        const net = tr.cells[6].innerText.trim();
        
        document.getElementById('editSku').value = sku;
        document.getElementById('editDesc').value = desc;
        
        ensureOptionExists(document.getElementById('editType'), type);
        document.getElementById('editType').value = type;
        
        ensureOptionExists(document.getElementById('editUom'), uom);
        document.getElementById('editUom').value = uom;
        
        document.getElementById('editGross').value = gross;
        document.getElementById('editNet').value = net;
        
        const editModalEl = document.getElementById('editProductModal');
        const editModal = bootstrap.Modal.getInstance(editModalEl) || new bootstrap.Modal(editModalEl);
        editModal.show();
    };

    function ensureOptionExists(selectEl, val) {
        if (!val || val === '-') return;
        let exists = false;
        for (let i=0; i<selectEl.options.length; i++) {
            if (selectEl.options[i].value === val) {
                exists = true; break;
            }
        }
        if (!exists) {
            const addOption = selectEl.querySelector('option[value="ADD_NEW"]');
            const newOpt = document.createElement('option');
            newOpt.value = val;
            newOpt.text = val;
            selectEl.insertBefore(newOpt, addOption);
        }
    }

    document.getElementById('btnUpdateProduct').addEventListener('click', function() {
        if (!currentRowBeingEdited) return;
        
        const desc = document.getElementById('editDesc').value;
        const type = document.getElementById('editType').value;
        const uom = document.getElementById('editUom').value;
        const gross = document.getElementById('editGross').value || '0.00';
        const net = document.getElementById('editNet').value || '0.000';
        
        if (!desc) {
            Swal.fire('Form Tidak Lengkap', 'Kolom Description wajib diisi.', 'warning');
            return;
        }

        const btn = this;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mengupdate...';
        btn.disabled = true;

        setTimeout(() => {
            currentRowBeingEdited.cells[2].innerText = desc;
            
            let typeColor = 'secondary';
            if(type.includes('AMC')) typeColor = 'info text-dark';
            if(type.includes('Emulsion')) typeColor = 'primary';
            currentRowBeingEdited.cells[3].innerHTML = `<span class="badge bg-${typeColor}">${type}</span>`;
            
            currentRowBeingEdited.cells[4].innerHTML = `<span class="badge bg-light text-dark border">${uom}</span>`;
            currentRowBeingEdited.cells[5].innerText = Number(gross).toFixed(2);
            currentRowBeingEdited.cells[6].innerText = Number(net).toFixed(3);
            
            currentRowBeingEdited.classList.add('bg-warning-subtle', 'animate__animated', 'animate__pulse');
            setTimeout(() => {
                currentRowBeingEdited.classList.remove('bg-warning-subtle', 'animate__animated', 'animate__pulse');
            }, 1500);

            const modalEl = document.getElementById('editProductModal');
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.hide();

            btn.innerHTML = originalText;
            btn.disabled = false;
            currentRowBeingEdited = null;

            Swal.fire({
                toast: true, position: 'top-end', icon: 'success', title: 'Perubahan berhasil disimpan!', showConfirmButton: false, timer: 2000
            });
        }, 600);
    });
</script>
@endpush