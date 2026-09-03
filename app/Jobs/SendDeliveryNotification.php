<?php

namespace App\Jobs;

use App\Models\DeliveryNote;
use App\Support\Messaging\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Mengirim tautan konfirmasi ke WhatsApp supir — PRD §6.5 F-OUT-04 #10.
 *
 * DIANTREKAN, BUKAN DIJALANKAN SAAT TOMBOL DITEKAN. Panggilan ke penyedia
 * pihak ketiga bisa lambat atau menggantung; menjalankannya di dalam
 * permintaan HTTP berarti Logistik menatap layar berputar sementara barang
 * sudah siap jalan — dan bila penyedianya sedang bermasalah, tombol Kirim
 * seolah rusak padahal pengirimannya sendiri sudah tercatat.
 *
 * KEGAGALAN TIDAK PERNAH MEMBATALKAN PENGIRIMAN. Status barang sudah berubah
 * sebelum job ini berjalan; yang ditulis di sini hanya status PESANNYA.
 * Itu keputusan pemilik produk: truk tidak menunggu penyedia WhatsApp.
 */
class SendDeliveryNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Tiga percobaan, berjarak semakin lama.
     *
     * Kegagalan penyedia WhatsApp lazimnya sementara. Tetapi percobaan tidak
     * boleh tak terbatas: nomor yang memang salah ketik akan gagal selamanya,
     * dan antrean yang terus mengulanginya menutupi kegagalan lain.
     */
    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $deliveryNoteId) {}

    public function handle(WhatsAppSender $sender): void
    {
        $note = DeliveryNote::with('customer:id,name')->find($this->deliveryNoteId);

        if ($note === null || blank($note->driver_phone) || $note->epod_token === null) {
            return;
        }

        // Sudah terkirim: jangan diulang. Job bisa dijalankan ulang oleh
        // antrean setelah gangguan, dan supir yang menerima pesan yang sama
        // tiga kali akan berhenti membacanya.
        if ($note->notify_status === DeliveryNote::NOTIFY_SENT) {
            return;
        }

        $hasil = $sender->send($note->driver_phone, $note->pesanUntukSupir());

        $note->forceFill([
            'notify_status' => $hasil->status,
            'notify_error' => $hasil->error,
            'notify_attempts' => $note->notify_attempts + 1,
            'notified_at' => $hasil->berhasil() ? now() : $note->notified_at,
        ])->save();

        if ($hasil->status === DeliveryNote::NOTIFY_FAILED) {
            // Dilempar supaya antrean mencoba lagi. Statusnya SUDAH tersimpan
            // lebih dulu, jadi walaupun seluruh percobaan habis, layar tetap
            // menampilkan kegagalan beserta alasannya — bukan diam.
            throw new \RuntimeException($hasil->error ?? 'Pengiriman WhatsApp gagal.');
        }
    }

    /**
     * Dipanggil setelah percobaan terakhir habis.
     *
     * Statusnya ditulis ulang di sini karena percobaan terakhir bisa saja
     * gagal sebelum sempat menyimpan (mis. proses antrean mati).
     */
    public function failed(?Throwable $e): void
    {
        DeliveryNote::where('id', $this->deliveryNoteId)
            ->where('notify_status', '<>', DeliveryNote::NOTIFY_SENT)
            ->update([
                'notify_status' => DeliveryNote::NOTIFY_FAILED,
                'notify_error' => $e?->getMessage() ?? 'Pengiriman WhatsApp gagal setelah beberapa percobaan.',
            ]);
    }
}
