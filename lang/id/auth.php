<?php

/*
| Pesan autentikasi bawaan Laravel.
|
| Dipakai saat gagal login lewat Auth::attempt(). AuthController punya
| pesannya sendiri untuk akun terkunci dan perangkat asing; yang di sini
| hanya jaring terakhir.
*/

return [
    'failed' => 'Email atau kata sandi salah.',
    'password' => 'Kata sandi salah.',
    'throttle' => 'Terlalu banyak percobaan masuk. Coba lagi dalam :seconds detik.',
];
