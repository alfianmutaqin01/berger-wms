@extends('layouts.wms')
@section('title', 'Cetak Surat Jalan')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Cetak Surat Jalan & Pengiriman (F-OUT-04)</h4>
        <p class="text-muted">Proses validasi stok fisik via Excel, pencetakan dokumen, dan pengiriman E-POD ke Supir.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex flex-column mb-4" role="alert">
    <div class="d-flex align-items-center mb-2">
        <i class="bi bi-check-circle-fill fs-4 me-2"></i> 
        <strong class="fs-5">Sukses!</strong>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <p class="mb-0">{{ session('success') }}</p>
    
    @if(session('epod_link'))
    <hr>
    <p class="mb-2 fw-semibold"><i class="bi bi-whatsapp me-1 text-success"></i> Simulasi Tampilan HP Supir (Magic Link E-POD):</p>
    <a href="{{ session('epod_link') }}" class="btn btn-dark w-auto align-self-start rounded-pill px-4" target="_blank">
        <i class="bi bi-phone me-1"></i> Buka Layar Supir Sekarang
    </a>
    @endif
</div>
@endif

<div class="row g-4">
    <!-- Kolom Kiri: Daftar Pesanan -->
    <div class="col-12 col-lg-5">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Antrean Siap Kirim</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush border-top">
                    <!-- PO-2608-001 -->
                    <button type="button" class="list-group-item list-group-item-action p-4 border-bottom bg-primary-subtle" id="selectPO1">
                        <div class="d-flex w-100 justify-content-between mb-1">
                            <h6 class="mb-0 fw-bold text-primary">PO-2608-001</h6>
                            <small class="text-muted">18 Ags 2026</small>
                        </div>
                        <p class="mb-1 text-dark fw-semibold">CV Bangun Jaya</p>
                        <small class="text-muted"><i class="bi bi-pin-map me-1"></i> Dispatch: WH-01 (Karawang)</small>
                    </button>
                    <!-- PO Lainnya (Kosong) -->
                </div>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Detail & Aksi -->
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm border-0 rounded-4 h-100" id="detailCard" style="display: none;">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-check text-primary me-2"></i>Verifikasi & Penerbitan SJ: <span class="text-primary">PO-2608-001</span></h6>
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

                    <!-- Hasil Validasi (Hidden by default) -->
                    <div id="validationResult" class="d-none">
                        <div class="alert alert-success d-flex align-items-center py-2 mb-0" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <span class="small fw-semibold">Pencocokan Sukses! Data fisik 100% sesuai dengan data WMS (2 Item, 115 Qty).</span>
                        </div>
                    </div>
                </div>

                <!-- Tahap 2: Data Pengiriman (Disabled by default) -->
                <div class="border rounded-4 p-4 opacity-50" id="step2">
                    <h6 class="fw-bold mb-3"><span class="badge bg-secondary rounded-circle me-2">2</span> Data Kendaraan & Supir</h6>
                    
                    <form action="/wms/outbound/generate-sj/PO-2608-001" method="POST" id="sjForm">
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
                                <div class="form-text small">Tautan konfirmasi (E-POD) akan dikirimkan otomatis ke nomor ini.</div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-success px-4 rounded-pill sj-input" disabled onclick="alert('Link konfirmasi E-POD telah dikirim ulang ke WA Supir!')">
                                <i class="bi bi-arrow-repeat me-1"></i> Resend Link WA
                            </button>
                            <button type="submit" class="btn btn-primary px-4 rounded-pill shadow-sm sj-input" disabled id="btnCetakSJ">
                                <i class="bi bi-printer me-2"></i>Cetak SJ & Kirim WA
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
        
        <!-- Placeholder ketika belum memilih -->
        <div class="card shadow-sm border-0 rounded-4 h-100 d-flex align-items-center justify-content-center bg-light text-muted" id="emptyState">
            <div class="text-center p-5">
                <i class="bi bi-inbox fs-1 mb-3 text-secondary"></i>
                <h6>Pilih pesanan dari daftar di sebelah kiri</h6>
            </div>
        </div>

    </div>
</div>

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectPO1 = document.getElementById('selectPO1');
        const detailCard = document.getElementById('detailCard');
        const emptyState = document.getElementById('emptyState');
        
        const btnUploadExcel = document.getElementById('btnUploadExcel');
        const validationResult = document.getElementById('validationResult');
        const step2 = document.getElementById('step2');
        const sjInputs = document.querySelectorAll('.sj-input');
        const excelFile = document.getElementById('excelFile');

        selectPO1.addEventListener('click', function() {
            emptyState.style.display = 'none';
            detailCard.style.display = 'block';
        });

        btnUploadExcel.addEventListener('click', function() {
            if(!excelFile.value) {
                alert("Pilih file Excel terlebih dahulu!");
                return;
            }
            // Mock loading
            btnUploadExcel.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
            btnUploadExcel.disabled = true;

            setTimeout(() => {
                btnUploadExcel.innerHTML = '<i class="bi bi-cloud-upload me-1"></i> Periksa Data';
                btnUploadExcel.disabled = false;
                
                // Show success
                validationResult.classList.remove('d-none');
                
                // Enable Step 2
                step2.classList.remove('opacity-50');
                sjInputs.forEach(input => input.disabled = false);
            }, 1000);
        });
    });
</script>
@endpush