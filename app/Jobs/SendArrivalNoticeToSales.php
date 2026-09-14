<?php

namespace App\Jobs;

use App\Models\DeliveryNote;
use App\Support\Messaging\WhatsAppSender;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Mengabari Sales lewat WhatsApp bahwa barang pesanannya sudah sampai.
 *
 * DIPICU SUPIR, bukan orang di kantor. Karena itu dua sifat yang berbeda dari
 * pesan untuk supir:
 *
 *   1. Tidak ada tombol "Buka WhatsApp" yang bisa ditekan siapa pun — pada
 *      mode manual pesannya tercatat `manual` dan tidak pernah keluar. Lonceng
 *      web untuk Sales tetap berbunyi sebagai jalur cadangan.
 *   2. Kegagalannya tidak boleh mengganggu halaman supir. Supir sudah
 *      menyelesaikan tugasnya; kalau WhatsApp bermasalah, itu urusan yang
 *      harus terlihat di layar Logistik, bukan galat di HP supir.
 *
 * DIANTREKAN, alasannya sama dengan SendDeliveryNotification: penyedia pihak
 * ketiga bisa lambat atau menggantung, dan supir yang menunggu layar berputar
 * di depan gudang pelanggan akan menekan tombolnya lagi.
 */
class SendArrivalNoticeToSales implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $deliveryNoteId) {}

    public function handle(WhatsAppSender $sender): void
    {
        $note = DeliveryNote::with(['salesOrder.user:id,full_name,phone_number', 'customer:id,name'])
            ->find($this->deliveryNoteId);

        // Kabar "sudah sampai" hanya sah untuk barang yang memang sudah
        // dinyatakan sampai. Job yang tertunda lalu berjalan setelah
        // pengirimannya dikoreksi tidak boleh mengabarkan sesuatu yang tidak
        // lagi benar.
        if ($note === null || $note->status !== DeliveryNote::STATUS_DELIVERED) {
            return;
        }

        // Sudah terkirim: jangan diulang. Sales yang menerima kabar yang sama
        // tiga kali akan berhenti membacanya — termasuk kabar keempat.
        if ($note->sales_notify_status === DeliveryNote::NOTIFY_SENT) {
            return;
        }

        $sales = $note->salesOrder?->user;
        $nomor = PhoneNumber::forWhatsApp($sales?->phone_number);

        /*
         * TANPA NOMOR, TIDAK DICOBA ULANG. Mengulang tiga kali tidak akan
         * membuat nomornya muncul; yang dibutuhkan adalah seseorang mengisi
         * data akun Sales-nya. Dicatat gagal beserta alasan yang menyebut
         * orangnya, supaya yang membaca layar tahu persis apa yang harus
         * diperbaiki — bukan "gagal kirim" yang tidak menunjuk ke mana-mana.
         */
        if ($nomor === null) {
            $note->forceFill([
                'sales_notify_status' => DeliveryNote::NOTIFY_FAILED,
                'sales_notify_error' => $sales === null
                    ? 'Pesanan ini tidak terhubung ke akun Sales mana pun, jadi tidak ada yang bisa dikabari.'
                    : sprintf(
                        'Akun Sales %s belum punya nomor HP yang terbaca sebagai satu nomor WhatsApp. Isi di Manajemen Pengguna.',
                        $sales->full_name,
                    ),
            ])->save();

            return;
        }

        $hasil = $sender->send($nomor, $note->pesanWhatsAppSales());

        $note->forceFill([
            'sales_notify_status' => $hasil->status,
            'sales_notify_error' => $hasil->error,
            'sales_notify_attempts' => $note->sales_notify_attempts + 1,
            'sales_notify_phone' => $nomor,
            'sales_notified_at' => $hasil->berhasil() ? now() : $note->sales_notified_at,
        ])->save();

        if ($hasil->status === DeliveryNote::NOTIFY_FAILED) {
            // Dilempar supaya antrean mencoba lagi; statusnya SUDAH tersimpan.
            throw new \RuntimeException($hasil->error ?? 'Pengiriman WhatsApp ke Sales gagal.');
        }
    }

    public function failed(?Throwable $e): void
    {
        DeliveryNote::where('id', $this->deliveryNoteId)
            ->where(fn ($q) => $q->whereNull('sales_notify_status')
                ->orWhere('sales_notify_status', '<>', DeliveryNote::NOTIFY_SENT))
            ->update([
                'sales_notify_status' => DeliveryNote::NOTIFY_FAILED,
                'sales_notify_error' => $e?->getMessage() ?? 'Pengiriman WhatsApp ke Sales gagal setelah beberapa percobaan.',
            ]);
    }
}
