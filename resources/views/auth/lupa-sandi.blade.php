@extends('auth.kerangka')

@section('judul', 'Lupa Kata Sandi')
@section('sub', 'Permintaan Anda diteruskan ke admin')

@section('isi')
@if(session('terkirim'))
    {{-- SELALU PESAN YANG SAMA, terdaftar atau tidak emailnya. Menyebut
         "email tidak ditemukan" di sini membuat daftar email karyawan bisa
         dipetakan dari luar — celah yang sudah ditutup di halaman login. --}}
    <div class="alert alert-success border-0 small">
        <i class="bi bi-check-circle-fill me-1"></i>
        <strong>Permintaan terkirim.</strong> Kalau email itu terdaftar sebagai akun aktif,
        admin akan menghubungi Anda untuk memastikan permintaan ini memang dari Anda,
        lalu memberikan sandi sementara.
    </div>
    <p class="small text-muted mb-4">
        Sandi sementara hanya berlaku sekali. Saat masuk dengannya, Anda akan diminta
        membuat sandi sendiri.
    </p>
    <a href="{{ route('login') }}" class="btn btn-outline-secondary w-100">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke halaman masuk
    </a>
@else
    @if($errors->any())
        <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
    @endif

    <p class="small text-muted">
        Sistem ini tidak mengirim tautan reset lewat email. Isi alamat email akun Anda;
        admin gudang Anda akan menerima permintaannya dan menghubungi Anda.
    </p>

    <form method="POST" action="{{ route('password.lupa.kirim') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary" for="email">Email akun</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}"
                   class="form-control" placeholder="nama@berger.co.id" maxlength="150" required autofocus>
        </div>
        <button type="submit" class="btn btn-akun text-white w-100 fw-semibold">
            <i class="bi bi-send me-1"></i> Kirim permintaan ke admin
        </button>
    </form>

    <div class="text-center mt-3">
        <a href="{{ route('login') }}" class="small text-decoration-none">Kembali ke halaman masuk</a>
    </div>
@endif
@endsection
