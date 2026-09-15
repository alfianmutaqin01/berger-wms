<?php

namespace App\Mail\Pesanan;

use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Mail\Mailables\Content;

/**
 * Pesanan diterima Logistik — beserta qty yang benar-benar diterima.
 *
 * "Dipesan 10, diterima 8" adalah isi utamanya, bukan catatan kaki: itulah
 * yang harus Sales sampaikan ke customer sebelum customer menghitung sendiri
 * di depan truk.
 *
 * DITERIMA BELUM TENTU TERSEDIA. Logistik boleh menerima melebihi stok yang
 * tercatat; porsi itu menunggu stok. Angkanya dibekukan saat penerimaan
 * (lihat SalesOrderEmail::data) karena sesudahnya ia bergerak mengikuti
 * picking dan stok masuk.
 */
class PesananDiterima extends EmailPesanan
{
    /**
     * @param  array{dicadangkan?: int, menunggu_stok?: int}  $saatDiterima
     */
    public function __construct(SalesOrder $order, public readonly array $saatDiterima = [])
    {
        parent::__construct($order);
    }

    protected function kejadian(): string
    {
        return 'Pesanan diterima';
    }

    public function content(): Content
    {
        $this->order->loadMissing(['details.product:id,sku,name,uom', 'approvedBy:id,full_name']);

        $baris = $this->order->details->map(fn (SalesOrderDetail $d) => [
            'sku' => self::sel($d->product?->sku),
            'nama' => self::sel($d->product?->name),
            'uom' => self::sel($d->product?->uom),
            'dipesan' => (int) $d->qty_ordered,
            'diterima' => (int) $d->qty_approved,
            'selisih' => max(0, (int) $d->qty_ordered - (int) $d->qty_approved),
        ])->values()->all();

        $dipesan = array_sum(array_column($baris, 'dipesan'));
        $diterima = array_sum(array_column($baris, 'diterima'));

        return new Content(markdown: 'mail.pesanan.diterima', with: array_merge($this->dataUmum(), [
            'baris' => $baris,
            'totalDipesan' => $dipesan,
            'totalDiterima' => $diterima,
            'totalSelisih' => array_sum(array_column($baris, 'selisih')),
            'menungguStok' => (int) ($this->saatDiterima['menunggu_stok'] ?? 0),
            'catatan' => $this->order->approval_note,
            'diterimaPada' => self::jam($this->order->approved_at),
            'diterimaOleh' => $this->order->approvedBy?->full_name ?? 'Logistik',
        ]));
    }
}
