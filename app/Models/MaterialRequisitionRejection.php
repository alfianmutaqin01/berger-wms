<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu penolakan yang pernah dialami sebuah MRF.
 *
 * TIDAK PERNAH DIBERSIHKAN, berbeda dengan kolom penolakan di
 * `material_requisitions` yang hanya menyimpan keadaan sekarang. Permintaan
 * yang ditolak boleh diperbaiki dan diajukan lagi dengan nomor yang sama;
 * begitu itu terjadi kolom di permintaannya dikosongkan supaya keadaan
 * sekarangnya jujur, dan tanpa tabel ini fakta bahwa permintaan itu pernah
 * ditolak akan lenyap.
 *
 * Aturannya sama dengan SalesOrderRejection, dan memang disengaja: kedua alur
 * menjawab pertanyaan yang sama pada pembacanya.
 */
class MaterialRequisitionRejection extends Model
{
    use HasFactory;

    /** Ditolak atasan lewat tautan WhatsApp. */
    public const STAGE_APPROVER = 'approver';

    /** Ditolak Logistik di layar WMS. */
    public const STAGE_LOGISTICS = 'logistics';

    public const STAGE_LABELS = [
        self::STAGE_APPROVER => 'Atasan Produksi',
        self::STAGE_LOGISTICS => 'Logistik',
    ];

    protected $fillable = [
        'material_requisition_id', 'stage', 'reason', 'attempt_no',
        'rejected_by_name', 'rejected_by', 'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'rejected_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisition::class, 'material_requisition_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function getStageLabelAttribute(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? $this->stage;
    }
}
