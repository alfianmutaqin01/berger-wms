<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Satu penolakan yang pernah dialami sebuah pesanan.
 *
 * TIDAK PERNAH DIBERSIHKAN, berbeda dengan kolom penolakan di `sales_orders`
 * yang hanya menyimpan keadaan sekarang. Pesanan yang ditolak boleh diperbaiki
 * dan diajukan lagi; begitu itu terjadi kolom di pesanannya dikosongkan supaya
 * keadaan sekarangnya jujur, dan tanpa tabel ini fakta bahwa pesanan itu
 * pernah ditolak akan lenyap — padahal permintaan pemilik produk justru
 * sebaliknya: catatan itu melekat sampai akhir.
 */
class SalesOrderRejection extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id',
        'reason',
        'attempt_no',
        'submitted_at',
        'rejected_at',
        'rejected_by',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'submitted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * Memasang pagar append-only.
     *
     * Pola yang sama dengan StockMovement dan SalesOrderOutstanding. Menolak
     * lewat exception, bukan `return false`, supaya percobaan menghapus jejak
     * penolakan tidak bisa gagal diam-diam dan luput dari perhatian.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'sales_order_rejections bersifat append-only: riwayat penolakan tidak boleh diubah.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'sales_order_rejections bersifat append-only: riwayat penolakan tidak boleh dihapus.'
            );
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
