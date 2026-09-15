<?php

namespace App\Jobs;

use App\Mail\Pesanan\BarangDikirim;
use App\Mail\Pesanan\BarangSampai;
use App\Mail\Pesanan\EmailPesanan;
use App\Mail\Pesanan\PesananDiterima;
use App\Mail\Pesanan\PesananDitolak;
use App\Mail\Pesanan\PesananSelesai;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Mengirim satu email kabar pesanan ke Sales pemilik pesanan.
 *
 * KEMBARAN SendArrivalNoticeToSales, dan kemiripannya disengaja: status
 * disimpan lebih dulu baru galat dilempar, pesan yang sudah terkirim tidak
 * diulang, dan data yang salah (alamat kosong) tidak dicoba ulang karena
 * mengulang tidak akan membuat alamatnya muncul.
 *
 * ISI EMAIL DIBACA SAAT DIKIRIM, dengan pemeriksaan ulang apakah kabarnya
 * masih benar. Yang dibekukan saat kejadian hanya angka yang memang bergerak
 * sesudahnya (kolom `data`).
 */
class SendSalesOrderEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Gangguan SMTP lazimnya sementara; batas harian Gmail baru pulih berjam-jam kemudian. */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $emailId) {}

    public function handle(): void
    {
        $email = SalesOrderEmail::with([
            'salesOrder.user:id,full_name,email,is_active',
            'salesOrder.customer:id,code,name',
            'deliveryNote',
        ])->find($this->emailId);

        if ($email === null || $email->status === SalesOrderEmail::STATUS_SENT) {
            return;
        }

        $order = $email->salesOrder;

        if ($order === null) {
            $this->tutup($email, SalesOrderEmail::STATUS_SKIPPED, 'Pesanannya sudah tidak ada.');

            return;
        }

        $alasanBasi = $this->alasanTidakBerlaku($email, $order, $email->deliveryNote);

        if ($alasanBasi !== null) {
            $this->tutup($email, SalesOrderEmail::STATUS_SKIPPED, $alasanBasi);

            return;
        }

        /*
         * HANYA SALES PEMILIK PESANAN — keputusan pemilik produk. Pesanan
         * yang dibuatkan Admin atas nama Budi tetap dikabarkan ke Budi saja.
         */
        $sales = $order->user;

        if ($sales === null) {
            $this->tutup($email, SalesOrderEmail::STATUS_FAILED,
                'Pesanan ini tidak terhubung ke akun Sales mana pun, jadi tidak ada yang bisa dikabari.');

            return;
        }

        if (! $sales->is_active) {
            $this->tutup($email, SalesOrderEmail::STATUS_SKIPPED,
                sprintf('Akun Sales %s sudah nonaktif.', $sales->full_name), $sales->id);

            return;
        }

        /*
         * ALAMAT YANG TIDAK TERBACA TIDAK DICOBA ULANG. Selain sia-sia, email
         * yang terus mental membuat Google membatasi akun pengirimnya — dan
         * yang ikut berhenti adalah email untuk seluruh Sales lain.
         */
        if (filter_var((string) $sales->email, FILTER_VALIDATE_EMAIL) === false) {
            $this->tutup($email, SalesOrderEmail::STATUS_FAILED, sprintf(
                'Akun Sales %s belum punya alamat email yang valid. Isi di Manajemen Pengguna.',
                $sales->full_name,
            ), $sales->id);

            return;
        }

        try {
            Mail::to($sales->email, $sales->full_name)->send($this->susun($email, $order));
        } catch (Throwable $e) {
            $email->forceFill([
                'status' => SalesOrderEmail::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'attempts' => $email->attempts + 1,
                'recipient_user_id' => $sales->id,
                'recipient_email' => $sales->email,
            ])->save();

            // Dilempar supaya antrean mencoba lagi; statusnya SUDAH tersimpan.
            throw $e;
        }

        $email->forceFill([
            'status' => SalesOrderEmail::STATUS_SENT,
            'error' => null,
            'attempts' => $email->attempts + 1,
            'recipient_user_id' => $sales->id,
            'recipient_email' => $sales->email,
            'sent_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        SalesOrderEmail::where('id', $this->emailId)
            ->where('status', '<>', SalesOrderEmail::STATUS_SENT)
            ->update([
                'status' => SalesOrderEmail::STATUS_FAILED,
                'error' => mb_substr($e?->getMessage() ?? 'Pengiriman email gagal setelah beberapa percobaan.', 0, 1000),
            ]);
    }

    /**
     * Kabar yang sudah tidak benar saat antrean sampai padanya.
     *
     * Antrean bisa tertunda berjam-jam (worker mati, batas Gmail). Email
     * "pesanan diterima" untuk pesanan yang sementara itu dibatalkan akan
     * membuat Sales mengabari customer sesuatu yang sudah tidak berlaku.
     */
    private function alasanTidakBerlaku(SalesOrderEmail $email, SalesOrder $order, ?DeliveryNote $note): ?string
    {
        return match ($email->type) {
            SalesOrderEmail::TYPE_APPROVED => $order->approved_at === null
                || $order->cancelled_at !== null
                || in_array($order->status, [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_PENDING, SalesOrder::STATUS_REJECTED], true)
                    ? 'Pesanan sudah tidak berstatus diterima saat email akan dikirim.'
                    : null,

            SalesOrderEmail::TYPE_REJECTED => $order->status !== SalesOrder::STATUS_REJECTED
                ? 'Pesanan sudah diajukan ulang atau diputus lain saat email akan dikirim.'
                : null,

            SalesOrderEmail::TYPE_SHIPPED => $note === null
                || $note->sales_order_id !== $order->id
                || ! in_array($note->status, [DeliveryNote::STATUS_SHIPPED, DeliveryNote::STATUS_DELIVERED], true)
                    ? 'Surat Jalan sudah tidak berstatus berangkat saat email akan dikirim.'
                    : null,

            SalesOrderEmail::TYPE_DELIVERED => $note === null
                || $note->sales_order_id !== $order->id
                || $note->status !== DeliveryNote::STATUS_DELIVERED
                    ? 'Surat Jalan sudah tidak berstatus sampai saat email akan dikirim.'
                    : null,

            SalesOrderEmail::TYPE_COMPLETED => ! in_array($order->status, [SalesOrder::STATUS_COMPLETED, SalesOrder::STATUS_COMPLETED_BILLING], true)
                ? 'Pesanan sudah tidak berstatus selesai saat email akan dikirim.'
                : null,

            default => 'Jenis email tidak dikenal: '.$email->type,
        };
    }

    private function susun(SalesOrderEmail $email, SalesOrder $order): EmailPesanan
    {
        return match ($email->type) {
            SalesOrderEmail::TYPE_APPROVED => new PesananDiterima($order, $email->data ?? []),
            SalesOrderEmail::TYPE_REJECTED => new PesananDitolak($order, $email->data ?? []),
            SalesOrderEmail::TYPE_SHIPPED => new BarangDikirim($order, $email->deliveryNote),
            SalesOrderEmail::TYPE_DELIVERED => new BarangSampai($order, $email->deliveryNote),
            SalesOrderEmail::TYPE_COMPLETED => new PesananSelesai($order),
        };
    }

    private function tutup(SalesOrderEmail $email, string $status, string $alasan, ?int $userId = null): void
    {
        $email->forceFill([
            'status' => $status,
            'error' => $alasan,
            'recipient_user_id' => $userId ?? $email->recipient_user_id,
        ])->save();
    }
}
