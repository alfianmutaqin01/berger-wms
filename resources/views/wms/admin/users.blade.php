@extends('layouts.wms')

@section('title', 'Manajemen User')
@section('page_title', 'Manajemen User')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-people text-primary me-2"></i> Daftar Pengguna Sistem</h5>
                    <p class="text-muted small mt-1">Role: Super Admin. Kelola hak akses, tambah, ubah, atau nonaktifkan akun pengguna.</p>
                </div>
                <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus me-1"></i> Tambah User Baru
                </button>
            </div>
            <div class="card-body p-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="input-group" style="max-width: 300px;">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Cari Nama / Email...">
                    </div>
                    <div>
                        <select class="form-select bg-light border-0">
                            <option value="">Semua Role</option>
                            <option value="Tim Produksi">Tim Produksi</option>
                            <option value="Tim Logistik">Tim Logistik</option>
                            <option value="Admin Gudang">Admin Gudang</option>
                            <option value="Sales">Sales</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold ps-3">NAMA LENGKAP</th>
                                <th class="text-secondary small fw-semibold">EMAIL / USERNAME</th>
                                <th class="text-secondary small fw-semibold">ROLE AKSES</th>
                                <th class="text-secondary small fw-semibold text-center">STATUS</th>
                                <th class="text-secondary small fw-semibold text-center pe-3">AKSI</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary-subtle text-primary rounded-circle d-flex justify-content-center align-items-center me-3 fw-bold" style="width: 35px; height: 35px; font-size: 0.8rem;">
                                            BS
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Budi Santoso</h6>
                                            <small class="text-muted">ID: USR-001</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="d-block text-dark">budi.s@bergerpaints.co.id</span>
                                </td>
                                <td><span class="badge bg-info-subtle text-info border border-info px-2 py-1"><i class="bi bi-shield-lock me-1"></i> Tim Produksi</span></td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i> Aktif</span>
                                </td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary me-1" title="Edit User" onclick="editUser('Budi Santoso', 'budi.s@bergerpaints.co.id', 'Tim Produksi')"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" title="Nonaktifkan" onclick="deleteUser('Budi Santoso')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-warning-subtle text-warning rounded-circle d-flex justify-content-center align-items-center me-3 fw-bold" style="width: 35px; height: 35px; font-size: 0.8rem;">
                                            AW
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Andi Wijaya</h6>
                                            <small class="text-muted">ID: USR-002</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="d-block text-dark">andi.w@bergerpaints.co.id</span>
                                </td>
                                <td><span class="badge bg-primary-subtle text-primary border border-primary px-2 py-1"><i class="bi bi-shield-check me-1"></i> Tim Logistik</span></td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i> Aktif</span>
                                </td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-secondary me-1" title="Edit User" onclick="editUser('Andi Wijaya', 'andi.w@bergerpaints.co.id', 'Tim Logistik')"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" title="Nonaktifkan" onclick="deleteUser('Andi Wijaya')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-danger-subtle text-danger rounded-circle d-flex justify-content-center align-items-center me-3 fw-bold" style="width: 35px; height: 35px; font-size: 0.8rem;">
                                            RN
                                        </div>
                                        <div>
                                            <h6 class="mb-0 fw-bold text-dark">Rina Novita</h6>
                                            <small class="text-muted">ID: USR-003</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="d-block text-dark">rina.n@bergerpaints.co.id</span>
                                </td>
                                <td><span class="badge bg-secondary-subtle text-secondary border border-secondary px-2 py-1"><i class="bi bi-briefcase me-1"></i> Sales</span></td>
                                <td class="text-center">
                                    <span class="badge bg-danger-subtle text-danger"><i class="bi bi-x-circle me-1"></i> Terkunci</span>
                                </td>
                                <td class="text-center pe-3">
                                    <button class="btn btn-sm btn-outline-success me-1" title="Buka Kunci Akun" onclick="alert('Kunci akun berhasil dibuka')"><i class="bi bi-unlock"></i></button>
                                    <button class="btn btn-sm btn-outline-secondary me-1" title="Edit User" onclick="editUser('Rina Novita', 'rina.n@bergerpaints.co.id', 'Sales')"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" title="Nonaktifkan" onclick="deleteUser('Rina Novita')"><i class="bi bi-trash"></i></button>
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

