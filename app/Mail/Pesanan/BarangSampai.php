<?php

namespace App\Mail\Pesanan;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use Illuminate\Mail\Mailables\Content;

/**
 * Supir mengonfirmasi satu Surat Jalan sampai.
 *
 * Cadangan untuk WhatsApp dan lonceng yang dikirim pada detik yang sama —
 * isinya sengaja sejalan dengan DeliveryNote::pesanUntukSales(), termasuk
 * langkah berikutnya: foto Surat Jalan bertanda tangan.
 */
class BarangSampai extends EmailPesanan
{
    public function __construct(SalesOrder $order, public readonly DeliveryNote $note)
    {
        parent::__construct($order);
    }

    protected function kejadian(): string
    {
        return 'Barang sampai (Surat Jalan '.$this->note->document_no.')';
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.pesanan.sampai', with: array_merge($this->dataUmum(), [
            'suratJalan' => $this->note->document_no,
            'sampai' => self::jam($this->note->delivered_at),
            'penerima' => filled($this->note->received_by_name) ? $this->note->received_by_name : null,
            'supir' => $this->note->driver_name ?: '—',
            'plat' => $this->note->vehicle_plate ?: '—',
        ]));
    }
}
