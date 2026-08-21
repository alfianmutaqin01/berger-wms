@extends('layouts.soms')
@section('page_title', 'Riwayat Pesanan (My Orders)')

@section('content')
<div class="row">
    <div class="col-12">
        <form id="hiddenReturnForm" method="POST" action="/sales/report-return" class="d-none">
            @csrf
            <input type="hidden" name="po_number" id="hr_po">
            <input type="hidden" name="customer" id="hr_customer">
            <input type="hidden" name="sku" id="hr_sku">
            <input type="hidden" name="qty" id="hr_qty">
            <input type="hidden" name="reason" id="hr_reason">
        </form>

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
                            <button class="btn btn-light text-primary w-100 rounded-pill" onclick="showOrderDetail('PO-2608-001')"><i class="bi bi-eye"></i> Detail</button>
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
                            <button class="btn btn-outline-primary w-50 rounded-pill" onclick="continueDraft('DRAFT-992')"><i class="bi bi-pencil"></i> Lanjutkan</button>
                            <button class="btn btn-outline-danger w-50 rounded-pill" onclick="deleteDraft(this, 'DRAFT-992')"><i class="bi bi-trash"></i> Hapus</button>
                        </div>
                    </div>
                </div>
            </div>

                        <!-- Terkirim / Upload Surat Jalan Simulation -->
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card border border-success shadow-sm rounded-4 h-100 position-relative" style="background-color: #f8fff9;">
                    <div class="position-absolute top-0 end-0 mt-3 me-3">
                        <span class="badge bg-success text-white px-3 py-2 rounded-pill"><i class="bi bi-check-circle me-1"></i> Terkirim</span>
                    </div>
                    <div class="card-body p-4">
                        <small class="text-success fw-bold d-block mb-1">PO-1508-011</small>
                        <h5 class="fw-bold text-dark mb-3">PT Sentosa Abadi</h5>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted"><i class="bi bi-box me-1"></i> Item:</small>
                            <span class="fw-semibold">150 Qty (3 SKU)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <small class="text-muted"><i class="bi bi-calendar3 me-1"></i> Tgl Order:</small>
                            <span class="fw-semibold">15 Ags 2026</span>
                        </div>

                        <div class="border-top pt-3 mt-4">
                            <button class="btn btn-success w-100 rounded-pill mb-2 fw-semibold shadow-sm" onclick="confirmDelivery('PO-1508-011', 'PT Sentosa Abadi', this)"><i class="bi bi-file-earmark-check me-2"></i>Selesaikan SJ</button>
                            <button class="btn btn-outline-danger w-100 rounded-pill mb-2 fw-semibold" onclick="reportReturn('PO-1508-011', 'PT Sentosa Abadi', this)"><i class="bi bi-exclamation-triangle me-2"></i>Lapor Kendala / Retur</button>
                            <button class="btn btn-light text-primary w-100 rounded-pill" onclick="showOrderDetail('PO-1508-011', true)"><i class="bi bi-eye"></i> Detail Lengkap</button>
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
                            <button class="btn btn-primary w-100 rounded-pill" onclick="showOrderDetail('PO-NEW')"><i class="bi bi-eye"></i> Detail</button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
    function showOrderDetail(poNumber, isFinished = false) {
                let custName = (poNumber === 'PO-1508-011') ? 'PT Sentosa Abadi' : 'CV Bangun Jaya';
        let statusBadge = (poNumber === 'PO-1508-011') ? '<span class="fw-bold text-success">Selesai / Terkirim</span>' : '';

        let timelineHtml = `
            <div class="position-relative mb-4 mt-3 px-1">
                <!-- Garis Penghubung Abu-abu -->
                <div class="position-absolute w-100" style="height: 4px; background-color: #e9ecef; top: 16px; left: 0; z-index: 1;"></div>
                <!-- Garis Penghubung Hijau (Progress) -->
                <div class="position-absolute" style="height: 4px; background-color: #198754; top: 16px; left: 0; width: ${isFinished ? '100%' : '25%'}; z-index: 2;"></div>
                
                <div class="d-flex justify-content-between position-relative" style="z-index: 3;">
                    <div class="text-center" style="width: 20%; background: transparent;">
                        <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2 mx-auto shadow-sm" style="width: 36px; height: 36px; border: 4px solid #fff;">
                            <i class="bi bi-check-lg"></i>
                        </div>
                        <div class="small fw-bold text-success mb-1" style="font-size: 0.75rem; line-height: 1.1;">Submit</div>
                        <div class="text-muted" style="font-size: 0.65rem;">18 Ags<br>09:15</div>
                    </div>
                    
                    <div class="text-center" style="width: 20%; background: transparent;">
                        <div class="${isFinished ? 'bg-success text-white' : 'bg-warning text-dark'} rounded-circle d-inline-flex align-items-center justify-content-center mb-2 mx-auto shadow-sm" style="width: 36px; height: 36px; border: 4px solid #fff;">
                            <i class="${isFinished ? 'bi bi-check-lg' : 'bi bi-hourglass-split'}"></i>
                        </div>
                        <div class="small fw-bold ${isFinished ? 'text-success' : 'text-warning'} mb-1" style="font-size: 0.75rem; line-height: 1.1;">Approval</div>
                        <div class="${isFinished ? 'text-muted' : 'text-warning fw-semibold'}" style="font-size: 0.65rem;">${isFinished ? '18 Ags<br>10:00' : 'Menunggu'}</div>
                    </div>

                    <div class="text-center" style="width: 20%; background: transparent;">
                        <div class="${isFinished ? 'bg-success text-white border-0' : 'bg-light text-muted border'} rounded-circle d-inline-flex align-items-center justify-content-center mb-2 mx-auto" style="width: 36px; height: 36px; border: 4px solid #fff !important;">
                            <i class="${isFinished ? 'bi bi-check-lg' : 'bi bi-box-seam'}"></i>
                        </div>
                        <div class="small ${isFinished ? 'text-success fw-bold' : 'text-muted fw-semibold'} mb-1" style="font-size: 0.75rem; line-height: 1.1;">Diproses</div>
                        <div class="text-muted" style="font-size: 0.65rem;">${isFinished ? '19 Ags<br>08:00' : '-'}</div>
                    </div>

                    <div class="text-center" style="width: 20%; background: transparent;">
                        <div class="${isFinished ? 'bg-success text-white border-0' : 'bg-light text-muted border'} rounded-circle d-inline-flex align-items-center justify-content-center mb-2 mx-auto" style="width: 36px; height: 36px; border: 4px solid #fff !important;">
                            <i class="${isFinished ? 'bi bi-check-lg' : 'bi bi-truck'}"></i>
                        </div>
                        <div class="small ${isFinished ? 'text-success fw-bold' : 'text-muted fw-semibold'} mb-1" style="font-size: 0.75rem; line-height: 1.1;">Dikirim</div>
                        <div class="text-muted" style="font-size: 0.65rem;">${isFinished ? '19 Ags<br>14:00' : '-'}</div>
                    </div>

                    <div class="text-center" style="width: 20%; background: transparent;">
                        <div class="${isFinished ? 'bg-success text-white border-0' : 'bg-light text-muted border'} rounded-circle d-inline-flex align-items-center justify-content-center mb-2 mx-auto" style="width: 36px; height: 36px; border: 4px solid #fff !important;">
                            <i class="bi bi-house-check"></i>
                        </div>
                        <div class="small ${isFinished ? 'text-success fw-bold' : 'text-muted fw-semibold'} mb-1" style="font-size: 0.75rem; line-height: 1.1;">Selesai</div>
                        <div class="text-muted" style="font-size: 0.65rem;">${isFinished ? 'Hari Ini<br>09:00' : '-'}</div>
                    </div>
                </div>
            </div>
        `;

        Swal.fire({
            title: 'Detail ' + poNumber,
            html: `
                <div class="text-start mt-3">
                    ${timelineHtml}
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <p class="mb-1"><small class="text-muted">Customer:</small> <strong></strong></p>
                        <p class="mb-1"><small class="text-muted">Tgl Order:</small> <strong>18 Ags 2026</strong></p>
                        <p class="mb-0"><small class="text-muted">Status Tracking:</small> </p>
                    </div>
                    <h6 class="fw-bold mb-2">Item Pesanan:</h6>
                    <ul class="list-group list-group-flush border rounded-3 mb-2">
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                            <div>
                                <span class="fw-bold text-dark d-block">Cat Tembok Putih 5Kg</span>
                                <small class="text-muted">BP-5KG-WHT</small>
                            </div>
                            <span class="badge bg-primary rounded-pill">100 Pail</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                            <div>
                                <span class="fw-bold text-dark d-block">Cat Pelapis Biru 20Kg</span>
                                <small class="text-muted">BP-20KG-BLU</small>
                            </div>
                            <span class="badge bg-primary rounded-pill">20 Pail</span>
                        </li>
                    </ul>
                </div>
            `,
            confirmButtonText: 'Tutup',
            confirmButtonColor: '#6c757d',
            width: '600px'
        });
    }

    function deleteDraft(btnElement, draftId) {
        Swal.fire({
            title: 'Hapus Draft ' + draftId + '?',
            text: "Data yang dihapus tidak dapat dikembalikan!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="bi bi-trash"></i> Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Terhapus!',
                    text: 'Draft pesanan Anda telah dihapus.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
                
                // Animasi hapus (fadeOut) menggunakan vanilla JS
                const card = btnElement.closest('.col-12');
                card.style.transition = 'opacity 0.4s';
                card.style.opacity = '0';
                setTimeout(() => {
                    card.remove();
                }, 400);
            }
        });
    }

    function continueDraft(draftId) {
        Swal.fire({
            title: 'Lanjutkan ' + draftId + '?',
            text: "Anda akan dialihkan ke form pemesanan untuk melengkapi draft ini.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Lanjutkan',
            cancelButtonText: 'Nanti saja'
        }).then((result) => {
            if (result.isConfirmed) {
                // Menampilkan loading sederhana sebelum redirect
                Swal.fire({
                    title: 'Memuat data...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                // Simulasi redirect
                setTimeout(() => {
                    window.location.href = '/sales/new-order?draft=' + draftId;
                }, 800);
            }
        });
    }
    function confirmDelivery(poNumber, customer, btnElement) {
        Swal.fire({
            title: 'Upload Surat Jalan',
            html: `
                <div class="text-start mt-2">
                    <p class="mb-3 text-muted">Barang untuk <strong>${customer}</strong> terpantau sudah sampai. Silakan unggah foto Surat Jalan yang telah ditandatangani toko.</p>
                    <div class="border border-2 border-dashed rounded-3 p-4 text-center bg-light" style="cursor: pointer;" onclick="document.getElementById('fileInputMock2').click()">
                        <i class="bi bi-camera display-6 text-muted mb-2"></i>
                        <p class="mb-0 text-muted fw-semibold">Jepret atau Pilih Foto</p>
                        <small class="text-secondary">Max 5MB</small>
                        <input type="file" id="fileInputMock2" class="d-none" accept="image/png, image/jpeg" onchange="document.getElementById('fileNameDisplay2').textContent = this.files[0]?.name || ''">
                    </div>
                    <p id="fileNameDisplay2" class="text-primary mt-2 text-center fw-semibold small mb-0"></p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-upload"></i> Simpan Selesai',
            cancelButtonText: 'Nanti',
            confirmButtonColor: '#198754',
            preConfirm: () => {
                const file = document.getElementById('fileInputMock2').files[0];
                if (!file) {
                    Swal.showValidationMessage('Anda belum memilih foto surat jalan!');
                    return false;
                }
                return true;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Memproses...',
                    html: 'Mohon tunggu',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Selesai!',
                        text: 'Pesanan ' + poNumber + ' telah resmi ditutup.',
                        confirmButtonColor: '#198754'
                    });
                    
                    // Ganti UI Button
                    btnElement.className = 'btn btn-outline-secondary w-100 rounded-pill mb-2 disabled';
                    btnElement.innerHTML = '<i class="bi bi-check2-all me-1"></i>Selesai & Tertutup';
                    
                    // Ubah badge
                    const card = btnElement.closest('.card');
                    card.classList.remove('border-success');
                    card.style.backgroundColor = '#ffffff';
                    card.style.opacity = '0.7';
                }, 1200);
            }
        });
    }
    function reportReturn(poNumber, customer, btnElement) {
        Swal.fire({
            title: 'Formulir Retur Barang',
            html: `
                <div class="text-start mt-2">
                    <p class="mb-3 text-muted">Laporkan barang yang ditolak oleh <strong>${customer}</strong> untuk dokumen <strong>${poNumber}</strong>.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Pilih SKU yang Bermasalah</label>
                        <select id="returSku" class="form-select">
                            <option value="BP-5KG-WHT">Cat Tembok Putih 5Kg</option>
                            <option value="BP-20KG-BLU">Cat Pelapis Biru 20Kg</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Jumlah (Qty) Retur</label>
                        <input type="number" id="returQty" class="form-control" value="1" min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Alasan Retur</label>
                        <select id="returReason" class="form-select">
                            <option value="Lecet/Penyok">Kemasan Lecet / Penyok</option>
                            <option value="Bocor">Bocor / Tumpah</option>
                            <option value="Salah Kirim">Salah Kirim Varian</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Bukti Surat Jalan (Coretan) & Fisik</label>
                        <input type="file" id="returFile" class="form-control form-control-sm">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-send"></i> Kirim Laporan Retur',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#dc3545',
            preConfirm: () => {
                const qty = document.getElementById('returQty').value;
                const sku = document.getElementById('returSku').options[document.getElementById('returSku').selectedIndex].text;
                const reason = document.getElementById('returReason').options[document.getElementById('returReason').selectedIndex].text;
                
                if (!qty || qty < 1) {
                    Swal.showValidationMessage('Jumlah retur harus diisi!');
                    return false;
                }
                
                document.getElementById('hr_po').value = poNumber;
                document.getElementById('hr_customer').value = customer;
                document.getElementById('hr_sku').value = sku;
                document.getElementById('hr_qty').value = qty;
                document.getElementById('hr_reason').value = reason;
                
                return true;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Mengirim Laporan...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });
                document.getElementById('hiddenReturnForm').submit();
            }
        });
    }
</script>
@endpush