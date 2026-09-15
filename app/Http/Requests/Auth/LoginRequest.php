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
            // (PRD §6.1 F-AUTH-02) ditolak di AuthController::login() dengan
            // pesan yang sama seperti token yang ditolak Google.
            'g-recaptcha-response' => ['nullable', 'string'],
        ];
    }
}
