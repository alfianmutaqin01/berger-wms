<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Sengaja 'nullable', bukan 'required': token kosong/tidak dicentang
            // (PRD §6.1 F-AUTH-02) harus jatuh ke pesan generik + counter lockout
            // yang sama dengan kredensial salah, bukan error validasi terpisah.
            'g-recaptcha-response' => ['nullable', 'string'],
        ];
    }
}
