<?php

namespace App\Mail\Pesanan;

use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Mail\Mailables\Content;

/**
 * Pesanan ditolak Logistik, beserta alasannya.
 *
 * ALASANNYA DIBEKUKAN saat penolakan. Pesanan boleh diperbaiki dan diajukan
 * ulang, dan kolom alasan di pesanannya dikosongkan saat itu; email yang
 * masih di antrean harus tetap menyebut alasan penolakan yang dikabarkannya.
 */
class PesananDitolak extends EmailPesanan
{
    /**
     * @param  array{alasan?: string}  $saatDitolak
     */
    public function __construct(SalesOrder $order, public readonly array $saatDitolak = [])
    {
        parent::__construct($order);
    }

    protected function kejadian(): string
    {
        return 'Pesanan ditolak';
    }

    public function content(): Content
    {
        $this->order->loadMissing(['details.product:id,sku,name,uom', 'rejectedBy:id,full_name']);

        return new Content(markdown: 'mail.pesanan.ditolak', with: array_merge($this->dataUmum(), [
            'alasan' => $this->saatDitolak['alasan'] ?? $this->order->rejection_reason ?? '—',
            'ditolakPada' => self::jam($this->order->rejected_at),
            'ditolakOleh' => $this->order->rejectedBy?->full_name ?? 'Logistik',
            'baris' => $this->order->details->map(fn (SalesOrderDetail $d) => [
                'sku' => self::sel($d->product?->sku),
                'nama' => self::sel($d->product?->name),
                'uom' => self::sel($d->product?->uom),
                'dipesan' => (int) $d->qty_ordered,
            ])->values()->all(),
        ]));
    }
}
