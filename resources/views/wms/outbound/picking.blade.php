@extends('layouts.wms')
@section('title', 'Proses Picking')
@section('page_title', 'Tugas Pengambilan Barang (Picking)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Aplikasi panduan bagi operator gudang/forklift untuk mengambil barang sesuai alokasi Batch dan Rak secara efisien.</p>
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
                    <!-- PO-2608-001 -->
                    <button type="button" class="list-group-item list-group-item-action p-4 border-bottom bg-primary-subtle" onclick="selectTask('PO-2608-001')">
                        <div class="d-flex w-100 justify-content-between mb-2">
                            <h6 class="mb-0 fw-bold text-primary">PO-2608-001</h6>
                            <span class="badge bg-warning text-dark rounded-pill">0 / 2 Selesai</span>
                        </div>
                        <p class="mb-1 text-dark fw-semibold small">CV Bangun Jaya</p>
                        <small class="text-muted"><i class="bi bi-box-seam me-1"></i> Total: 120 Qty (2 SKU)</small>
                    </button>
                    <!-- PO-Lainnya -->
                    <button type="button" class="list-group-item list-group-item-action p-4 border-bottom" onclick="selectTask('PO-2608-005')">
                        <div class="d-flex w-100 justify-content-between mb-2">
                            <h6 class="mb-0 fw-bold text-dark">PO-2608-005</h6>
                            <span class="badge bg-secondary text-white rounded-pill">Belum Mulai</span>
                        </div>
                        <p class="mb-1 text-dark fw-semibold small">PT Mitra Bangunan</p>
                        <small class="text-muted"><i class="bi bi-box-seam me-1"></i> Total: 50 Qty (1 SKU)</small>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <!-- Rincian Picking -->
        <div class="card shadow-sm border-0 rounded-4 h-100" id="pickingDetailCard">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt text-primary me-2"></i>Rute Pengambilan: <span class="text-primary">PO-2608-001</span></h6>
                <div class="progress w-25" style="height: 10px;">
                    <div class="progress-bar bg-success" id="pickingProgress" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
            <div class="card-body p-4 bg-light rounded-bottom-4">
                
                <!-- Item 1 -->
                <div class="card border-0 shadow-sm rounded-4 mb-3 picking-item" id="item1">
                    <div class="card-body p-0">
                        <div class="row g-0 align-items-center">
                            <div class="col-3 col-md-2 bg-primary-subtle text-primary text-center p-3 rounded-start-4 d-flex flex-column justify-content-center h-100" style="min-height: 100px;">
                                <h4 class="fw-bold mb-0">Rak G</h4>
                            </div>
                            <div class="col-7 col-md-8 p-3">
                                <span class="font-monospace text-muted small d-block mb-1">ID1-F00123202225 (Batch: BCH-2607-042)</span>
                                <h6 class="fw-bold text-dark mb-2">Apex Emulsion White 2.5Ltr</h6>
                                <div class="d-flex gap-3">
                                    <span class="badge bg-light text-dark border"><i class="bi bi-box me-1"></i>Ambil: 100 TIN</span>
                                    <span class="badge bg-light text-dark border"><i class="bi bi-upc-scan me-1"></i>PLT-015</span>
                                </div>
                            </div>
                            <div class="col-2 col-md-2 text-center pe-3">
                                <button class="btn btn-outline-success rounded-circle btn-lg p-3 btn-check-item" onclick="checkItem('item1', this)"><i class="bi bi-check-lg"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Item 2 -->
                <div class="card border-0 shadow-sm rounded-4 mb-4 picking-item" id="item2">
                    <div class="card-body p-0">
                        <div class="row g-0 align-items-center">
                            <div class="col-3 col-md-2 bg-primary-subtle text-primary text-center p-3 rounded-start-4 d-flex flex-column justify-content-center h-100" style="min-height: 100px;">
                                <h4 class="fw-bold mb-0">Rak C</h4>
                            </div>
                            <div class="col-7 col-md-8 p-3">
                                <span class="font-monospace text-muted small d-block mb-1">ID1-F00123708320 (Batch: BCH-2608-010)</span>
                                <h6 class="fw-bold text-dark mb-2">Apex Emulsion Harvest Cream 20Ltr</h6>
                                <div class="d-flex gap-3">
                                    <span class="badge bg-light text-dark border"><i class="bi bi-box me-1"></i>Ambil: 20 PAIL</span>
                                    <span class="badge bg-light text-dark border"><i class="bi bi-upc-scan me-1"></i>PLT-102</span>
                                </div>
                            </div>
                            <div class="col-2 col-md-2 text-center pe-3">
                                <button class="btn btn-outline-success rounded-circle btn-lg p-3 btn-check-item" onclick="checkItem('item2', this)"><i class="bi bi-check-lg"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <form action="/wms/outbound/complete-picking/PO-2608-001" method="POST" id="formFinishPicking">
                        @csrf
                        <button type="button" class="btn btn-primary px-5 rounded-pill fw-bold shadow-sm" id="btnFinishPicking" disabled onclick="finishPicking()">Selesaikan & Serahkan ke Packing <i class="bi bi-arrow-right ms-2"></i></button>
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

    function selectTask(po) {
        alert('Simulasi: Memuat tugas ' + po);
    }

    function checkItem(itemId, btn) {
        const card = document.getElementById(itemId);
        
        // Toggle states
        if(card.classList.contains('border-success')) {
            // Uncheck
            card.classList.remove('border-success');
            card.classList.add('border-0');
            card.style.opacity = '1';
            
            btn.classList.remove('btn-success', 'text-white');
            btn.classList.add('btn-outline-success');
            
            pickedCount--;
        } else {
            // Check
            card.classList.remove('border-0');
            card.classList.add('border', 'border-2', 'border-success');
            card.style.opacity = '0.7';
            
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
        const progress = (pickedCount / totalItems) * 100;
        document.getElementById('pickingProgress').style.width = progress + '%';

        // Enable/Disable Finish Button
        const btnFinish = document.getElementById('btnFinishPicking');
        if(pickedCount === totalItems) {
            btnFinish.disabled = false;
        } else {
            btnFinish.disabled = true;
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
            confirmButtonText: 'Ya, Selesaikan'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('formFinishPicking').submit();
            }
        });
    }
</script>
@endpush