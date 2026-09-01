@extends('layouts.wms')

@section('title', 'Input Produksi')
@section('page_title', 'Input Produksi (Inbound)')

@section('content')
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-arrow-in-right text-primary me-2"></i> Input Produksi</h5>
                <p class="text-muted small mt-1 mb-0">
                    Unggah berkas hasil produksi. Sistem membaca isinya lalu memecah jumlahnya
                    menjadi palet sesuai kemasan tiap produk.
                </p>
            </div>

            <form action="{{ route('wms.inbound.preview') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="card-body p-4">

                    {{-- Nomor dokumen Input WMS & tanggal dibangkitkan sistem --}}
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-secondary small">No. Dokumen Input WMS</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-magic text-primary"></i></span>
                                <input type="text" class="form-control bg-light font-monospace fw-bold" value="{{ $documentNumber }}" readonly>
                            </div>
                            <div class="form-text">Dibuat otomatis oleh sistem.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-secondary small">Tanggal Produksi</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-calendar-check text-primary"></i></span>
                                <input type="text" class="form-control bg-light fw-semibold" value="{{ $productionDate->translatedFormat('d F Y') }}" readonly>
                            </div>
                            <div class="form-text">Mengikuti tanggal pengisian.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-secondary small">Gudang Tujuan <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" required>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->display_label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <hr class="my-4">

                    <label class="form-label fw-semibold text-secondary small">Berkas Produksi (.xlsx / .xls) <span class="text-danger">*</span></label>
                    <input type="file" name="file" class="form-control form-control-lg" accept=".xlsx,.xls" required>
                    <div class="form-text">Ukuran maksimal 10 MB, hingga 5.000 baris.</div>
                </div>

                <div class="card-footer bg-light border-top-0 rounded-bottom-4 text-end py-3 px-4">
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <i class="bi bi-search me-1"></i> Baca &amp; Pratinjau
                    </button>
                </div>
            </form>
        </div>
    </div>

        <div class="col-12 mt-3">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4">
                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Cara Kerja</h6>
                <div class="row">
                    <div class="col-md-6 col-lg-3 mb-2 mb-lg-0">
                        <div class="d-flex align-items-start">
                            <span class="badge bg-primary rounded-circle me-2 mt-1 flex-shrink-0 d-flex align-items-center justify-content-center" style="width:24px;height:24px;">1</span>
                            <span class="small text-muted">Unggah berkas produksi.</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3 mb-2 mb-lg-0">
                        <div class="d-flex align-items-start">
                            <span class="badge bg-primary rounded-circle me-2 mt-1 flex-shrink-0 d-flex align-items-center justify-content-center" style="width:24px;height:24px;">2</span>
                            <span class="small text-muted">Sistem membaca SKU, jumlah, dan batch, lalu <strong>memecahnya menjadi palet</strong> sesuai kapasitas kemasan.</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3 mb-2 mb-lg-0">
                        <div class="d-flex align-items-start">
                            <span class="badge bg-primary rounded-circle me-2 mt-1 flex-shrink-0 d-flex align-items-center justify-content-center" style="width:24px;height:24px;">3</span>
                            <span class="small text-muted">Periksa hasilnya di layar pratinjau &mdash; <strong>belum ada yang tersimpan</strong>.</span>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="d-flex align-items-start">
                            <span class="badge bg-primary rounded-circle me-2 mt-1 flex-shrink-0 d-flex align-items-center justify-content-center" style="width:24px;height:24px;">4</span>
                            <span class="small text-muted">Tekan Simpan. Dokumen masuk antrean <strong>Menunggu Put-away</strong>.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
