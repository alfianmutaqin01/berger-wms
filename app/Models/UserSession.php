<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesi aktif seorang user pada satu device/browser.
 *
 * PRD §6.1 F-AUTH-04: maksimal 2 sesi aktif bersamaan per user, dan idle
 * timeout 1 jam. Baris di tabel ini ADALAH sumber kebenaran untuk kedua
 * aturan tersebut — session Laravel sendiri (tabel `sessions`) tidak
 * cukup karena tidak membedakan device satu dengan device lain per user.
 */
class UserSession extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'last_activity_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
