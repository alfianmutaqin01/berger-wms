<?php

namespace App\Mail\Pesanan;

use App\Models\SalesOrder;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Induk seluruh email kabar pesanan untuk Sales.
 *
 * TIDAK ShouldQueue. Yang diantrekan adalah App\Jobs\SendSalesOrderEmail,
 * yang juga mencatat hasilnya; email yang diantrekan lagi di dalamnya akan
 * tercatat "terkirim" padahal baru masuk antrean kedua.
 *
 * Pengirim dan Reply-To diambil dari config/mail.php, bukan ditulis di sini:
 * berpindah dari Gmail ke alamat domain perusahaan cukup mengganti .env.
 */
abstract class EmailPesanan extends Mailable
{
    public function __construct(public readonly SalesOrder $order) {}

    /** Kejadian yang dikabarkan, untuk subjek — mis. "Pesanan diterima". */
    abstract protected function kejadian(): string;

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf(
            '[Berger WMS] %s — %s (%s)',
            $this->kejadian(),
            $this->nomorPesanan(),
            $this->order->customer?->name ?? 'customer',
        ));
    }

    /**
     * Nomor SO BC bila sudah ada — itulah yang dipakai Sales saat berbicara
     * dengan customer dan bagian penagihan. Nomor internal hanya sebelum ada.
     */
    protected function nomorPesanan(): string
    {
        return $this->order->bc_so_number ?: $this->order->display_number;
    }

    protected function urlPesanan(): string
    {
        return url('/sales/orders/'.$this->order->id);
    }

    /** Waktu dalam zona aplikasi; server menyimpan UTC. */
    protected static function jam(?CarbonInterface $waktu): string
    {
        return $waktu?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—';
    }

    /**
     * Isi satu sel tabel markdown.
     *
     * Garis tegak memecah kolom dan ganti baris memutus tabel — nama produk
     * dari ERP memuat keduanya sesekali, dan tabel yang pecah membuat angka
     * qty tampil di kolom yang salah tanpa ada yang menyadarinya.
     */
    protected static function sel(?string $teks): string
    {
        $bersih = trim(preg_replace('/\s*[\r\n|]+\s*/', ' / ', (string) $teks));

        return $bersih === '' ? '—' : $bersih;
    }

    /** @return array<string, mixed> */
    protected function dataUmum(): array
    {
        return [
            'namaSales' => $this->order->user?->full_name ?? 'Sales',
            'nomorPesanan' => $this->nomorPesanan(),
            'nomorInternal' => $this->order->order_number,
            'customer' => $this->order->customer?->name ?? '—',
            'urlPesanan' => $this->urlPesanan(),
        ];
    }
}
