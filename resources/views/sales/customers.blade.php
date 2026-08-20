@extends('layouts.soms')
@section('title', 'My Customers')
@section('page_title', 'Daftar Pelanggan Saya')

@section('content')
<div class="row mb-4">
    <div class="col-12 col-md-8">
        <h4 class="fw-bold text-dark mb-0">Pelanggan Anda</h4>
        <p class="text-muted">Kelola dan ajukan toko/pelanggan baru untuk di-approve oleh Admin Logistik.</p>
    </div>
    <div class="col-12 col-md-4 text-md-end">
        <button class="btn btn-primary fw-semibold shadow-sm rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#ajukanCustomerModal">
            <i class="bi bi-person-plus-fill me-2"></i>Ajukan Pelanggan Baru
        </button>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
        <h6 class="fw-bold mb-0 text-dark">Riwayat Pengajuan Pelanggan</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small">
                    <tr>
                        <th class="ps-4">NAMA TOKO / PELANGGAN</th>
                        <th>KONTAK</th>
                        <th>TANGGAL PENGAJUAN</th>
                        <th>STATUS</th>
                        <th class="pe-4 text-end">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-4">
                            <h6 class="mb-0 fw-bold text-dark">Toko Cat Makmur Jaya</h6>
                            <small class="text-muted">Jl. Raya Bogor KM 29, Depok</small>
                        </td>
                        <td>
                            <span class="d-block">Bpk. Budi</span>
                            <small class="text-muted">0812-3456-7890</small>
                        </td>
                        <td>19 Ags 2026</td>
                        <td><span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Menunggu Approval</span></td>
                        <td class="pe-4 text-end">
                            <button class="btn btn-sm btn-light border" onclick="showCustomerDetail('Toko Cat Makmur Jaya')"><i class="bi bi-eye"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4">
                            <h6 class="mb-0 fw-bold text-dark">CV Bintang Bangunan</h6>
                            <small class="text-muted">Kawasan Industri Cikarang Blok C</small>
                        </td>
                        <td>
                            <span class="d-block">Ibu Linda</span>
                            <small class="text-muted">0819-8765-4321</small>
                        </td>
                        <td>15 Ags 2026</td>
                        <td><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approved (Aktif)</span></td>
                        <td class="pe-4 text-end">
                            <a href="/sales/new-order" class="btn btn-sm btn-outline-primary rounded-pill">Buat PO</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('modals')
<!-- Modal Ajukan Customer Baru -->
<div class="modal fade" id="ajukanCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light border-0 pt-4 px-4">
                <div>
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-shop text-primary me-2"></i>Form Pengajuan Pelanggan</h5>
                    <p class="text-muted small mb-0 mt-1">Data akan dikirim ke Admin untuk diverifikasi sebelum bisa digunakan.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form action="#" method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Nama Toko / Perusahaan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg fs-6" placeholder="Misal: TB. Sumber Rezeki" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Nama Penanggung Jawab / PIC</label>
                            <input type="text" class="form-control form-control-lg fs-6" placeholder="Nama pemilik/PIC">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Nomor Telepon / WhatsApp <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control form-control-lg fs-6" placeholder="Contoh: 08123456789" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Batas Plafon Kredit (Opsional)</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control form-control-lg fs-6" placeholder="Kosongkan jika Cash">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted small fw-bold">Alamat Pengiriman / Toko <span class="text-danger">*</span></label>
                            <textarea class="form-control form-control-lg fs-6" rows="3" placeholder="Masukkan alamat lengkap dengan kodepos" required></textarea>
                        </div>
                        <div class="col-12 mt-4">
                            <label class="form-label text-muted small fw-bold"><i class="bi bi-camera me-1"></i> Foto Toko / NPWP (Opsional)</label>
                            <div class="border border-2 border-dashed rounded-3 p-4 text-center bg-light" style="cursor: pointer;">
                                <i class="bi bi-cloud-arrow-up display-6 text-muted mb-2"></i>
                                <p class="mb-0 text-muted">Klik atau drag gambar ke sini untuk upload</p>
                                <small class="text-secondary">Format: JPG, PNG (Max 5MB)</small>
                                <input type="file" class="d-none" accept="image/png, image/jpeg">
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-5">
                        <button type="button" class="btn btn-light fw-semibold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                        <button type="button" class="btn btn-primary fw-semibold rounded-pill px-4" data-bs-dismiss="modal" onclick="submitCustomer()"><i class="bi bi-send me-2"></i>Kirim Pengajuan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    function submitCustomer() {
        Swal.fire({
            icon: 'success',
            title: 'Pengajuan Terkirim!',
            text: 'Data pelanggan baru berhasil dikirim dan sedang menunggu verifikasi dari Admin Logistik.',
            confirmButtonText: 'Kembali',
            confirmButtonColor: '#198754'
        });
    }

    function showCustomerDetail(name) {
        Swal.fire({
            title: 'Profil Pelanggan',
            html: `
                <div class="text-start mt-3">
                    <div class="text-center mb-4">
                        <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center border" style="width: 80px; height: 80px;">
                            <i class="bi bi-shop fs-1 text-primary"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-0">${name}</h5>
                        <span class="badge bg-warning text-dark mt-2"><i class="bi bi-hourglass-split"></i> Menunggu Approval</span>
                    </div>
                    <ul class="list-group list-group-flush border-top border-bottom">
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center">
                            <span class="text-muted small"><i class="bi bi-person me-2"></i>PIC / Kontak</span>
                            <span class="fw-semibold">Bpk. Budi (0812-3456-7890)</span>
                        </li>
                        <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center">
                            <span class="text-muted small"><i class="bi bi-credit-card me-2"></i>Plafon Kredit</span>
                            <span class="fw-semibold">Rp 50.000.000</span>
                        </li>
                        <li class="list-group-item px-0 py-3">
                            <span class="text-muted d-block small mb-1"><i class="bi bi-geo-alt me-2"></i>Alamat Toko</span>
                            <span class="fw-semibold">Jl. Raya Bogor KM 29, Depok, Jawa Barat</span>
                        </li>
                    </ul>
                </div>
            `,
            confirmButtonText: 'Tutup',
            confirmButtonColor: '#6c757d',
            width: '450px'
        });
    }
</script>
@endpush