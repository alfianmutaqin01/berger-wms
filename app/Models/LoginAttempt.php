<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat percobaan login (berhasil maupun gagal), untuk analisis keamanan.
 *
 * PRD §6.1 F-AUTH-03. Tidak berelasi ke `users` lewat FK — email dicatat apa
 * adanya supaya percobaan dengan email yang tidak terdaftar pun tetap terekam.
 */
class LoginAttempt extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'email',
        'ip_address',
        'user_agent',
        'is_successful',
        'failure_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_successful' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
