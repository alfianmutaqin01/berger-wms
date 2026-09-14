<?php

namespace App\Mail\Pesanan;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use Illuminate\Mail\Mailables\Content;

/**
 * Ringkasan pesanan yang sudah complete.
 *
 * Satu email yang menjawab seluruh riwayat pesanan tanpa membuka sistem:
 * kapan tiap tahap terjadi, berapa yang dipesan/diterima/terkirim, dan Surat
 * Jalan mana saja. Inilah yang dicari Sales saat customer menanyakan pesanan
 * bulan lalu.
 */
class PesananSelesai extends EmailPesanan
{
    protected function kejadian(): string
    {
        return 'Pesanan selesai';
    }

    public function content(): Content
    {
        $this->order->loadMissing([
            'details.product:id,sku,name,uom',
            'paymentTerm',
            'deliveryNotes' => fn ($q) => $q->whereIn('status', [DeliveryNote::STATUS_SHIPPED, DeliveryNote::STATUS_DELIVERED])
                ->orderBy('shipped_at'),
        ]);

        $baris = $this->order->details->map(fn (SalesOrderDetail $d) => [
            'sku' => self::sel($d->product?->sku),
            'nama' => self::sel($d->product?->name),
            'uom' => self::sel($d->product?->uom),
            'dipesan' => (int) $d->qty_ordered,
            'diterima' => (int) $d->qty_approved,
            'terkirim' => (int) $d->qty_shipped,
            'kurang' => (int) $d->outstanding_qty,
        ])->values()->all();

        $suratJalan = $this->order->deliveryNotes;

        return new Content(markdown: 'mail.pesanan.selesai', with: array_merge($this->dataUmum(), [
            'status' => SalesOrder::STATUS_LABELS[$this->order->status] ?? $this->order->status,
            'menungguBayar' => $this->order->status === SalesOrder::STATUS_COMPLETED_BILLING,
            'termin' => $this->order->paymentTerm?->name,
            'linimasa' => [
                ['Diajukan', self::jam($this->order->submitted_at)],
                ['Diterima Logistik', self::jam($this->order->approved_at)],
                ['Berangkat pertama', self::jam($suratJalan->first()?->shipped_at ?? $this->order->shipped_at)],
                ['Sampai terakhir', self::jam($suratJalan->max('delivered_at') ?? $this->order->delivered_at)],
                ['Complete', self::jam($this->order->completed_at)],
            ],
            'slaJam' => $this->order->sla_hours !== null ? (float) $this->order->sla_hours : null,
            'baris' => $baris,
            'total' => [
                'dipesan' => array_sum(array_column($baris, 'dipesan')),
                'diterima' => array_sum(array_column($baris, 'diterima')),
                'terkirim' => array_sum(array_column($baris, 'terkirim')),
                'kurang' => array_sum(array_column($baris, 'kurang')),
            ],
            'suratJalan' => $suratJalan->map(fn (DeliveryNote $n) => [
                'nomor' => self::sel($n->document_no),
                'berangkat' => self::jam($n->shipped_at),
                'sampai' => self::jam($n->delivered_at),
                'penerima' => self::sel($n->received_by_name),
            ])->values()->all(),
        ]));
    }
}
