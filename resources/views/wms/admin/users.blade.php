@extends('layouts.wms')
@section('title', 'Manajemen User')

@section('content')
<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h4 class="fw-bold text-dark mb-0">Management User</h4>
            <p class="text-muted mb-0">Kelola daftar akun, hak akses (role), dan status karyawan di sistem WMS.</p>
        </div>
        <button class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openUserModal('add')">
            <i class="bi bi-person-plus-fill me-2"></i> Tambah User Baru
        </button>
    </div>
</div>

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

<!-- Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Total Karyawan</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ $stats['total'] }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Akun Aktif</h6>
                <h3 class="mb-0 fw-bold text-success">{{ $stats['active'] }}</h3>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-secondary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Non-aktif / Resign</h6>
                <h3 class="mb-0 fw-bold text-secondary">{{ $stats['inactive'] }}</h3>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-people text-primary me-2"></i>Daftar Karyawan Terdaftar</h6>
        </div>

        <!-- Filter: submit via GET agar hasil filter bisa di-bookmark & di-share -->
        <form method="GET" action="{{ route('wms.users.index') }}" class="row g-2 mt-2 mb-3">
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-light border-start-0" placeholder="Cari NIK, Nama, Email...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="role_id" class="form-select form-select-sm">
                    <option value="">Semua Role</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->id }}" @selected($filters['role_id'] == $role->id)>{{ $role->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select form-select-sm">
                    <option value="">Semua Gudang</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($filters['warehouse_id'] == $warehouse->id)>{{ $warehouse->display_label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <option value="active" @selected($filters['status'] === 'active')>Aktif</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Non-aktif</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Terapkan</button>
                <a href="{{ route('wms.users.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>

    <div class="card-body p-4 pt-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light text-muted small">
                    <tr>
                        <th width="50">PROFIL</th>
                        <th>NAMA &amp; EMAIL</th>
                        <th>NIK</th>
                        <th>ROLE &amp; DIVISI</th>
                        <th>LOKASI TUGAS</th>
                        <th>STATUS</th>
                        <th class="text-end">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                    @php
                        // Manager tidak boleh menyentuh akun Super Admin. Tombol aksi
                        // disembunyikan agar tampilan jujur, namun penegakan aturan
                        // sebenarnya ada di server (User::canManage + Form Request).
                        $editable = $actor?->canManage($user) ?? false;
                        $dimmed = $user->is_active ? '' : 'opacity-50';

                        // Payload untuk mengisi modal edit. Disiapkan di sini (bukan
                        // inline di atribut onclick) agar Blade tidak salah membaca
                        // tanda kurung array yang terpotong antar baris.
                        $payload = [
                            'id' => $user->id,
                            'employee_id' => $user->employee_id,
                            'full_name' => $user->full_name,
                            'email' => $user->email,
                            'phone_number' => $user->phone_number,
                            'role_id' => $user->role_id,
                            'department_id' => $user->department_id,
                            'warehouse_id' => $user->warehouse_id,
                            'manager_id' => $user->manager_id,
                            'is_active' => $user->is_active,
                        ];
                    @endphp
                    <tr>
                        <td>
                            @if($user->avatar_path)
                                <img src="{{ Storage::url($user->avatar_path) }}" alt="Foto {{ $user->full_name }}"
                                     class="rounded-circle shadow-sm {{ $dimmed }}" style="width: 40px; height: 40px; object-fit: cover;">
                            @else
                                <div class="avatar-circle bg-primary text-white d-flex justify-content-center align-items-center fw-bold rounded-circle shadow-sm {{ $dimmed }}"
                                     style="width: 40px; height: 40px;">{{ $user->initials }}</div>
                            @endif
                        </td>
                        <td class="{{ $dimmed }}">
                            <div class="fw-bold text-dark">{{ $user->full_name }}</div>
                            <div class="small text-muted">{{ $user->email }}</div>
                        </td>
                        <td class="{{ $dimmed }}">
                            <span class="badge bg-light text-dark border font-monospace">{{ $user->employee_id ?? '—' }}</span>
                        </td>
                        <td class="{{ $dimmed }}">
                            <span class="fw-semibold text-primary">{{ $user->role?->name ?? 'Tanpa Role' }}</span><br>
                            <span class="small text-muted"><i class="bi bi-building"></i> {{ $user->department?->name ?? '—' }}</span>
                        </td>
                        <td class="{{ $dimmed }}">
                            @if($user->warehouse)
                                <span class="badge bg-secondary">{{ $user->warehouse->display_label }}</span>
                            @else
                                <span class="badge bg-info text-dark">Semua Gudang</span>
                            @endif
                        </td>
                        <td>
                            @if($user->is_active)
                                <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-check-circle me-1"></i> Aktif</span>
                            @else
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-x-circle me-1"></i> Non-aktif</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if($editable)
                                <button class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                        data-bs-toggle="modal" data-bs-target="#userModal"
                                        onclick='openUserModal("edit", @json($payload))'>
                                    <i class="bi bi-pencil me-1"></i> Edit
                                </button>
                                <form action="{{ route('wms.users.status', $user) }}" method="POST" class="d-inline js-confirm-toggle"
                                      data-name="{{ $user->full_name }}" data-action="{{ $user->is_active ? 'menonaktifkan' : 'mengaktifkan' }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-{{ $user->is_active ? 'danger' : 'success' }} rounded-pill px-3"
                                            title="{{ $user->is_active ? 'Nonaktifkan akun' : 'Aktifkan akun' }}">
                                        <i class="bi bi-{{ $user->is_active ? 'person-slash' : 'person-check' }}"></i>
                                    </button>
                                </form>
                            @else
                                <span class="text-muted small fst-italic" title="Manager tidak berwenang mengubah akun Super Admin">
                                    <i class="bi bi-lock"></i> Terkunci
                                </span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-2 opacity-50"></i>
                            Tidak ada karyawan yang cocok dengan filter saat ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <span class="small text-muted">
                Menampilkan {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} dari {{ $users->total() }} Karyawan
            </span>
            {{ $users->onEachSide(1)->links('pagination::bootstrap-5') }}
        </div>
    </div>
</div>

@endsection

@push('modals')
<!-- User Management Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark" id="modalTitle"><i class="bi bi-person-plus text-primary me-2"></i> Tambah Karyawan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="userForm" method="POST" action="{{ route('wms.users.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_method" id="formMethod" value="POST">

                <div class="modal-body py-4 bg-light">
                    @if($errors->any())
                    <div class="alert alert-danger border-0 rounded-3">
                        <strong class="d-block mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Periksa kembali isian berikut:</strong>
                        <ul class="mb-0 small">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    <div class="row g-4">
                        <!-- Kolom Kiri: Identitas Dasar -->
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-body">
                                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-person-badge text-primary me-2"></i>Identitas Dasar</h6>

                                    <div class="text-center mb-3">
                                        <div class="position-relative d-inline-block">
                                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100' height='100' fill='%23e9ecef'/%3E%3Ccircle cx='50' cy='38' r='17' fill='%23adb5bd'/%3E%3Cpath d='M18 96a32 32 0 0164 0z' fill='%23adb5bd'/%3E%3C/svg%3E"
                                                 alt="Pratinjau foto profil" class="rounded-circle shadow-sm mb-2" id="previewAvatar" style="width: 100px; height: 100px; object-fit: cover;">
                                            <label for="avatarInput" class="position-absolute bottom-0 end-0 bg-primary text-white rounded-circle p-1" style="cursor: pointer;">
                                                <i class="bi bi-camera-fill"></i>
                                            </label>
                                            <input type="file" class="d-none" id="avatarInput" name="avatar" accept="image/png, image/jpeg">
                                        </div>
                                        <div class="small text-muted">Upload Foto Profil <span class="text-secondary">(JPG/PNG, maks 2MB)</span></div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formNik">Employee ID (NIK) *</label>
                                        <input type="text" class="form-control font-monospace @error('employee_id') is-invalid @enderror"
                                               placeholder="Contoh: EMP-2026-xxx" required id="formNik" name="employee_id" value="{{ old('employee_id') }}">
                                        @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formName">Nama Lengkap *</label>
                                        <input type="text" class="form-control @error('full_name') is-invalid @enderror"
                                               placeholder="Nama sesuai KTP" required id="formName" name="full_name" value="{{ old('full_name') }}">
                                        @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formPhone">Nomor Telepon/WA</label>
                                        <input type="tel" class="form-control @error('phone_number') is-invalid @enderror"
                                               placeholder="0812-xxxx-xxxx" id="formPhone" name="phone_number" value="{{ old('phone_number') }}">
                                        @error('phone_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Kolom Tengah: Keamanan & Autentikasi -->
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-body">
                                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-shield-lock text-primary me-2"></i>Keamanan Akun</h6>

                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formEmail">Email Perusahaan *</label>
                                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                                               placeholder="nama@berger.co.id" required id="formEmail" name="email" value="{{ old('email') }}">
                                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text small" style="font-size:0.7rem;">Gunakan format baku nama.divisi@berger.co.id</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formPassword">Password <span id="passwordRequiredMark">*</span></label>
                                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                                               placeholder="Masukkan kata sandi" id="formPassword" name="password" autocomplete="new-password">
                                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text text-warning small" style="font-size:0.7rem;">
                                            <i class="bi bi-info-circle"></i> Minimal 8 karakter, kombinasi huruf dan angka. Password di-hash otomatis.
                                        </div>
                                        <div class="form-text small d-none" id="passwordEditHint" style="font-size:0.7rem;">
                                            Kosongkan bila tidak ingin mengubah kata sandi.
                                        </div>
                                    </div>

                                    <hr>

                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary">Status Akun</label>
                                        <div class="form-check form-switch fs-5">
                                            <input class="form-check-input" type="checkbox" role="switch" id="statusSwitch" name="is_active" value="1" checked>
                                            <label class="form-check-label fs-6 mt-1" for="statusSwitch">Aktif (Dapat Login)</label>
                                        </div>
                                        <div class="form-text small text-muted mt-0" style="font-size:0.7rem;">
                                            Matikan switch ini jika karyawan sudah resign. Data historisnya tetap tersimpan.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Kolom Kanan: Peran & Otorisasi -->
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-4 h-100">
                                <div class="card-body">
                                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-diagram-3 text-primary me-2"></i>Peran &amp; Otorisasi</h6>

                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formDepartment">Departemen / Divisi *</label>
                                        <select class="form-select @error('department_id') is-invalid @enderror" required id="formDepartment" name="department_id">
                                            <option value="">Pilih Departemen...</option>
                                            @foreach($departments as $department)
                                                <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formRole">Role Akses (Sistem) *</label>
                                        <select class="form-select @error('role_id') is-invalid @enderror" required id="formRole" name="role_id">
                                            <option value="">Pilih Role...</option>
                                            @foreach($roles as $role)
                                                <option value="{{ $role->id }}" @selected(old('role_id') == $role->id)>{{ $role->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('role_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        @unless($actor?->isSuperAdmin())
                                        <div class="form-text small" style="font-size:0.7rem;">
                                            <i class="bi bi-info-circle"></i> Role Super Admin hanya dapat ditetapkan oleh Super Admin.
                                        </div>
                                        @endunless
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formWarehouse">Lokasi Tugas (Warehouse)</label>
                                        <select class="form-select @error('warehouse_id') is-invalid @enderror" id="formWarehouse" name="warehouse_id">
                                            <option value="">-- Akses Bebas / Semua --</option>
                                            @foreach($warehouses as $warehouse)
                                                <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->display_label }}</option>
                                            @endforeach
                                        </select>
                                        @error('warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text small" style="font-size:0.7rem;">Membatasi data stok yang bisa dilihat user ini.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold text-secondary" for="formManager">Atasan Langsung (Direct Manager)</label>
                                        <select class="form-select @error('manager_id') is-invalid @enderror" id="formManager" name="manager_id">
                                            <option value="">-- Tanpa Atasan --</option>
                                            @foreach($managers as $manager)
                                                <option value="{{ $manager->id }}" @selected(old('manager_id') == $manager->id)>
                                                    {{ $manager->full_name }} ({{ $manager->employee_id }})
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('manager_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer bg-white border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="btnSubmitUser"><i class="bi bi-save me-1"></i> Simpan Data User</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    const USER_STORE_URL = @json(route('wms.users.store'));
    const USER_UPDATE_URL = @json(url('/wms/admin/users'));
    const AVATAR_PLACEHOLDER = document.getElementById('previewAvatar')?.src;

    /**
     * Menyiapkan modal untuk mode tambah atau ubah.
     *
     * @param {'add'|'edit'} mode
     * @param {Object|null} user Data user untuk mode edit.
     */
    function openUserModal(mode, user = null) {
        const form = document.getElementById('userForm');
        const title = document.getElementById('modalTitle');
        const methodField = document.getElementById('formMethod');
        const passwordField = document.getElementById('formPassword');
        const requiredMark = document.getElementById('passwordRequiredMark');
        const editHint = document.getElementById('passwordEditHint');
        const nikField = document.getElementById('formNik');

        if (mode === 'add') {
            title.innerHTML = '<i class="bi bi-person-plus text-primary me-2"></i> Tambah Karyawan Baru';
            form.action = USER_STORE_URL;
            methodField.value = 'POST';

            form.reset();
            nikField.readOnly = false;
            document.getElementById('statusSwitch').checked = true;
            document.getElementById('previewAvatar').src = AVATAR_PLACEHOLDER;

            // Password wajib saat membuat akun baru.
            passwordField.required = true;
            requiredMark.classList.remove('d-none');
            editHint.classList.add('d-none');
            return;
        }

        title.innerHTML = '<i class="bi bi-pencil-square text-primary me-2"></i> Edit Data Karyawan';
        form.action = `${USER_UPDATE_URL}/${user.id}`;
        methodField.value = 'PUT';

        nikField.value = user.employee_id ?? '';
        // NIK berasal dari HRD dan dipakai sebagai rujukan lintas dokumen,
        // sehingga tidak diubah lewat layar ini.
        nikField.readOnly = true;

        document.getElementById('formName').value = user.full_name ?? '';
        document.getElementById('formEmail').value = user.email ?? '';
        document.getElementById('formPhone').value = user.phone_number ?? '';
        document.getElementById('formRole').value = user.role_id ?? '';
        document.getElementById('formDepartment').value = user.department_id ?? '';
        document.getElementById('formWarehouse').value = user.warehouse_id ?? '';
        document.getElementById('formManager').value = user.manager_id ?? '';
        document.getElementById('statusSwitch').checked = Boolean(user.is_active);

        // Saat mengubah data, password boleh dikosongkan agar tidak tertimpa.
        passwordField.value = '';
        passwordField.required = false;
        requiredMark.classList.add('d-none');
        editHint.classList.remove('d-none');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Pratinjau foto sebelum diunggah.
        const avatarInput = document.getElementById('avatarInput');
        if (avatarInput) {
            avatarInput.addEventListener('change', function () {
                const file = this.files[0];
                if (file) {
                    document.getElementById('previewAvatar').src = URL.createObjectURL(file);
                }
            });
        }

        // Konfirmasi sebelum menonaktifkan / mengaktifkan akun.
        document.querySelectorAll('.js-confirm-toggle').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                const name = this.dataset.name;
                const action = this.dataset.action;

                Swal.fire({
                    title: `Konfirmasi ${action} akun`,
                    html: `Anda akan <strong>${action}</strong> akun <strong>${name}</strong>.<br><small class="text-muted">Data historis karyawan tetap tersimpan.</small>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: `Ya, ${action}`,
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#0d6efd',
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            });
        });

        // Bila validasi server gagal, buka kembali modal supaya pengguna tidak
        // kehilangan konteks dan langsung melihat pesan kesalahannya.
        @if($errors->any())
            openUserModal('add');
            new bootstrap.Modal(document.getElementById('userModal')).show();
        @endif
    });
</script>
@endpush
