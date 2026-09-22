@extends('auth.kerangka')

@section('judul', 'Buat Kata Sandi Baru')
@section('sub', 'Sandi Anda saat ini adalah sandi sementara dari admin')

@section('isi')
{{-- Menyimpan lewat profile.password, pintu yang sama dengan ganti sandi
     sukarela — aturan sandinya tidak mungkin berbeda di antara keduanya. --}}
<div class="alert alert-warning border-0 small">
    <i class="bi bi-shield-lock-fill me-1"></i>
    Halo <strong>{{ $user->full_name }}</strong>. Sandi yang Anda pakai untuk masuk diisi oleh admin,
    jadi admin mengetahuinya. Buat sandi Anda sendiri sebelum memakai sistem.
</div>

@if($errors->any())
    <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('profile.password') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label small fw-semibold text-secondary" for="current_password">Sandi sementara dari admin</label>
        <input type="password" name="current_password" id="current_password" class="form-control"
               autocomplete="current-password" required autofocus>
    </div>
    <div class="mb-3">
        <label class="form-label small fw-semibold text-secondary" for="new_password">Sandi baru</label>
        <input type="password" name="new_password" id="new_password" class="form-control"
               autocomplete="new-password" minlength="8" required>
        <div class="form-text">Minimal 8 karakter, memuat huruf dan angka.</div>
    </div>
    <div class="mb-4">
        <label class="form-label small fw-semibold text-secondary" for="new_password_confirmation">Ulangi sandi baru</label>
        <input type="password" name="new_password_confirmation" id="new_password_confirmation" class="form-control"
               autocomplete="new-password" minlength="8" required>
    </div>
    <button type="submit" class="btn btn-akun text-white w-100 fw-semibold">
        <i class="bi bi-check2-circle me-1"></i> Simpan dan lanjutkan
    </button>
</form>

{{-- Jalan keluar wajib ada: orang yang tidak ingat sandi sementaranya tidak
     boleh terperangkap di satu halaman tanpa jalan kembali. --}}
<form method="POST" action="{{ route('logout') }}" class="text-center mt-3">
    @csrf
    <button type="submit" class="btn btn-link small text-decoration-none text-muted">Keluar</button>
</form>
@endsection
