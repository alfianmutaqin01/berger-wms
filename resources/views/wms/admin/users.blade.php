@extends('layouts.wms')

@section('title', 'Manajemen User')
@section('page_title', 'Manajemen User')

@section('content')
<div class="table-card card">
    <div class="card-header bg-white pt-4 pb-3 border-bottom-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">Daftar Pengguna Sistem</h6>
        <div>
            <button class="btn btn-sm btn-primary fw-semibold shadow-sm"><i class="bi bi-person-plus me-1"></i> Tambah User</button>
        </div>
    </div>
    <div class="card-body p-0 border-top">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Nama Lengkap</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Terakhir Login</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-4 fw-bold text-dark"><div class="user-avatar d-inline-flex me-2 bg-warning" style="width: 28px; height: 28px; font-size: 0.6rem;">AD</div> Admin Gudang</td>
                        <td>admin@bergerpaints.co.id</td>
                        <td><span class="badge bg-primary">Warehouse Admin</span></td>
                        <td class="text-muted small fw-semibold">Hari ini, 08:30</td>
                        <td><span class="badge bg-success">Aktif</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-dark"><div class="user-avatar d-inline-flex me-2 bg-secondary" style="width: 28px; height: 28px; font-size: 0.6rem;">SR</div> Budi Santoso</td>
                        <td>budi.sales@bergerpaints.co.id</td>
                        <td><span class="badge bg-secondary">Sales Rep</span></td>
                        <td class="text-muted small fw-semibold">Kemarin, 14:15</td>
                        <td><span class="badge bg-success">Aktif</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
