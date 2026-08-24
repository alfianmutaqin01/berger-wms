@extends('layouts.soms')
@section('page_title', 'Buat Pesanan Baru')

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        
        <!-- Warning Alert (Hidden by default) -->
        <div id="alertBilling" class="alert alert-warning d-none align-items-center rounded-3 shadow-sm mb-4 border-warning" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-warning"></i>
            <div>
                <strong class="d-block mb-1 text-dark">Perhatian: Customer Menunggak</strong>
                <span class="small text-dark">Customer ini terdeteksi memiliki tagihan tempo yang belum lunas. Pesanan tetap dapat dilanjutkan, namun akan ditandai khusus untuk memerlukan tinjauan tambahan dari tim logistik/finance.</span>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-cart-plus text-primary me-2"></i> Form Pesanan Baru</h5>
                <p class="text-muted small mt-1">Lengkapi data kustomer dan item pesanan di bawah ini.</p>
            </div>
            
            <form action="/sales/new-order" method="POST" id="formOrder">
                @csrf
                <div class="card-body p-4">
                    
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Informasi Pelanggan & Pengiriman</h6>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Customer <span class="text-danger">*</span></label>
                            <input type="text" class="form-control bg-light" list="customerList" id="customerInput" name="customer" placeholder="Ketik nama kustomer..." required>
                            <datalist id="customerList">
                                <option value="Kusumana Food">
                                <option value="Toko Merah (Menunggak)">
                                <option value="CV Bangun Jaya">
                                <option value="PT Sentosa Abadi">
                            </datalist>
                            <div class="form-text small">Ketik nama untuk mencari dari daftar Approved Customer.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Gudang Pengiriman (Dispatch Code) <span class="text-danger">*</span></label>
                            <select class="form-select bg-light" name="dispatch_code" required>
                                <option value="" selected disabled>Pilih gudang asal pengiriman...</option>
                                <option value="WH-01">Gudang Karawang</option>
                                <option value="WH-02">Gudang Pekanbaru</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted">Syarat Pembayaran (Payment Term) <span class="text-danger">*</span></label>
                            <select class="form-select bg-light" name="payment_term" required>
                                <option value="Cash">Cash / Tunai</option>
                                <option value="Transfer">Transfer Bank</option>
                                <option value="Tempo 30">Tempo 30 Hari</option>
                                <option value="Tempo 60">Tempo 60 Hari</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold text-muted">Foto Bukti Pesanan / Catatan Toko <span class="fw-normal">(Opsional)</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="bi bi-camera text-muted"></i></span>
                                <input type="file" class="form-control bg-light" name="bukti_pesanan" accept="image/png, image/jpeg" capture="environment">
                            </div>
                                                        <div class="form-text small">Anda bisa memotret catatan PO toko langsung dari HP Anda. (Max 2MB)</div>
                            <div class="form-check mt-3 border p-2 rounded bg-white shadow-sm">
                                <input class="form-check-input ms-1" type="checkbox" id="documentOnlyCheck" name="document_only">
                                <label class="form-check-label ms-2 text-dark small fw-semibold" for="documentOnlyCheck">
                                    Pesanan sesuai dengan dokumen yang diupload. <span class="text-muted fw-normal">(Abaikan input rincian item di bawah)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Item Pesanan</h6>
                    
                    <div id="itemList">
                        <!-- Item Row 1 -->
                        <div class="row g-2 align-items-end mb-3 item-row border-bottom pb-3">
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold text-muted">Kode SKU <span class="text-danger">*</span></label>
                                <input type="text" class="form-control sku-code-input bg-light" list="skuCodeList" name="sku_code[]" placeholder="Misal: BP-5KG-WHT" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold text-muted">Deskripsi Produk <span class="text-danger">*</span></label>
                                <input type="text" class="form-control sku-desc-input bg-light" list="skuDescList" name="sku_desc[]" placeholder="Ketik nama produk..." required>
                            </div>
                            <div class="col-5 col-md-2">
                                <label class="form-label small fw-semibold text-muted">Qty <span class="text-danger">*</span></label>
                                <input type="number" class="form-control text-center" name="qty[]" min="1" required placeholder="0">
                            </div>
                            <div class="col-7 col-md-3 text-end text-md-center pb-1 d-flex align-items-center justify-content-end justify-content-md-center gap-2">
                                <div class="stock-indicator" style="display: none;" title="Status Ketersediaan"></div>
                                <button type="button" class="btn btn-outline-danger btn-sm btn-remove-item d-none"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>

                    <datalist id="skuCodeList">
                        <option value="BP-5KG-WHT">
                        <option value="BP-20KG-BLU">
                        <option value="BP-1KG-RED">
                        <option value="BP-25KG-YEL">
                    </datalist>
                    <datalist id="skuDescList">
                        <option value="Cat Tembok Berger White 5Kg">
                        <option value="Cat Pelapis Berger Blue 20Kg">
                        <option value="Cat Minyak Berger Red 1Kg">
                        <option value="Cat Jalan Berger Yellow 25Kg">
                    </datalist>

                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 mt-2" id="btnAddItem">
                        <i class="bi bi-plus-circle me-1"></i> Tambah Produk
                    </button>

                </div>
                
                @php
                    $currentHour = now()->format('H');
                    $isLate = $currentHour >= 15;
                @endphp
                <div class="card-footer bg-light border-top p-4 rounded-bottom-4">
                    @if($isLate)
                    <div class="alert alert-warning d-flex align-items-center mb-3 p-2" role="alert">
                        <i class="bi bi-clock-history fs-5 me-2"></i>
                        <span class="small fw-semibold">Batas waktu pemesanan hari ini (15:00 WIB) sudah lewat. Anda hanya dapat menyimpan sebagai draft. Silakan submit kembali besok.</span>
                    </div>
                    @endif
                    <div class="d-flex justify-content-end gap-2">
                        <button type="submit" name="action" value="draft" class="btn btn-secondary px-4 shadow-sm" id="btnDraft">Simpan Draft</button>
                        <button type="submit" name="action" value="submit" class="btn btn-primary px-4 shadow-sm" id="btnSubmit" {{ $isLate ? 'disabled' : '' }}>
                            @if($isLate) <i class="bi bi-lock-fill me-1"></i> @endif Submit & Request Approval
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
                // Document-only Checkbox Logic
        const documentOnlyCheck = document.getElementById('documentOnlyCheck');
        const itemListSection = document.getElementById('itemList');
        
        documentOnlyCheck.addEventListener('change', function() {
            const isChecked = this.checked;
            const inputs = itemListSection.querySelectorAll('input');
            const btnAdd = document.getElementById('btnAddItem');
            
            if(isChecked) {
                itemListSection.style.opacity = '0.4';
                itemListSection.style.pointerEvents = 'none';
                if(btnAdd) btnAdd.disabled = true;
                
                inputs.forEach(input => {
                    input.removeAttribute('required');
                });
            } else {
                itemListSection.style.opacity = '1';
                itemListSection.style.pointerEvents = 'auto';
                if(btnAdd) btnAdd.disabled = false;
                
                inputs.forEach(input => {
                    input.setAttribute('required', 'required');
                });
            }
        });

        // Validation Customer Block
        const customerInput = document.getElementById('customerInput');
        const alertBilling = document.getElementById('alertBilling');
        const btnDraft = document.getElementById('btnDraft');
        const btnSubmit = document.getElementById('btnSubmit');

        customerInput.addEventListener('change', function() {
            if(this.value.includes('Menunggak')) {
                alertBilling.classList.remove('d-none');
                alertBilling.classList.add('d-flex');
                // Tombol tidak lagi di-disable, tetapi kita bisa mengubah tampilannya sedikit jika mau
                this.classList.add('is-invalid');
                // Opsional: tambahkan class khusus jika kita ingin membedakan warna input
                this.style.borderColor = '#ffc107'; 
                this.style.paddingRight = '2.25rem';
                            } else {
                alertBilling.classList.add('d-none');
                alertBilling.classList.remove('d-flex');
                this.classList.remove('is-invalid');
                this.style.borderColor = '';
                this.style.backgroundImage = '';
            }
        });

        // Dynamic Add Item
        const btnAddItem = document.getElementById('btnAddItem');
        const itemList = document.getElementById('itemList');
        
        btnAddItem.addEventListener('click', function() {
            const row = itemList.querySelector('.item-row').cloneNode(true);
            row.querySelector('.sku-code-input').value = ''; row.querySelector('.sku-desc-input').value = '';
            row.querySelector('input[type="number"]').value = '';
            row.querySelector('.stock-indicator').style.display = 'none';
            row.querySelector('.btn-remove-item').classList.remove('d-none');
            
            // Attach event listeners to the new row
            attachSkuListener(row);
            
            row.querySelector('.btn-remove-item').addEventListener('click', function() {
                if(itemList.querySelectorAll('.item-row').length > 1) {
                    row.remove();
                }
            });

            itemList.appendChild(row);
        });

                // Product Catalog for Autocomplete
        const productCatalog = {
            "BP-5KG-WHT": "Cat Tembok Berger White 5Kg",
            "BP-20KG-BLU": "Cat Pelapis Berger Blue 20Kg",
            "BP-1KG-RED": "Cat Minyak Berger Red 1Kg",
            "BP-25KG-YEL": "Cat Jalan Berger Yellow 25Kg"
        };
        const reverseCatalog = {};
        for (const [sku, desc] of Object.entries(productCatalog)) {
            reverseCatalog[desc] = sku;
        }

        // Blind Stock Indicator Simulation & Autocomplete Sync
        function attachSkuListener(row) {
            const skuInput = row.querySelector('.sku-code-input');
            const descInput = row.querySelector('.sku-desc-input');
            const indicator = row.querySelector('.stock-indicator');
            
            const updateIndicator = (val) => {
                if(!val) {
                    indicator.style.display = 'none';
                    return;
                }
                indicator.style.display = 'block';
                if(val.includes('WHT')) {
                    indicator.innerHTML = '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle fs-6 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i> Tersedia</span>';
                } else if(val.includes('BLU')) {
                    indicator.innerHTML = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fs-6 rounded-pill"><i class="bi bi-exclamation-triangle-fill me-1"></i> Terbatas</span>';
                } else if(val.includes('RED')) {
                    indicator.innerHTML = '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle fs-6 rounded-pill"><i class="bi bi-x-circle-fill me-1"></i> Kosong</span>';
                } else {
                    indicator.innerHTML = '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle fs-6 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i> Tersedia</span>';
                }
            };

            skuInput.addEventListener('input', function() {
                const val = this.value;
                if (productCatalog[val]) {
                    descInput.value = productCatalog[val];
                }
                updateIndicator(val);
            });

            descInput.addEventListener('input', function() {
                const val = this.value;
                if (reverseCatalog[val]) {
                    skuInput.value = reverseCatalog[val];
                    updateIndicator(skuInput.value);
                }
            });
            
            if(skuInput.value) updateIndicator(skuInput.value);
        }

        // Attach listener to first row
        attachSkuListener(document.querySelector('.item-row'));        
        // Form Submit Interception for SweetAlert Notifications
        const form = document.querySelector('form');
        let submitAction = '';

        btnDraft.addEventListener('click', function() {
            submitAction = 'draft';
        });

        btnSubmit.addEventListener('click', function() {
            submitAction = 'submit';
        });

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (submitAction === 'draft') {
                    Swal.fire({
                        icon: 'info',
                        title: 'Disimpan sebagai Draft',
                        text: 'Pesanan Anda berhasil disimpan. Jangan lupa untuk menekan Submit nanti jika Anda sudah yakin untuk memproses pesanan ini ke gudang!',
                        confirmButtonText: 'Mengerti',
                        confirmButtonColor: '#6c757d'
                    }).then(() => {
                        window.location.href = '/sales/my-orders';
                    });
                } else if (submitAction === 'submit') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Pesanan Terkirim!',
                        text: 'Pesanan Anda berhasil disubmit dan saat ini sedang menunggu persetujuan (approval) dari Admin WMS.',
                        confirmButtonText: 'Kembali ke Dashboard',
                        confirmButtonColor: '#198754'
                    }).then(() => {
                        window.location.href = '/sales/my-orders';
                    });
                }
            });
        }
        // Auto-fill from draft if URL has ?draft=...
        const urlParams = new URLSearchParams(window.location.search);
        const draftId = urlParams.get('draft');
        if (draftId === 'DRAFT-992') {
            // Mock prefill data for DRAFT-992 (Kusumana Food)
            setTimeout(() => {
                document.getElementById('customerInput').value = 'Kusumana Food';
                document.querySelector('select[name="dispatch_code"]').value = 'WH-01';
                document.querySelector('select[name="payment_term"]').value = 'Tempo 30';
                
                const firstRow = document.querySelector('.item-row');
                const skuInput = firstRow.querySelector('.sku-code-input');
                skuInput.value = 'BP-5KG-WHT';
                skuInput.dispatchEvent(new Event('input')); // trigger autocomplete & indicator
                
                firstRow.querySelector('input[type="number"]').value = '50';
                
                Swal.fire({
                    icon: 'success',
                    title: 'Draft Berhasil Dimuat',
                    text: 'Data untuk ' + draftId + ' berhasil dikembalikan ke dalam form.',
                    confirmButtonText: 'Lanjutkan Edit',
                    confirmButtonColor: '#0d6efd'
                });
            }, 300); // small delay to ensure DOM is fully ready
        }
    });
</script>
@endpush

