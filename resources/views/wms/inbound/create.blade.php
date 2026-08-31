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
    <div class="col-12 col-xl-9">
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

                    {{-- Nomor dokumen & tanggal dibangkitkan sistem, bukan diketik
                         Tim Produksi. Ditampilkan read-only agar terlihat jelas
                         nomor apa yang akan dipakai. --}}
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-secondary small">No. Dokumen Produksi</label>
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
                    <div class="form-text mb-3">Ukuran maksimal 10 MB, hingga 5.000 baris.</div>

                    <div class="alert alert-light border small mb-0">
                        <strong><i class="bi bi-table me-1"></i>Kolom yang dibaca sistem</strong>
                        — hanya kolom A sampai E; kolom setelahnya diabaikan.
                        <div class="table-responsive mt-2">
                            <table class="table table-sm table-bordered mb-0" style="font-size: 0.78rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px;">Kol</th>
                                        <th>Judul Kolom</th>
                                        <th>Dipakai sebagai</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td class="text-center fw-bold">A</td><td>No.</td><td>Nomor order produksi</td></tr>
                                    <tr><td class="text-center fw-bold">B</td><td>Source No.</td><td>SKU produk — harus sudah ada di Master Produk</td></tr>
                                    <tr><td class="text-center fw-bold">C</td><td>Description</td><td>Nama produk (hanya untuk tampilan)</td></tr>
                                    <tr><td class="text-center fw-bold">D</td><td>Quantity</td><td>Total qty, dipecah otomatis jadi palet</td></tr>
                                    <tr><td class="text-center fw-bold">E</td><td>QC Number</td><td>Nomor batch</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <hr class="my-2">
                        Berkas <strong>tidak disimpan</strong> di sistem — hanya hasil pembacaannya.
                    </div>
                </div>

                <div class="card-footer bg-light border-top-0 rounded-bottom-4 text-end py-3 px-4">
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <i class="bi bi-search me-1"></i> Baca &amp; Pratinjau
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-12 col-xl-3 mt-4 mt-xl-0">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4">
                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Cara Kerja</h6>
                <ol class="small text-muted ps-3 mb-0">
                    <li class="mb-2">Unggah berkas produksi.</li>
                    <li class="mb-2">Sistem membaca SKU, jumlah, dan batch, lalu <strong>memecahnya menjadi palet</strong> sesuai kapasitas kemasan.</li>
                    <li class="mb-2">Periksa hasilnya di layar pratinjau — <strong>belum ada yang tersimpan</strong>.</li>
                    <li class="mb-2">Tekan Simpan. Dokumen masuk antrean <strong>Menunggu Put-away</strong>.</li>
                    <li>Berkas Excel dibuang setelah tersimpan.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
@endsection
