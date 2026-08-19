@extends('layouts.soms')
@section('page_title', 'Riwayat Pesanan (My Orders)')

@section('content')
<div class="row">
    <div class="col-12">

        <!-- Mobile Filter (Shown on mobile only) -->
        <div class="d-lg-none mb-3 d-flex gap-2 overflow-x-auto pb-2" style="white-space: nowrap; -webkit-overflow-scrolling: touch;">
            <button class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">Semua</button>
            <button class="btn btn-sm btn-outline-secondary bg-white rounded-pill px-3 shadow-sm">Draft</button>
            <button class="btn btn-sm btn-outline-secondary bg-white rounded-pill px-3 shadow-sm">Menunggu Approval</button>
        </div>
        
        <!-- Desktop Filters -->
        <div class="d-none d-lg-flex justify-content-between align-items-center mb-4">
            <div>
                <button class="btn btn-primary rounded-pill px-4 shadow-sm me-2">Semua</button>
                <button class="btn btn-outline-secondary bg-white rounded-pill px-4 shadow-sm me-2">Draft</button>
                <button class="btn btn-outline-secondary bg-white rounded-pill px-4 shadow-sm">Menunggu Approval</button>
            </div>
            <div class="input-group w-auto">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control" placeholder="Cari No. PO atau Customer...">
            </div>
        </div>

        @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        @endif

        <!-- Orders List / Grid -->
        <div class="row g-3">
            
            <!-- Order Card 1: Waiting Approval -->
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100 position-relative">
                    <div class="position-absolute top-0 end-0 mt-3 me-3">
                        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split me-1"></i> Menunggu Approval</span>
                    </div>
                    <div class="card-body p-4">
                        <small class="text-muted d-block mb-1">PO-2608-001</small>
                        <h5 class="fw-bold text-dark mb-3">CV Bangun Jaya</h5>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted"><i class="bi bi-box me-1"></i> Item:</small>
                            <span class="fw-semibold">120 Qty (2 SKU)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted"><i class="bi bi-credit-card me-1"></i> Payment:</small>
                            <span class="fw-semibold">Tempo 30 Hari</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <small class="text-muted"><i class="bi bi-calendar3 me-1"></i> Tgl Order:</small>
                            <span class="fw-semibold">18 Ags 2026</span>
                        </div>

                        <div class="border-top pt-3 d-flex gap-2">
                            <button class="btn btn-light text-primary w-100 rounded-pill"><i class="bi bi-eye"></i> Detail</button>
                            <!-- No Edit button because it's already submitted -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Card 2: Draft -->
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100 position-relative">
                    <div class="position-absolute top-0 end-0 mt-3 me-3">
                        <span class="badge bg-secondary px-3 py-2 rounded-pill"><i class="bi bi-pencil-square me-1"></i> Draft</span>
                    </div>
                    <div class="card-body p-4">
                        <small class="text-muted d-block mb-1">DRAFT-992</small>
                        <h5 class="fw-bold text-dark mb-3">Kusumana Food</h5>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted"><i class="bi bi-box me-1"></i> Item:</small>
                            <span class="fw-semibold">50 Qty (1 SKU)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted"><i class="bi bi-credit-card me-1"></i> Payment:</small>
                            <span class="fw-semibold">-</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <small class="text-muted"><i class="bi bi-calendar3 me-1"></i> Tgl Order:</small>
                            <span class="fw-semibold">18 Ags 2026</span>
                        </div>

                        <div class="border-top pt-3 d-flex gap-2">
                            <button class="btn btn-outline-primary w-50 rounded-pill"><i class="bi bi-pencil"></i> Lanjutkan</button>
                            <button class="btn btn-outline-danger w-50 rounded-pill"><i class="bi bi-trash"></i> Hapus</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- New Order Placed by Simulation -->
            @if(session('success'))
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card border border-primary shadow-sm rounded-4 h-100 position-relative">
                    <div class="position-absolute top-0 end-0 mt-3 me-3">
                        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="bi bi-hourglass-split me-1"></i> Menunggu Approval</span>
                    </div>
                    <div class="card-body p-4">
                        <small class="text-primary fw-bold d-block mb-1">PO-NEW (Baru Saja)</small>
                        <h5 class="fw-bold text-dark mb-3">Pemesanan Baru</h5>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted"><i class="bi bi-box me-1"></i> Item:</small>
                            <span class="fw-semibold">Berdasarkan Input</span>
                        </div>
                        <div class="border-top pt-3 mt-4">
                            <button class="btn btn-primary w-100 rounded-pill"><i class="bi bi-eye"></i> Detail</button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

        </div>

    </div>
</div>
@endsection
