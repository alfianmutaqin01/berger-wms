@extends('layouts.wms')
@section('page_title', 'Proses Put-away')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <a href="/wms/inbound/putaway" class="text-muted text-decoration-none me-2"><i class="bi bi-arrow-left"></i></a>
                        Proses Put-away Produksi: <span class="text-primary font-monospace">{{ $inbound['doc_no'] }}</span>
                    </h5>
                    <p class="text-muted small mt-1 ms-4 mb-0">Tentukan lokasi rak untuk masing-masing palet di bawah ini.</p>
                </div>
                <div class="text-end">
                    <span class="d-block text-muted small">Batch No: <strong class="text-dark font-monospace">{{ $inbound['batch_no'] }}</strong></span>
                    <span class="d-block text-muted small">Tanggal: <strong class="text-dark">{{ $inbound['date'] }}</strong></span>
                </div>
            </div>
            <div class="card-body p-4">
                
                <div class="alert alert-info border-0 bg-info-subtle text-info-emphasis d-flex align-items-center rounded-3 p-3 mb-4" role="alert">
                    <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                    <div>
                        <small>Anda <strong>tidak dapat</strong> mengubah nilai Qty atau Batch pada tahap ini. Silakan arahkan palet secara fisik ke lokasi rak yang tersedia, nyalakan tuas <strong>Verifikasi Fisik</strong> jika fisik palet sesuai dengan data, lalu input kode lokasinya (contoh: G-03-04).</small>
                    </div>
                </div>

                <div class="table-responsive" style="overflow: visible;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-center">VERIFIKASI FISIK</th>
                                <th class="text-secondary small fw-semibold">SKU / DESKRIPSI</th>
                                <th class="text-secondary small fw-semibold">BATCH</th>
                                <th class="text-secondary small fw-semibold text-center">QTY</th>
                                <th class="text-secondary small fw-semibold" style="width: 250px;">LOKASI RAK</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pallets as $idx => $pallet)
                            <tr>
                                <td class="text-center align-middle">
                                    <div class="form-check form-switch d-flex justify-content-center mb-1">
                                        <input class="form-check-input verify-checkbox border-secondary" type="checkbox" role="switch" id="verify{{ $idx }}" style="width: 2.5em; height: 1.25em; cursor: pointer;">
                                    </div>
                                    <label class="form-check-label small text-muted fw-bold" for="verify{{ $idx }}" style="cursor: pointer;">{{ $pallet['pallet_no'] }}</label>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border mb-1">{{ $pallet['sku'] }}</span><br>
                                    <small class="text-muted">{{ $pallet['description'] }}</small>
                                </td>
                                <td><small class="font-monospace text-muted">{{ $pallet['batch'] }}</small></td>
                                <td class="text-center">
                                    <span class="badge bg-primary-subtle text-primary border border-primary px-3 py-2">{{ $pallet['qty'] }}</span>
                                </td>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-upc-scan text-muted"></i></span>
                                        <input type="text" class="form-control location-input" placeholder="Scan/Input Lokasi" list="locationList{{ $idx }}" required>
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
                    <a href="/wms/inbound/putaway" class="btn btn-outline-secondary px-4">Batal</a>
                    <button type="button" class="btn btn-success px-5 fw-bold shadow-sm" id="btnSubmitPutaway">
                        <i class="bi bi-check-circle me-1"></i> Selesaikan Put-away
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
        document.getElementById('btnSubmitPutaway').addEventListener('click', function() {
            let isValid = true;
            let inputs = document.querySelectorAll('.location-input');
            inputs.forEach(function(input) {
                if(!input.value) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            let isVerified = true;
            let checks = document.querySelectorAll('.verify-checkbox');
            checks.forEach(function(check) {
                if(!check.checked) {
                    isVerified = false;
                    check.classList.add('is-invalid');
                } else {
                    check.classList.remove('is-invalid');
                }
            });

            if(!isValid || !isVerified) {
                alert('Peringatan: Anda belum mengisi lokasi rak atau memastikan seluruh tuas Verifikasi Fisik telah menyala (dicentang).');
                return;
            }

            alert('Proses Put-away dan Verifikasi Fisik berhasil! Status Inbound kini menjadi: Menunggu Verifikasi Final Logistik.');
            window.location.href = '/wms/inbound/putaway';
        });
    });
</script>
@endpush