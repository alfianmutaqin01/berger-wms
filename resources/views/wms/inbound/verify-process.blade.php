@extends('layouts.wms')
@section('page_title', 'Proses Verifikasi')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <a href="/wms/inbound/verify" class="text-muted text-decoration-none me-2"><i class="bi bi-arrow-left"></i></a>
                        Verifikasi Produksi: <span class="text-primary font-monospace">{{ $inbound['doc_no'] }}</span>
                    </h5>
                    <p class="text-muted small mt-1 ms-4 mb-0">Lakukan pengecekan fisik. Edit Qty atau Lokasi jika terdapat ketidaksesuaian.</p>
                </div>
                <div class="text-end">
                    <span class="d-block text-muted small">Batch No: <strong class="text-dark font-monospace">{{ $inbound['batch_no'] }}</strong></span>
                    <span class="d-block text-muted small">Tanggal: <strong class="text-dark">{{ $inbound['date'] }}</strong></span>
                </div>
            </div>
            <div class="card-body p-4">
                
                <div class="alert alert-warning border-0 bg-warning-subtle text-warning-emphasis d-flex align-items-center rounded-3 p-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                    <div>
                        <small><strong>Perhatian:</strong> SKU dan Batch bersifat paten dari Tim Produksi dan tidak dapat diubah di sini. Anda hanya diizinkan untuk mengoreksi nilai <strong>Qty</strong> dan <strong>Lokasi Rak</strong> jika fisik gudang berbeda dengan data sistem.</small>
                    </div>
                </div>

                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-3" id="btnCheckAll">
                        <i class="bi bi-check2-square me-1"></i> Verifikasi Semua Baris
                    </button>
                </div>

                <div class="table-responsive" style="overflow: visible;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-center" style="width: 80px;">VERIFIKASI</th>
                                <th class="text-secondary small fw-semibold">PALET NO</th>
                                <th class="text-secondary small fw-semibold">SKU / DESKRIPSI</th>
                                <th class="text-secondary small fw-semibold">BATCH</th>
                                <th class="text-secondary small fw-semibold text-center" style="width: 120px;">QTY AKTUAL</th>
                                <th class="text-secondary small fw-semibold" style="width: 200px;">LOKASI RAK</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pallets as $idx => $pallet)
                            <tr>
                                <td class="text-center align-middle">
                                    <div class="form-check d-flex justify-content-center mb-0">
                                        <input class="form-check-input verify-checkbox border-secondary" type="checkbox" id="verify{{ $idx }}" style="width: 1.5em; height: 1.5em; cursor: pointer;">
                                    </div>
                                </td>
                                <td class="fw-bold text-dark">{{ $pallet['pallet_no'] }}</td>
                                <td>
                                    <span class="badge bg-light text-dark border mb-1">{{ $pallet['sku'] }}</span><br>
                                    <small class="text-muted">{{ $pallet['description'] }}</small>
                                </td>
                                <td>
                                    <!-- Readonly Batch -->
                                    <div class="px-2 py-1 bg-light border rounded small font-monospace text-muted d-inline-block">
                                        {{ $pallet['batch'] }}
                                    </div>
                                </td>
                                <td class="text-center">
                                    <!-- Editable QTY -->
                                    <input type="number" class="form-control form-control-sm text-center fw-bold text-primary border-primary-subtle bg-primary-subtle" value="{{ $pallet['qty'] }}" min="0">
                                </td>
                                <td>
                                    <!-- Editable Location -->
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-geo-alt text-muted"></i></span>
                                        <input type="text" class="form-control border-start-0 fw-bold" value="{{ $pallet['location'] }}" list="locationList{{ $idx }}">
                                        <datalist id="locationList{{ $idx }}">
                                            @foreach($availableLocations as $loc)
                                            <option value="{{ $loc }}">
                                            @endforeach
                                        </datalist>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 d-flex justify-content-between align-items-center">
                    <a href="/wms/inbound/verify" class="btn btn-outline-secondary px-4">Kembali</a>
                    <button type="button" class="btn btn-success px-5 fw-bold shadow-sm" id="btnSubmitVerify">
                        <i class="bi bi-check-circle-fill me-1"></i> Selesaikan & Aktifkan Stok
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Select All Checkbox logic
        document.getElementById('btnCheckAll').addEventListener('click', function() {
            let checks = document.querySelectorAll('.verify-checkbox');
            let allChecked = Array.from(checks).every(c => c.checked);
            checks.forEach(c => c.checked = !allChecked); // Toggle all
            this.innerHTML = allChecked ? '<i class="bi bi-check2-square me-1"></i> Verifikasi Semua Baris' : '<i class="bi bi-x-square me-1"></i> Batal Verifikasi Semua';
        });

        // Submit logic
        document.getElementById('btnSubmitVerify').addEventListener('click', function() {
            let isVerified = true;
            let checks = document.querySelectorAll('.verify-checkbox');
            checks.forEach(function(check) {
                if(!check.checked) {
                    isVerified = false;
                    check.closest('tr').classList.add('table-danger');
                } else {
                    check.closest('tr').classList.remove('table-danger');
                }
            });

            if(!isVerified) {
                alert('Peringatan: Ada baris palet yang belum diverifikasi. Silakan centang semua baris jika fisik sudah sesuai.');
                return;
            }

            alert('Verifikasi Final berhasil! Stok sekarang telah berstatus AKTIF di sistem.');
            window.location.href = '/wms/inbound/verify';
        });
    });
</script>
@endpush