@extends('layouts.wms')
@section('title', 'Pengaturan Akun')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Pengaturan Akun</h4>
        <p class="text-muted">Kelola informasi profil, keamanan, dan preferensi akun Anda.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center" role="alert">
    <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i>
    <div>
        <h6 class="fw-bold mb-0 text-success">Berhasil</h6>
        <span class="small">{{ session('success') }}</span>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="row g-4">
    <!-- Kolom Kiri: Info Profil Utama -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body text-center p-4">
                <div class="mb-3 position-relative d-inline-block">
                    <!-- Avatar Placeholder -->
                    <div class="rounded-circle text-white d-flex align-items-center justify-content-center mx-auto shadow-sm" style="width: 100px; height: 100px; font-size: 2.5rem; font-weight: bold; background-color: #1e3a8a;">
                        AD
                    </div>
                    <span class="position-absolute bottom-0 end-0 p-2 bg-success border border-light rounded-circle" title="Online" style="border-width: 3px !important;">
                        <span class="visually-hidden">Online</span>
                    </span>
                </div>
                <h5 class="fw-bold text-dark mb-1">Admin Logistik</h5>
                <p class="text-muted small mb-3">admin.logistik@bergerpaints.co.id</p>
                <div class="d-flex justify-content-center gap-2">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-2">Administrator</span>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-2">WH-01</span>
                </div>
            </div>
            <div class="card-footer bg-light border-top-0 rounded-bottom-4 p-3 text-center">
                <small class="text-muted">Terdaftar sejak: 12 Jan 2026</small>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Pengaturan Keamanan & Sesi -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <ul class="nav nav-pills nav-sm" id="profile-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill px-4" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button" role="tab" aria-selected="true"><i class="bi bi-shield-lock me-2"></i>Keamanan Akun</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-4" id="sessions-tab" data-bs-toggle="pill" data-bs-target="#sessions" type="button" role="tab" aria-selected="false"><i class="bi bi-laptop me-2"></i>Sesi Aktif</button>
                    </li>
                </ul>
            </div>
            
            <div class="card-body p-4">
                <div class="tab-content" id="profile-tabs-content">
                    
                    <!-- TAB 1: GANTI PASSWORD -->
                    <div class="tab-pane fade show active" id="security" role="tabpanel" tabindex="0">
                        <h6 class="fw-bold mb-3 text-dark">Ubah Kata Sandi</h6>
                        <form action="/wms/profile/password" method="POST">
                            @csrf
                            <div class="row mb-3">
                                <label class="col-sm-4 col-form-label text-muted small fw-semibold">Kata Sandi Saat Ini</label>
                                <div class="col-sm-8">
                                    <input type="password" class="form-control bg-light" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-4 col-form-label text-muted small fw-semibold">Kata Sandi Baru</label>
                                <div class="col-sm-8">
                                    <input type="password" class="form-control" name="new_password" required minlength="8">
                                    <div class="form-text small">Minimal 8 karakter, kombinasikan huruf dan angka.</div>
                                </div>
                            </div>
                            <div class="row mb-4">
                                <label class="col-sm-4 col-form-label text-muted small fw-semibold">Ulangi Kata Sandi Baru</label>
                                <div class="col-sm-8">
                                    <input type="password" class="form-control" name="new_password_confirmation" required minlength="8">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-sm-8 offset-sm-4">
                                    <button type="submit" class="btn text-white rounded-pill px-4 shadow-sm" style="background-color: #1e3a8a;">Simpan Perubahan</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- TAB 2: SESI AKTIF -->
                    <div class="tab-pane fade" id="sessions" role="tabpanel" tabindex="0">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h6 class="fw-bold mb-1 text-dark">Daftar Perangkat Login</h6>
                                <small class="text-muted">Kelola sesi aktif di berbagai perangkat.</small>
                            </div>
                            <button class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="alert('Sesi lain berhasil ditutup.')"><i class="bi bi-box-arrow-right me-1"></i>Logout Perangkat Lain</button>
                        </div>
                        
                        <div class="list-group list-group-flush border-top border-bottom">
                            <!-- Sesi Saat Ini -->
                            <div class="list-group-item py-3 px-0 border-0 border-bottom d-flex align-items-center">
                                <div class="bg-success-subtle text-success p-2 rounded-3 me-3">
                                    <i class="bi bi-laptop fs-4"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-0 fw-semibold text-dark">Windows - Chrome Browser</h6>
                                    <small class="text-muted d-block">IP: 192.168.1.45 &bull; Jakarta, ID</small>
                                </div>
                                <div>
                                    <span class="badge bg-success rounded-pill px-3 py-2">Sesi Ini (Aktif)</span>
                                </div>
                            </div>
                            
                            <!-- Sesi Lama -->
                            <div class="list-group-item py-3 px-0 border-0 d-flex align-items-center">
                                <div class="bg-light text-secondary p-2 rounded-3 me-3">
                                    <i class="bi bi-phone fs-4"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-0 fw-semibold text-dark">Android - Berger WMS Mobile</h6>
                                    <small class="text-muted d-block">IP: 114.120.x.x &bull; 2 jam yang lalu</small>
                                </div>
                                <div>
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="this.closest('.list-group-item').remove(); alert('Akses dicabut.')">Cabut Akses</button>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
<style>
    .nav-pills .nav-link { color: #6c757d; font-weight: 500; transition: all 0.2s; }
    .nav-pills .nav-link:hover { color: #1e3a8a; background-color: #f8fafc; }
    .nav-pills .nav-link.active { background-color: #1e3a8a !important; color: #ffffff !important; }
</style>
@endpush