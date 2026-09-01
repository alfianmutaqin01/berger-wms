@extends('layouts.wms')
@section('title', 'Proses Picking')
@section('page_title', 'Tugas Pengambilan Barang (Picking)')

@section('content')
<style>
    .picking-item {
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 2px solid transparent;
    }
    .picking-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.05) !important;
    }
    .picking-item.done {
        border-color: #198754 !important;
        background-color: #f8fff9 !important;
        opacity: 0.85;
    }
    .picking-item.done .sku-title {
        text-decoration: line-through;
        color: #6c757d !important;
    }
    .rack-badge {
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -1px;
    }
    .btn-check-item {
        transition: transform 0.2s, background-color 0.2s;
    }
    .btn-check-item:active {
        transform: scale(0.9);
    }
</style>

<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Aplikasi panduan bagi operator gudang/forklift untuk mengambil barang sesuai alokasi Batch dan Rak secara efisien berdasarkan Daftar Picking (Batch) yang telah dibuat oleh Logistik.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <!-- Antrean Picking -->
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-card-checklist text-primary me-2"></i>Daftar Tugas Picking</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush border-top">
                    <!-- Batch 1 -->
                    <button type="button" class="list-group-item list-group-item-action p-4 border-bottom bg-primary-subtle" onclick="selectTask('BATCH-001')">
                        <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                            <h5 class="mb-0 fw-bold text-primary">Daftar Picking 1</h5>
                            <span class="badge bg-warning text-dark rounded-pill px-3 py-2">0 / 2 Selesai</span>
                        </div>
                        <p class="mb-2 text-dark fw-semibold">2 PO (CV Bangun Jaya, Toko Merah)</p>
                        <div class="d-flex gap-2">
                            <span class="badge bg-white text-dark border"><i class="bi bi-box-seam text-primary me-1"></i> 170 Qty</span>
                            <span class="badge bg-white text-dark border"><i class="bi bi-upc-scan text-primary me-1"></i> 3 SKU</span>
                        </div>
                    </button>
                    <!-- Batch 2 -->
                    <button type="button" class="list-group-item list-group-item-action p-4 border-bottom" onclick="selectTask('BATCH-002')">
                        <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 fw-bold text-dark">Daftar Picking 2</h6>
                            <span class="badge bg-secondary text-white rounded-pill px-3 py-2">Belum Mulai</span>
                        </div>
                        <p class="mb-2 text-dark fw-semibold small">1 PO (PT Mitra Bangunan)</p>
                        <div class="d-flex gap-2">
                            <span class="badge bg-light text-dark border"><i class="bi bi-box-seam me-1"></i> 50 Qty</span>
                            <span class="badge bg-light text-dark border"><i class="bi bi-upc-scan me-1"></i> 1 SKU</span>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <!-- Rincian Picking -->
        <div class="card shadow-sm border-0 rounded-4 h-100" id="pickingDetailCard">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-3 px-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Rute Pengambilan: <span class="text-primary">Daftar Picking 1</span></h5>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm mt-2 mt-sm-0" onclick="printPickingList()"><i class="bi bi-printer me-1"></i> Cetak PDF</button>
                </div>
                <div>
                    <div class="d-flex justify-content-between small text-muted fw-semibold mb-1">
                        <span>Progres Pengambilan</span>
                        <span id="progressText" class="text-success">0%</span>
                    </div>
                    <div class="progress rounded-pill shadow-sm" style="height: 12px;">
                        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="pickingProgress" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
            </div>
            
            <div class="card-body p-4 bg-light rounded-bottom-4">
                
                <!-- Item 1 -->
                <div class="card border-0 shadow-sm rounded-4 mb-3 picking-item" id="item1">
                    <div class="card-body p-0">
                        <div class="row g-0 align-items-stretch">
                            <!-- Rack Area -->
                            <div class="col-3 col-md-2 text-white text-center p-3 rounded-start-4 d-flex flex-column justify-content-center" style="background: linear-gradient(135deg, #0d6efd, #0b5ed7);">
                                <small class="text-white-50 fw-semibold text-uppercase letter-spacing-1 mb-1">LOKASI</small>
                                <span class="rack-badge mb-0">G-12</span>
                            </div>
                            
                            <!-- Detail Area -->
                            <div class="col-6 col-md-8 p-3 p-md-4 d-flex flex-column justify-content-center">
                                <span class="font-monospace text-primary fw-bold small mb-1 bg-primary-subtle px-2 py-1 rounded d-inline-block" style="width: fit-content;">ID1-F00123202225</span>
                                <h5 class="fw-bold text-dark mb-1 sku-title">Apex Emulsion White 2.5Ltr</h5>
                                <div class="text-muted small mb-3"><i class="bi bi-clock-history me-1"></i>Batch: BCH-2607-042</div>
                                
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-warning text-dark border border-warning fs-6 px-3 py-2 shadow-sm rounded-pill"><i class="bi bi-box-seam me-1"></i>AMBIL: 100 TIN</span>
                                    <span class="badge bg-light text-dark border fs-6 px-3 py-2 rounded-pill"><i class="bi bi-upc-scan me-1"></i>PLT-015</span>
                                </div>
                            </div>
                            
                            <!-- Action Area -->
                            <div class="col-3 col-md-2 bg-white rounded-end-4 d-flex align-items-center justify-content-center border-start border-light pe-2 pe-md-0">
                                <button class="btn btn-outline-success rounded-circle btn-check-item d-flex align-items-center justify-content-center shadow-sm" style="width: 60px; height: 60px;" onclick="checkItem('item1', this)">
                                    <i class="bi bi-check-lg fs-2"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Item 2 -->
                <div class="card border-0 shadow-sm rounded-4 mb-4 picking-item" id="item2">
                    <div class="card-body p-0">
                        <div class="row g-0 align-items-stretch">
                            <!-- Rack Area -->
                            <div class="col-3 col-md-2 text-white text-center p-3 rounded-start-4 d-flex flex-column justify-content-center" style="background: linear-gradient(135deg, #fd7e14, #e85e00);">
                                <small class="text-white-50 fw-semibold text-uppercase letter-spacing-1 mb-1">LOKASI</small>
                                <span class="rack-badge mb-0">C-04</span>
                            </div>
                            
                            <!-- Detail Area -->
                            <div class="col-6 col-md-8 p-3 p-md-4 d-flex flex-column justify-content-center">
                                <span class="font-monospace text-primary fw-bold small mb-1 bg-primary-subtle px-2 py-1 rounded d-inline-block" style="width: fit-content;">ID1-F00123708320</span>
                                <h5 class="fw-bold text-dark mb-1 sku-title">Apex Emulsion Harvest Cream 20Ltr</h5>
                                <div class="text-muted small mb-3"><i class="bi bi-clock-history me-1"></i>Batch: BCH-2608-010</div>
                                
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-warning text-dark border border-warning fs-6 px-3 py-2 shadow-sm rounded-pill"><i class="bi bi-box-seam me-1"></i>AMBIL: 20 PAIL</span>
                                    <span class="badge bg-light text-dark border fs-6 px-3 py-2 rounded-pill"><i class="bi bi-upc-scan me-1"></i>PLT-102</span>
                                </div>
                            </div>
                            
                            <!-- Action Area -->
                            <div class="col-3 col-md-2 bg-white rounded-end-4 d-flex align-items-center justify-content-center border-start border-light pe-2 pe-md-0">
                                <button class="btn btn-outline-success rounded-circle btn-check-item d-flex align-items-center justify-content-center shadow-sm" style="width: 60px; height: 60px;" onclick="checkItem('item2', this)">
                                    <i class="bi bi-check-lg fs-2"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end mt-5 pt-3 border-top">
                    <form action="/wms/outbound/complete-picking/BATCH-001" method="POST" id="formFinishPicking">
                        @csrf
                        <button type="button" class="btn btn-primary btn-lg px-5 rounded-pill fw-bold shadow-sm" id="btnFinishPicking" disabled onclick="finishPicking()">
                            Selesaikan & Serahkan ke Packing <i class="bi bi-arrow-right-circle ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    let pickedCount = 0;
    const totalItems = 2;

    function printPickingList() {
        Swal.fire({
            title: 'Mempersiapkan Dokumen',
            text: 'Sedang men-generate PDF Daftar Picking...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        setTimeout(() => {
            Swal.fire('Berhasil', 'Dokumen Daftar Picking berhasil dicetak/diunduh.', 'success');
        }, 1500);
    }

    function selectTask(po) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: 'Memuat tugas ' + po + '...',
            showConfirmButton: false,
            timer: 1000
        });
    }

    function checkItem(itemId, btn) {
        const card = document.getElementById(itemId);
        
        // Toggle states
        if(card.classList.contains('done')) {
            // Uncheck
            card.classList.remove('done');
            
            btn.classList.remove('btn-success', 'text-white');
            btn.classList.add('btn-outline-success');
            
            pickedCount--;
        } else {
            // Check
            card.classList.add('done');
            
            btn.classList.remove('btn-outline-success');
            btn.classList.add('btn-success', 'text-white');
            
            pickedCount++;
            
            // Suara scan / alert ringan (mock)
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1000,
                timerProgressBar: true
            });
            Toast.fire({
                icon: 'success',
                title: 'Item Dipicking'
            });
        }

        // Update progress bar
        const progress = Math.round((pickedCount / totalItems) * 100);
        document.getElementById('pickingProgress').style.width = progress + '%';
        document.getElementById('progressText').innerText = progress + '%';

        // Enable/Disable Finish Button
        const btnFinish = document.getElementById('btnFinishPicking');
        if(pickedCount === totalItems) {
            btnFinish.disabled = false;
            btnFinish.classList.add('animate__animated', 'animate__pulse', 'animate__infinite');
        } else {
            btnFinish.disabled = true;
            btnFinish.classList.remove('animate__animated', 'animate__pulse', 'animate__infinite');
        }
    }

    function finishPicking() {
        Swal.fire({
            title: 'Selesaikan Tugas?',
            text: "Semua barang (120 Qty) telah berhasil dikumpulkan.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="bi bi-check-circle me-1"></i> Ya, Selesaikan'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('formFinishPicking').submit();
            }
        });
    }
</script>
@endpush