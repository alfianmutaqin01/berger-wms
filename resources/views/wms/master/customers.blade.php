@extends('layouts.wms')

@section('title', 'Master Customers')
@section('page_title', 'Master Customers')

@section('content')
<div class="row mb-4">
    <div class="col-12 col-md-8">
        <h4 class="fw-bold text-dark mb-0">Manajemen Pelanggan</h4>
        <p class="text-muted">Kelola basis data pelanggan aktif dan review pengajuan pelanggan baru dari Sales.</p>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom pt-4 px-4 pb-0">
        <!-- Nav Tabs -->
        <ul class="nav nav-tabs border-bottom-0" id="customerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold px-4 py-3" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button" role="tab">
                    <i class="bi bi-person-check me-2"></i>Pelanggan Aktif
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold px-4 py-3 text-danger" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                    <i class="bi bi-person-exclamation me-2"></i>Menunggu Diterima <span class="badge bg-danger rounded-pill ms-2">1</span>
                </button>
            </li>
        </ul>
    </div>
    
    <div class="card-body p-0">
        <div class="tab-content" id="customerTabsContent">
            
            <!-- Tab: Pelanggan Aktif -->
            <div class="tab-pane fade show active" id="active" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center p-4 border-bottom bg-light">
                    <div class="input-group w-50">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0" placeholder="Cari pelanggan...">
                    </div>
                    <button class="btn btn-primary fw-semibold rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                        <i class="bi bi-plus-circle me-2"></i>Tambah Manual
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th class="ps-4">KODE</th>
                                <th>NAMA PELANGGAN</th>
                                <th>NO. TELEPON</th>
                                <th>ALAMAT</th>
                                <th>STATUS</th>
                                <th class="text-end pe-4">AKSI</th>
                            </tr>
                        </thead>
                        <tbody id="active-customers-list">
                            <tr>
                                <td class="ps-4 fw-bold text-muted">CUST-001</td>
                                <td class="fw-bold text-dark">Toko Besi Maju Jaya</td>
                                <td>0812-3456-7890</td>
                                <td>Jl. Raya Bekasi No. 45</td>
                                <td><span class="badge bg-success">Aktif</span></td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="Swal.fire('Fitur Edit', 'Simulasi form edit pelanggan aktif akan muncul di sini', 'info')"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="ps-4 fw-bold text-muted">CUST-002</td>
                                <td class="fw-bold text-dark">CV. Bintang Abadi</td>
                                <td>0819-8765-4321</td>
                                <td>Kawasan Industri Karawang</td>
                                <td><span class="badge bg-success">Aktif</span></td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-secondary" onclick="Swal.fire('Fitur Edit', 'Simulasi form edit pelanggan aktif akan muncul di sini', 'info')"><i class="bi bi-pencil"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Tab: Menunggu Diterima -->
            <div class="tab-pane fade" id="pending" role="tabpanel">
                <div class="table-responsive mt-3">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted small">
                            <tr>
                                <th class="ps-4">NAMA PENGAJU (SALES)</th>
                                <th>NAMA TOKO CALON</th>
                                <th>KONTAK</th>
                                <th>ALAMAT</th>
                                
                                <th class="text-end pe-4">AKSI REVIEW</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr id="pending-customer-row">
                                <td class="ps-4">
                                    <span class="fw-bold text-dark d-block">Budi Santoso</span>
                                    <small class="text-muted">Tgl Pengajuan: 19 Ags 2026</small>
                                </td>
                                <td>
                                    <h6 class="mb-0 fw-bold text-primary">Toko Cat Makmur Jaya</h6>
                                </td>
                                <td>0812-3456-7890</td>
                                <td>Jl. Raya Bogor KM 29, Depok</td>
                                
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#reviewCustomerModal">
                                        <i class="bi bi-file-earmark-person me-1"></i> Review Pengajuan
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>
@endsection

{{-- Sebelumnya ada `@push('modals')` nyasar tepat di atas baris ini yang tidak
     pernah ditutup `@endpush`. Blade membuka output buffer untuk tiap @push,
     sehingga buffer itu menggantung sepanjang request — terdeteksi sebagai
     "did not close its own output buffers" saat halaman ini dites. Isi blok
     di bawah memang skrip, jadi push 'modals' tersebut dihapus. --}}
