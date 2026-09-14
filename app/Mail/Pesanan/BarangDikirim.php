<?php

namespace App\Mail\Pesanan;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Mail\Mailables\Content;

/**
 * Satu Surat Jalan berangkat.
 *
 * PER SURAT JALAN, BUKAN PER PESANAN. Satu pesanan bisa berangkat beberapa
 * kali (kirim sebagian lalu susulan), dan setiap keberangkatan adalah kabar
 * sendiri bagi Sales: apa yang ada di truk ini, dan apa yang belum.
 */
class BarangDikirim extends EmailPesanan
{
    public function __construct(SalesOrder $order, public readonly DeliveryNote $note)
    {
        parent::__construct($order);
    }

    protected function kejadian(): string
    {
        return 'Barang dikirim (Surat Jalan '.$this->note->document_no.')';
    }

    public function content(): Content
    {
        $this->note->loadMissing('lines.product:id,sku,name,uom');
        $this->order->loadMissing('details.product:id,sku,name,uom');

        return new Content(markdown: 'mail.pesanan.dikirim', with: array_merge($this->dataUmum(), [
            'suratJalan' => $this->note->document_no,
            'berangkat' => self::jam($this->note->shipped_at),
            // Nomor supir ikut disebut: Sales yang ditanya customer "truknya
            // di mana" tidak perlu menelepon gudang dulu.
            'supir' => ($this->note->driver_name ?: '—')
                .($this->note->driver_phone ? ' ('.$this->note->driver_phone.')' : ''),
            'plat' => $this->note->vehicle_plate ?: '—',
            'muatan' => $this->note->lines->map(fn (DeliveryNoteLine $l) => [
                'sku' => self::sel($l->product?->sku ?? $l->sku),
                'nama' => self::sel($l->product?->name),
                'uom' => self::sel($l->product?->uom),
                'qty' => (int) $l->qty,
            ])->values()->all(),
            // Kekurangan terhadap qty DIPESAN, sama dengan outstanding_qty
            // yang dihitung ulang tiap keberangkatan (Shipment::catatQtyTerkirim).
            'sisa' => $this->order->details
                ->filter(fn (SalesOrderDetail $d) => (int) $d->outstanding_qty > 0)
                ->map(fn (SalesOrderDetail $d) => [
                    'sku' => self::sel($d->product?->sku),
                    'nama' => self::sel($d->product?->name),
                    'uom' => self::sel($d->product?->uom),
                    'dipesan' => (int) $d->qty_ordered,
                    'terkirim' => (int) $d->qty_shipped,
                    'belum' => (int) $d->outstanding_qty,
                ])->values()->all(),
        ]));
    }
}