@push('modals')
<!-- Modal Tambah User -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-person-plus text-primary me-2"></i> Tambah User Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Nama Lengkap *</label>
                    <input type="text" class="form-control" id="addName" placeholder="Masukkan nama lengkap">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Email / Username *</label>
                    <input type="email" class="form-control" id="addEmail" placeholder="email@bergerpaints.co.id">
                </div>
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Password *</label>
                        <input type="password" class="form-control" placeholder="••••••••">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Hak Akses (Role) *</label>
                        <select class="form-select" id="addRole">
                            <option value="Tim Produksi">Tim Produksi</option>
                            <option value="Tim Logistik">Tim Logistik</option>
                            <option value="Admin Gudang">Admin Gudang</option>
                            <option value="Sales">Sales</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" onclick="saveNewUser()"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit User -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i> Edit Data User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Nama Lengkap</label>
                    <input type="text" class="form-control" id="editName">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Email / Username</label>
                    <input type="email" class="form-control bg-light" id="editEmail" readonly>
                </div>
                <div class="mb-0">
                    <label class="form-label small fw-semibold text-secondary">Ubah Hak Akses (Role)</label>
                    <select class="form-select" id="editRole">
                        <option value="Tim Produksi">Tim Produksi</option>
                        <option value="Tim Logistik">Tim Logistik</option>
                        <option value="Admin Gudang">Admin Gudang</option>
                        <option value="Sales">Sales</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" onclick="saveEditUser()"><i class="bi bi-check2 me-1"></i> Perbarui</button>
            </div>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    // Variables for modals
    let addModal, editModal;

    document.addEventListener('DOMContentLoaded', function() {
        addModal = new bootstrap.Modal(document.getElementById('addUserModal'));
        editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
    });

    function saveNewUser() {
        const name = document.getElementById('addName').value;
        const email = document.getElementById('addEmail').value;
        const role = document.getElementById('addRole').value;

        if(!name || !email) {
            alert('Mohon lengkapi form pendaftaran.');
            return;
        }

        let initials = name.substring(0, 2).toUpperCase();
        let badgeClass = role === 'Tim Produksi' ? 'bg-info-subtle text-info border-info' : 'bg-primary-subtle text-primary border-primary';

        const tbody = document.getElementById('userTableBody');
        const tr = document.createElement('tr');
        tr.innerHTML = 
            '<td class="ps-3">' +
                '<div class="d-flex align-items-center">' +
                    '<div class="bg-success-subtle text-success rounded-circle d-flex justify-content-center align-items-center me-3 fw-bold" style="width: 35px; height: 35px; font-size: 0.8rem;">' + initials + '</div>' +
                    '<div>' +
                        '<h6 class="mb-0 fw-bold text-dark">' + name + '</h6>' +
                        '<small class="text-muted">ID: USR-NEW</small>' +
                    '</div>' +
                '</div>' +
            '</td>' +
            '<td><span class="d-block text-dark">' + email + '</span></td>' +
            '<td><span class="badge ' + badgeClass + ' border px-2 py-1"><i class="bi bi-shield-lock me-1"></i> ' + role + '</span></td>' +
            '<td class="text-center"><span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i> Aktif</span></td>' +
            '<td class="text-center pe-3">' +
                '<button class="btn btn-sm btn-outline-secondary me-1" onclick="editUser(\'' + name + '\', \'' + email + '\', \'' + role + '\')"><i class="bi bi-pencil"></i></button>' +
                '<button class="btn btn-sm btn-outline-danger" onclick="deleteUser(\'' + name + '\')"><i class="bi bi-trash"></i></button>' +
            '</td>';
        
        tbody.insertBefore(tr, tbody.firstChild);
        addModal.hide();
        
        // Reset form
        document.getElementById('addName').value = '';
        document.getElementById('addEmail').value = '';
        
        alert('Simulasi: User berhasil ditambahkan!');
    }

    window.editUser = function(name, email, role) {
        document.getElementById('editName').value = name;
        document.getElementById('editEmail').value = email;
        document.getElementById('editRole').value = role;
        editModal.show();
    }

    window.saveEditUser = function() {
        editModal.hide();
        alert('Simulasi: Perubahan hak akses berhasil disimpan!');
    }

    window.deleteUser = function(name) {
        if(confirm('Apakah Anda yakin ingin menonaktifkan pengguna ' + name + '? (Sesuai aturan PRD, histori transaksi user tetap disimpan).')) {
            alert('User dinonaktifkan.');
        }
    }
</script>
@endpush