@push('scripts')
<script>
    function approveCustomer() {
        Swal.fire({
            title: 'Memproses...',
            text: 'Mendaftarkan pelanggan ke sistem WMS',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        setTimeout(() => {
            // Remove from pending
            const pendingRow = document.getElementById('pending-customer-row');
            if (pendingRow) pendingRow.remove();
            
            // Hide the badge
            const badges = document.querySelectorAll('.badge.bg-danger.rounded-pill');
            badges.forEach(b => b.style.display = 'none');

            // Add to active
            const activeList = document.getElementById('active-customers-list');
            if (activeList) {
                const newRow = `
                    <tr class="table-success" style="transition: all 1s ease;">
                        <td class="ps-4 fw-bold text-muted">CUST-003</td>
                        <td class="fw-bold text-dark">Toko Cat Makmur Jaya</td>
                        <td>0812-3456-7890</td>
                        <td>Jl. Raya Bogor KM 29, Depok</td>
                        <td><span class="badge bg-success">Aktif</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-secondary" onclick="Swal.fire('Fitur Edit', 'Simulasi form edit pelanggan aktif akan muncul di sini', 'info')"><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                `;
                activeList.insertAdjacentHTML('beforeend', newRow);
            }

            Swal.fire({
                icon: 'success',
                title: 'Disetujui!',
                text: 'Pelanggan Toko Cat Makmur Jaya berhasil ditambahkan (Kode: CUST-003).',
                confirmButtonColor: '#198754'
            }).then(() => {
                // Switch to active tab
                const activeTab = new bootstrap.Tab(document.getElementById('active-tab'));
                activeTab.show();
            });
        }, 1200);
    }
</script>
@endpush
@push('modals')
<!-- Modal Review Customer -->
<div class="modal fade" id="reviewCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-person-lines-fill text-primary me-2"></i>Review Pengajuan Pelanggan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="fw-bold text-secondary mb-3 border-bottom pb-2">Data Toko/Perusahaan</h6>
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted fw-normal">Nama Toko</dt>
                            <dd class="col-sm-8 fw-semibold text-dark">Toko Cat Makmur Jaya</dd>
                            
                            <dt class="col-sm-4 text-muted fw-normal">Bentuk Usaha</dt>
                            <dd class="col-sm-8 text-dark">Toko Retail / Eceran</dd>

                            <dt class="col-sm-4 text-muted fw-normal">Alamat Lengkap</dt>
                            <dd class="col-sm-8 text-dark">Jl. Raya Bogor KM 29, Kec. Cimanggis, Kota Depok, Jawa Barat 16452</dd>
                        </dl>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold text-secondary mb-3 border-bottom pb-2">Kontak & Legalitas</h6>
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted fw-normal">Nama Pemilik</dt>
                            <dd class="col-sm-8 text-dark">Bpk. H. Sukamto</dd>

                            <dt class="col-sm-4 text-muted fw-normal">No. Telepon/WA</dt>
                            <dd class="col-sm-8 fw-semibold text-primary">0812-3456-7890</dd>

                            <dt class="col-sm-4 text-muted fw-normal">NPWP</dt>
                            <dd class="col-sm-8 text-dark font-monospace">01.234.567.8-412.000</dd>
                            
                            <dt class="col-sm-4 text-muted fw-normal">Lampiran</dt>
                            <dd class="col-sm-8">
                                <button class="btn btn-sm btn-light border text-primary"><i class="bi bi-file-earmark-image me-1"></i>Lihat KTP/NPWP</button>
                            </dd>
                        </dl>
                    </div>
                </div>
                
                <div class="mt-4 p-3 bg-light rounded-3 border">
                    <h6 class="fw-bold text-dark mb-2">Catatan Sales (Pengaju: Budi Santoso)</h6>
                    <p class="mb-0 small text-muted fst-italic">"Toko potensial, lokasi strategis di pinggir jalan raya utama. Rencana order perdana 50 Pail jika disetujui."</p>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0 pb-4 px-4 justify-content-between">
                <button type="button" class="btn btn-outline-danger fw-bold rounded-pill px-4" onclick="Swal.fire('Ditolak', 'Pengajuan pelanggan dikembalikan ke Sales.', 'error')" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Tolak Pengajuan
                </button>
                <button type="button" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm" onclick="approveCustomer()" data-bs-dismiss="modal">
                    <i class="bi bi-check-circle me-1"></i> Approve & Daftarkan
                </button>
            </div>
        </div>
    </div>
</div>
@endpush
@push('modals')
<!-- Modal Add Customer -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-person-plus text-primary me-2"></i>Tambah Pelanggan Manual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-4">Silakan isi form ini jika pendaftaran tidak dilakukan melalui aplikasi Sales.</p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Nama Pelanggan/Toko</label>
                    <input type="text" class="form-control" placeholder="Contoh: Toko Bangunan Jaya">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Nomor Telepon</label>
                    <input type="text" class="form-control" placeholder="08xx-xxxx-xxxx">
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold text-secondary">Alamat Lengkap</label>
                    <textarea class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm" onclick="Swal.fire('Berhasil', 'Pelanggan berhasil ditambahkan manual (Simulasi).', 'success')" data-bs-dismiss="modal">
                    Simpan Pelanggan
                </button>
            </div>
        </div>
    </div>
</div>
@endpush