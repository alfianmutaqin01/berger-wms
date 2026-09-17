<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Tautan permintaan material untuk divisi yang tidak punya akun WMS.
 *
 * QC dan R&D meminta material beberapa kali setahun. Membuatkan mereka akun
 * berarti satu peran baru dengan matriks izinnya sendiri, dasbor yang dibuka
 * dua kali setahun, dan kata sandi yang pasti lupa. Sistem ini sudah punya
 * pola untuk orang tanpa akun — atasan yang menyetujui MRF dan supir yang
 * mengisi ePOD sama-sama bekerja lewat tautan bertoken — dan tautan ini
 * berjalan di rel yang sama.
 *
 * TAUTANNYA BERLAKU SELAMANYA sampai dinonaktifkan, jadi ia diperlakukan
 * seperti kunci: diberikan sekali ke kepala divisinya, bukan disebar. Yang
 * menahannya kalau bocor bukan kerahasiaan tautannya, melainkan dua pintu
 * persetujuan yang tetap harus dilewati — atasan divisi lewat WhatsApp, lalu
 * Logistik yang memastikan barangnya ada. Keduanya bukan pengisi formulir.
 */
class MrfRequestLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id', 'department_id', 'token',
        'approver_name', 'approver_phone', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /* ------------------------------------------------------------ Relasi */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(MaterialRequisition::class, 'request_link_id');
    }

    /* ------------------------------------------------------------- Scope */

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* ------------------------------------------------------------ Aturan */

    /**
     * Token acak 64 karakter.
     *
     * BUKAN disusun dari id: tautan yang bisa ditebak dari nomor urut membuat
     * siapa pun mengajukan permintaan atas nama divisi mana pun. Aturan yang
     * sama dengan tautan persetujuan MRF dan ePOD.
     */
    public static function tokenBaru(): string
    {
        return Str::random(64);
    }

    public function url(): string
    {
        return url('/mrf/minta/'.$this->token);
    }

    /**
     * Atasannya sudah ditetapkan Manager, sehingga pengisi tidak bisa
     * menyebutkan atasannya sendiri.
     *
     * Inilah yang membuat tautan publik aman dipakai: tanpa penguncian ini,
     * siapa pun yang memegang tautannya bisa mengetik nomornya sendiri,
     * menerima tautan persetujuannya, lalu menyetujui permintaannya sendiri.
     */
    public function atasanTerkunci(): bool
    {
        return filled($this->approver_name) && filled($this->approver_phone);
    }

    public function getLabelAttribute(): string
    {
        return ($this->department?->name ?? 'Divisi').' · '.($this->warehouse?->kode_pendek ?? '—');
    }
}
