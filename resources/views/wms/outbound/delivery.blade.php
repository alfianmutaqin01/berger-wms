@extends('layouts.wms')
@section('title', 'Surat Jalan & Pengiriman')
@section('page_title', 'Cetak Surat Jalan & Pengiriman (F-OUT-04)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Proses validasi stok fisik via Excel, pencetakan dokumen, dan pengiriman E-POD ke Supir.</p>
    </div>
</div>

<div class="row g-4">
    <!-- Kolom Kiri: Daftar Pesanan -->
    <div class="col-12 col-lg-5">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Antrean Siap Kirim</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush border-top" id="poList">
                    <!-- PO-2608-001 (Match Scenario) -->
                    <button type="button" class="list-group-item list-group-item-action p-4 border-bottom" onclick="selectPO('PO-2608-001', 'CV Bangun Jaya', 'match')" id="btn-PO-2608-001">
                        <div class="d-flex w-100 justify-content-between mb-1">
                            <h6 class="mb-0 fw-bold text-dark po-title">PO-2608-001</h6>
                            <small class="text-muted">18 Ags 2026</small>
                        </div>
                        <p class="mb-1 text-dark fw-semibold">CV Bangun Jaya</p>
                        <small class="text-muted"><i class="bi bi-pin-map me-1"></i> Dispatch: WH-01 (Karawang)</small>
                    </button>
                    <!-- PO-2608-002 (Mismatch Scenario) -->
                    <button type="button" class="list-group-item list-group-item-action p-4 border-bottom" onclick="selectPO('PO-2608-002', 'PT Sentosa Abadi', 'mismatch')" id="btn-PO-2608-002">
                        <div class="d-flex w-100 justify-content-between mb-1">
                            <h6 class="mb-0 fw-bold text-dark po-title">PO-2608-002</h6>
                            <small class="text-muted">18 Ags 2026</small>
                        </div>
                        <p class="mb-1 text-dark fw-semibold">PT Sentosa Abadi</p>
                        <small class="text-muted"><i class="bi bi-pin-map me-1"></i> Dispatch: WH-01 (Karawang)</small>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Detail & Aksi -->
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm border-0 rounded-4 h-100" id="detailCard" style="display: none;">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-check text-primary me-2"></i>Verifikasi & Penerbitan SJ: <span class="text-primary" id="detailPoTitle">PO-000</span></h6>
            </div>
            <div class="card-body p-4">
                
                <!-- Tahap 1: Validasi Excel -->
                <div class="border rounded-4 p-4 mb-4 bg-light" id="step1">
                    <h6 class="fw-bold mb-3"><span class="badge bg-secondary rounded-circle me-2">1</span> Konfirmasi Fisik (Upload Excel)</h6>
                    <p class="small text-muted mb-3">Silakan unggah data barang dari proses perhitungan fisik (Stock Opname) untuk dicocokkan dengan data sistem WMS.</p>
                    
                    <div class="input-group mb-3">
                        <input type="file" class="form-control" id="excelFile" accept=".xlsx, .xls">
                        <button class="btn btn-outline-primary" type="button" id="btnUploadExcel"><i class="bi bi-cloud-upload me-1"></i> Periksa Data</button>
                    </div>

                    <!-- Hasil Validasi -->
                    <div id="validationResult" class="d-none mt-4">
                        <div id="validationAlert" class="alert d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between py-3 mb-3 gap-3" role="alert">
                            <div class="d-flex align-items-center">
                                <i id="validationIcon" class="bi fs-4 me-3"></i>
                                <div>
                                    <h6 class="fw-bold mb-1" id="validationTitle">Status</h6>
                                    <span class="small fw-semibold" id="validationMessage">Message</span>
                                </div>
                            </div>
                            <div id="validationAction"></div>
                        </div>

                        <!-- Tabel Komparasi -->
                        <h6 class="fw-bold text-dark mb-2 mt-4 small">Rincian Komparasi (WMS vs Fisik/Excel)</h6>
                        <div class="table-responsive border rounded-3 bg-white">
                            <table class="table table-sm table-bordered mb-0 text-center align-middle" style="font-size: 0.8rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-start px-2">SKU</th>
                                        <th>Qty WMS</th>
                                        <th>Qty Fisik (Excel)</th>
                                        <th>Selisih</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="comparisonTableBody">
                                    <!-- Diisi via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tahap 2: Data Pengiriman -->
                <div class="border rounded-4 p-4 opacity-50" id="step2">
                    <h6 class="fw-bold mb-3"><span class="badge bg-secondary rounded-circle me-2">2</span> Data Kendaraan & Supir</h6>
                    
                    <form action="#" method="POST" id="sjForm">
                        @csrf
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-muted">Nama Supir <span class="text-danger">*</span></label>
                                <input type="text" class="form-control sj-input" name="nama_supir" required disabled placeholder="Misal: Budi Santoso">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold text-muted">Plat Kendaraan <span class="text-danger">*</span></label>
                                <input type="text" class="form-control sj-input" name="plat_nomor" required disabled placeholder="Misal: B 1234 CD">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-semibold text-muted">Nomor WhatsApp Supir <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-whatsapp text-success"></i></span>
                                    <input type="text" class="form-control sj-input border-start-0" name="wa_supir" required disabled placeholder="08123456789 (Tanpa kode negara)">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary px-4 rounded-pill shadow-sm sj-input" disabled id="btnCetakSJ">
                                <i class="bi bi-printer me-2"></i>Cetak SJ & Kirim WA
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
        
        <!-- Placeholder -->
        <div class="card shadow-sm border-0 rounded-4 h-100 d-flex align-items-center justify-content-center bg-light text-muted" id="emptyState">
            <div class="text-center p-5">
                <i class="bi bi-inbox fs-1 mb-3 text-secondary"></i>
                <h6>Pilih pesanan dari daftar antrean siap kirim</h6>
            </div>
        </div>

    </div>
</div>

@endsection

@push('scripts')
<script>
    let currentScenario = '';
    let currentSelectedPo = '';

    function selectPO(poNumber, customer, scenario) {
        currentScenario = scenario;
        currentSelectedPo = poNumber;
        
        // Reset UI
        document.getElementById('emptyState').classList.remove('d-flex');
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('detailCard').style.display = 'block';
        document.getElementById('detailCard').classList.remove('d-none');
        document.getElementById('detailPoTitle').textContent = poNumber;
        document.getElementById('validationResult').classList.add('d-none');
        const actionDiv = document.getElementById('validationAction');
        if (actionDiv) actionDiv.innerHTML = '';
        document.getElementById('step2').classList.add('opacity-50');
        document.getElementById('excelFile').value = '';
        document.querySelectorAll('.sj-input').forEach(el => el.disabled = true);

        // Highlight Active Sidebar Item
        document.querySelectorAll('#poList button').forEach(btn => {
            btn.classList.remove('bg-primary-subtle');
            btn.querySelector('.po-title').classList.remove('text-primary');
            btn.querySelector('.po-title').classList.add('text-dark');
        });
        const activeBtn = document.getElementById('btn-' + poNumber);
        activeBtn.classList.add('bg-primary-subtle');
        activeBtn.querySelector('.po-title').classList.remove('text-dark');
        activeBtn.querySelector('.po-title').classList.add('text-primary');
    }

    document.getElementById('btnUploadExcel').addEventListener('click', function() {
        const fileInput = document.getElementById('excelFile');
        if(!fileInput.value) {
            alert("Harap pilih file Excel terlebih dahulu!");
            return;
        }

        const btn = this;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Memproses...';
        btn.disabled = true;

        setTimeout(() => {
            btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i> Periksa Data';
            btn.disabled = false;
            
            const validationResult = document.getElementById('validationResult');
            const alertBox = document.getElementById('validationAlert');
            const icon = document.getElementById('validationIcon');
            const title = document.getElementById('validationTitle');
            const message = document.getElementById('validationMessage');
            const tbody = document.getElementById('comparisonTableBody');
            const step2 = document.getElementById('step2');
            const actionDiv = document.getElementById('validationAction');
            
            validationResult.classList.remove('d-none');

            if(currentScenario === 'match') {
                // Success Scenario
                alertBox.className = 'alert alert-success d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between py-3 mb-3 gap-3';
                icon.className = 'bi bi-check-circle-fill fs-4 me-3 text-success';
                title.textContent = 'Pencocokan Sukses!';
                title.className = 'fw-bold mb-1 text-success';
                message.textContent = 'Data fisik 100% sesuai dengan data WMS (2 Item, 120 Qty).';
                if (actionDiv) actionDiv.innerHTML = '';

                tbody.innerHTML = `
                    <tr>
                        <td class="text-start px-2 fw-semibold">BP-5KG-WHT</td>
                        <td>100</td>
                        <td>100</td>
                        <td class="text-success">0</td>
                        <td><span class="badge bg-success">Match</span></td>
                    </tr>
                    <tr>
                        <td class="text-start px-2 fw-semibold">BP-20KG-BLU</td>
                        <td>20</td>
                        <td>20</td>
                        <td class="text-success">0</td>
                        <td><span class="badge bg-success">Match</span></td>
                    </tr>
                `;

                // Enable Step 2
                step2.classList.remove('opacity-50');
                document.querySelectorAll('.sj-input').forEach(input => input.disabled = false);

            } else {
                // Mismatch Scenario
                alertBox.className = 'alert alert-danger d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between py-3 mb-3 gap-3';
                icon.className = 'bi bi-exclamation-triangle-fill fs-4 me-3 text-danger';
                title.textContent = 'Ditemukan Selisih (Mismatch)!';
                title.className = 'fw-bold mb-1 text-danger';
                message.textContent = 'Ada perbedaan jumlah stok antara WMS dan fisik. Lakukan penyesuaian agar sama dengan data Excel fisik.';
                
                if (actionDiv) {
                    actionDiv.innerHTML = '<button type="button" class="btn btn-sm btn-danger shadow-sm text-nowrap" onclick="autoAdjustStock()"><i class="bi bi-arrow-repeat me-1"></i> Sesuaikan Otomatis</button>';
                }

                tbody.innerHTML = `
                    <tr id="row-bp5kg">
                        <td class="text-start px-2 fw-semibold">BP-5KG-WHT</td>
                        <td id="qty-wms-bp5kg">100</td>
                        <td class="text-danger fw-bold">98</td>
                        <td class="text-danger fw-bold" id="diff-bp5kg">-2</td>
                        <td id="status-bp5kg"><span class="badge bg-danger">Mismatch</span></td>
                    </tr>
                    <tr>
                        <td class="text-start px-2 fw-semibold">BP-20KG-BLU</td>
                        <td>50</td>
                        <td>50</td>
                        <td class="text-success">0</td>
                        <td><span class="badge bg-success">Match</span></td>
                    </tr>
                `;

                // Keep Step 2 disabled
                step2.classList.add('opacity-50');
                document.querySelectorAll('.sj-input').forEach(input => input.disabled = true);
            }

        }, 1200);
    });

    function autoAdjustStock() {
        Swal.fire({
            title: 'Konfirmasi Penyesuaian',
            html: 'Sistem akan menyesuaikan stok WMS <b>mengikuti data Excel fisik (98 Qty)</b> secara otomatis. Lanjutkan?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Sesuaikan Data'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Memproses Penyesuaian...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });
                
                setTimeout(() => {
                    // Update UI to Match scenario
                    const alertBox = document.getElementById('validationAlert');
                    const icon = document.getElementById('validationIcon');
                    const title = document.getElementById('validationTitle');
                    const message = document.getElementById('validationMessage');
                    const actionDiv = document.getElementById('validationAction');
                    const step2 = document.getElementById('step2');
                    
                    alertBox.className = 'alert alert-success d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between py-3 mb-3 gap-3';
                    icon.className = 'bi bi-check-circle-fill fs-4 me-3 text-success';
                    title.textContent = 'Penyesuaian Berhasil!';
                    title.className = 'fw-bold mb-1 text-success';
                    message.textContent = 'Data WMS telah dikurangi 2 Qty dan kini 100% cocok dengan data fisik.';
                    if (actionDiv) actionDiv.innerHTML = '';
                    
                    // Update Table Row
                    document.getElementById('qty-wms-bp5kg').innerHTML = '<span class="text-success fw-bold">98</span>';
                    document.getElementById('diff-bp5kg').innerHTML = '<span class="text-success fw-bold">0</span>';
                    document.getElementById('diff-bp5kg').className = 'text-success fw-bold';
                    document.getElementById('status-bp5kg').innerHTML = '<span class="badge bg-success">Match</span>';
                    
                    // Enable Step 2
                    step2.classList.remove('opacity-50');
                    document.querySelectorAll('.sj-input').forEach(input => input.disabled = false);
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Sukses',
                        text: 'Stok berhasil diseimbangkan.',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }, 1200);
            }
        });
    }

    document.getElementById('sjForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const namaSupir = document.querySelector('input[name="nama_supir"]').value;
        const plat = document.querySelector('input[name="plat_nomor"]').value;
        const wa = document.querySelector('input[name="wa_supir"]').value;

        Swal.fire({
            title: 'Memproses Dokumen...',
            html: 'Menerbitkan Surat Jalan dan Mengirim E-POD ke WhatsApp <strong>' + namaSupir + '</strong>',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        setTimeout(() => {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                html: `
                    <div class="text-start mt-3">
                        <ul class="list-group list-group-flush mb-3">
                            <li class="list-group-item px-0"><i class="bi bi-printer text-primary me-2"></i> Surat Jalan Fisik siap dicetak.</li>
                            <li class="list-group-item px-0"><i class="bi bi-whatsapp text-success me-2"></i> Link E-POD terkirim ke ${wa}.</li>
                        </ul>
                        <div class="alert alert-success border-success bg-success-subtle p-3 text-center mb-0 mt-3 rounded-4">
                            <p class="small fw-bold text-success mb-2">Simulasi Klik Link WA Supir:</p>
                            <a href="/epod/${currentSelectedPo}" target="_blank" class="btn btn-sm btn-success rounded-pill px-4"><i class="bi bi-phone me-1"></i> Buka Layar Supir (E-POD)</a>
                        </div>
                    </div>
                `,
                confirmButtonColor: '#198754',
                confirmButtonText: 'Tutup'
            });
        }, 2000);
    });
</script>
@endpush