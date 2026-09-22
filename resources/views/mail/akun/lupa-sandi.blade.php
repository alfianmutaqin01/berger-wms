{{-- Markdown: baris JANGAN diberi indentasi — empat spasi di depan dibaca sebagai blok kode. --}}
<x-mail::message>
# Permintaan reset sandi

**{{ $nama }}** ({{ $peran }}, {{ $email }}) menekan **"Lupa sandi?"** di halaman login pada {{ $waktu }}.

<x-mail::panel>
**Pastikan dulu permintaan ini memang dari orangnya** — hubungi langsung lewat telepon atau temui di tempat. Halaman lupa sandi bisa diisi siapa pun yang tahu alamat email seseorang.
</x-mail::panel>

Setelah dipastikan, isi sandi sementara di Manajemen Pengguna. Sandi itu hanya berlaku sekali: saat login berikutnya, pemiliknya wajib membuat sandinya sendiri.

<x-mail::button :url="$tautan">
Buka Manajemen Pengguna
</x-mail::button>

Email ini dikirim otomatis oleh Berger WMS.
</x-mail::message>
