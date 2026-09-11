<?php

namespace App\Http\Requests\Wms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Mengganti kata sandi sendiri — PRD §6.1.
 *
 * ATURANNYA SENGAJA SAMA dengan pembuatan user oleh Admin (StoreUserRequest):
 * minimal 8 karakter dan wajib memuat huruf DAN angka. Kalau jalur ini lebih
 * longgar, kebijakan sandi organisasi bisa dilewati hanya dengan mengganti
 * sandi sendiri sesudah dibuatkan Admin — pintu belakang yang tidak terlihat
 * karena keduanya berada di layar yang berbeda.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Setiap user berhak mengganti sandinya sendiri; tidak ada objek
        // milik orang lain yang disentuh di sini.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required', 'string', 'min:8', 'confirmed',
                'regex:/[A-Za-z]/', 'regex:/[0-9]/',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                /*
                 * SANDI LAMA DIPERIKSA DI SINI, bukan di controller. Kalau
                 * diperiksa belakangan, aturan panjang dan komposisi sandi
                 * baru sudah lolos lebih dulu — dan pesan galatnya jadi
                 * memberi tahu penyerang bahwa sandi barunya "sudah benar",
                 * hanya sandi lamanya yang salah.
                 */
                if ($v->errors()->has('current_password')) {
                    return;
                }

                if (! Hash::check((string) $this->input('current_password'), (string) $this->user()?->password)) {
                    $v->errors()->add('current_password', 'Kata sandi saat ini tidak cocok.');

                    return;
                }

                if ($this->input('current_password') === $this->input('new_password')) {
                    $v->errors()->add('new_password', 'Kata sandi baru harus berbeda dari yang sekarang.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'kata sandi saat ini',
            'new_password' => 'kata sandi baru',
        ];
    }

    public function messages(): array
    {
        return [
            'new_password.confirmed' => 'Ulangan kata sandi baru tidak sama.',
            'new_password.regex' => 'Kata sandi harus memuat kombinasi huruf dan angka.',
        ];
    }
}
