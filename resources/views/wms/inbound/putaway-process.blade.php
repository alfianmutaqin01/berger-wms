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
                        <small>Jika rak yang Anda pilih tidak memiliki kapasitas yang cukup untuk 1 palet (maks 180 Pail), sistem akan <strong>otomatis memecah</strong> sisanya ke baris baru agar Anda dapat menempatkannya di rak lain.</small>
                    </div>
                </div>

                <div class="table-responsive" style="overflow: visible;">
                    <table class="table table-hover align-middle mb-0" id="putawayTable">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-center">VERIFIKASI FISIK</th>
                                <th class="text-secondary small fw-semibold">SKU / DESKRIPSI</th>
                                <th class="text-secondary small fw-semibold">BATCH</th>
                                <th class="text-secondary small fw-semibold text-center">QTY SISTEM</th>
                                  <th class="text-secondary small fw-semibold text-center" style="width: 120px;">QTY AKTUAL</th>
                                <th class="text-secondary small fw-semibold" style="width: 300px;">LOKASI RAK</th>
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
                                <td class="text-center qty-cell">
                                      <span class="badge bg-light text-dark border px-2 py-1 original-qty" title="Dari Produksi">{{ $pallet['qty'] }}</span>
                                  </td>
                                  <td class="text-center actual-qty-cell" style="width: 120px;">
                                      <input type="number" class="form-control form-control-sm text-center actual-qty-input fw-bold text-primary border-primary-subtle bg-primary-subtle" value="{{ $pallet['qty'] }}" min="0" required>
                                  </td>
                                <td>
                                    <div class="input-group">
                                        <button type="button" class="btn btn-outline-secondary" title="Scan QR Code" onclick="openQRScanner(this, '{{ $idx }}')"><i class="bi bi-qr-code-scan"></i></button>
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
    const RACK_MAX_CAPACITY = 180;

    window.handleLocationSelection = function(inputElem) {
        let tr = inputElem.closest('tr');
        let locationString = inputElem.value;
        if (!locationString) return;
        
        // Parse how much is currently in the rack
        let currentInRack = 0;
        let match = locationString.match(/Terisi (\d+) Pail/i);
        if (match) {
            currentInRack = parseInt(match[1]);
        } else if (locationString.toLowerCase().includes('kosong')) {
            currentInRack = 0;
        } else {
            currentInRack = 0;
        }
        
        let availableSpace = RACK_MAX_CAPACITY - currentInRack;
        
        let qtyCell = tr.querySelector('.qty-cell');
        let qtyBadge = tr.querySelector('.original-qty');
        
        let currentQty = parseInt(qtyBadge.innerText) || 0;
        
        if (currentQty > availableSpace && availableSpace > 0) {
            // Split needed!
            // 1. Change this row to hold only availableSpace
            qtyCell.innerHTML = '<span class="badge bg-light text-dark border px-2 py-1 original-qty">' + availableSpace + '</span>';
            tr.querySelector('.actual-qty-input').value = availableSpace;
            
            let remainingQty = currentQty - availableSpace;
            
            // 2. Clone row for remainder
            let newTr = tr.cloneNode(true);
            let timestamp = new Date().getTime();
            
            // Setup new row labels & IDs
            let verifyLabel = newTr.querySelector('.form-check-label');
            if (!verifyLabel.innerText.includes('(Sisa)')) {
                verifyLabel.innerText = verifyLabel.innerText + ' (Sisa)';
            }
            
            let switchInput = newTr.querySelector('.verify-checkbox');
            switchInput.id = 'verify_split_' + timestamp;
            switchInput.checked = false;
            verifyLabel.setAttribute('for', 'verify_split_' + timestamp);
            
            let newLocationInput = newTr.querySelector('.location-input');
            newLocationInput.value = '';
            newLocationInput.classList.remove('is-invalid');
            
            let scanBtn = newTr.querySelector('button[title="Scan QR Code"]');
            scanBtn.setAttribute('onclick', 'openQRScanner(this, "split_' + timestamp + '")');
            
            let datalist = newTr.querySelector('datalist');
            datalist.id = 'locationList_split_' + timestamp;
            newLocationInput.setAttribute('list', 'locationList_split_' + timestamp);
            
            let newQtyCell = newTr.querySelector('.qty-cell');
            newQtyCell.innerHTML = '<span class="badge bg-light text-dark border px-2 py-1 original-qty">' + remainingQty + '</span>';
            newTr.querySelector('.actual-qty-input').value = remainingQty;
            
            newLocationInput.addEventListener('change', function() {
                handleLocationSelection(this);
            });
            
            tr.parentNode.insertBefore(newTr, tr.nextSibling);
            
            Swal.fire({
                icon: 'warning',
                title: 'Kapasitas Rak Terbatas',
                text: 'Rak ' + locationString + ' hanya muat ' + availableSpace + ' pail lagi. Sisa ' + remainingQty + ' pail otomatis dipisahkan ke baris baru.',
                position: 'center',
                showConfirmButton: true,
                confirmButtonText: 'Mengerti'
            });
            
            // Auto focus the new location input
            setTimeout(() => newLocationInput.focus(), 1000);
        }
    };

    window.openQRScanner = function(btnElement, idx) {
        let inputField = btnElement.closest('.input-group').querySelector('.location-input');
        
        Swal.fire({
            title: 'Scan QR Code Rak',
            html: '<div class="d-flex flex-column align-items-center justify-content-center bg-dark rounded-3" style="height: 250px; position: relative; overflow: hidden;">' +
                  '  <div class="border border-success border-2 rounded-3" style="width: 150px; height: 150px; position: absolute; z-index: 2;"></div>' +
                  '  <div class="bg-success opacity-50" style="width: 150px; height: 2px; position: absolute; top: 50%; z-index: 3; animation: scan 2s linear infinite;"></div>' +
                  '  <i class="bi bi-camera-video text-secondary opacity-50" style="font-size: 5rem; z-index: 1;"></i>' +
                  '  <style>@keyframes scan { 0% { transform: translateY(-75px); } 50% { transform: translateY(75px); } 100% { transform: translateY(-75px); } }</style>' +
                  '</div><p class="mt-3 text-muted small">Membuka kamera... Arahkan ke QR Code di rak.</p>',
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Batal',
            allowOutsideClick: false,
            didOpen: () => {
                // Simulate scanning process
                setTimeout(() => {
                    Swal.close();
                    
                    const racks = [
                        'G-03-01 (Kosong)', 
                        'G-03-02 (Terisi 90 Pail)', 
                        'G-03-03 (Terisi 120 Pail)', 
                        'G-03-04 (Terisi 150 Pail)', 
                        'G-03-05 (Kosong)'
                    ];
                    let randomRack = racks[Math.floor(Math.random() * racks.length)];
                    inputField.value = randomRack;
                    
                    // Trigger the change event manually to run auto-split logic
                    inputField.dispatchEvent(new Event('change'));
                    
                    // Only show success if it didn't trigger a split alert
                    let currentInRack = 0;
                    let match = randomRack.match(/Terisi (\d+) Pail/i);
                    if (match) currentInRack = parseInt(match[1]);
                    let availableSpace = RACK_MAX_CAPACITY - currentInRack;
                    
                    let qtyBadge = btnElement.closest('tr').querySelector('.original-qty');
                    let currentQty = qtyBadge ? parseInt(qtyBadge.innerText) : 0;
                    
                    if (!(currentQty > availableSpace && availableSpace > 0)) {
                        Swal.fire({
                            icon: 'success',
                            title: 'QR Code Terbaca',
                            text: 'Lokasi Rak: ' + randomRack,
                            position: 'center',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    }
                }, 2000);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        // Bind change event to existing inputs
        document.querySelectorAll('.location-input').forEach(input => {
            input.addEventListener('change', function() {
                handleLocationSelection(this);
            });
        });

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
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan',
                    text: 'Anda belum mengisi lokasi rak atau memastikan seluruh tuas Verifikasi Fisik telah menyala (dicentang).',
                    position: 'center',
                    confirmButtonText: 'Periksa Kembali'
                });
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Proses Put-away dan Verifikasi Fisik berhasil! Status Inbound kini menjadi: Menunggu Verifikasi Final Logistik.',
                position: 'center',
                showConfirmButton: true,
                confirmButtonText: 'Kembali ke Daftar',
                confirmButtonColor: '#198754'
            }).then(() => {
                window.location.href = '/wms/inbound/putaway';
            });
        });
    });
</script>
@endpush