<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch mana persisnya yang ditahan sebuah booking.
 *
 * Kembaran SalesOrderAllocation, dan ada karena alasan yang sama: melepas
 * booking harus mengembalikan qty ke baris stok YANG BENAR. Tanpa catatan
 * ini, pembatalan hanya tahu "kembalikan 5" tanpa tahu ke batch mana —
 * dan menaruhnya di batch sembarang yang kebetulan punya sisa akan merusak
 * urutan FIFO sekaligus membuat umur simpan tercatat keliru.
 */
class StockBookingAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['stock_booking_id', 'inventory_stock_id', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(StockBooking::class, 'stock_booking_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }
}
