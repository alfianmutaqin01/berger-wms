<?php

namespace App\Jobs;

use App\Models\MaterialRequisition;
use App\Support\Messaging\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Mengirim tautan persetujuan MRF ke WhatsApp atasan.
 *
 * KEMBARAN SendDeliveryNotification, dan kemiripannya disengaja: keduanya
 * mengirim satu tautan ke satu nomor lewat penyedia yang sama, dan keduanya
 * harus berperilaku sama saat penyedianya sedang bermasalah.
 *
 * DIANTREKAN, BUKAN DIJALANKAN SAAT TOMBOL DITEKAN. Panggilan ke penyedia
 * pihak ketiga bisa lambat atau menggantung; menjalankannya di dalam
 * permintaan HTTP membuat Produksi menatap layar berputar, dan bila
 * penyedianya sedang mati, tombol Simpan seolah rusak padahal permintaannya
 * sudah tersimpan rapi.
 *
 * KEGAGALAN TIDAK MEMBATALKAN PERMINTAANNYA. Yang ditulis di sini hanya
 * status PESANNYA. Dalam mode manual — yang jadi bawaan — pesan memang tidak
 * pernah terkirim sendiri: layar MRF menyediakan tombol wa.me yang tinggal
 * ditekan Produksi, dan status `manual` itulah yang menandainya.
 */
class SendMrfApprovalRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $requisitionId) {}

    public function handle(WhatsAppSender $sender): void
    {
        $mrf = MaterialRequisition::with(['items.product:id,sku,name', 'warehouse:id,name', 'requestedBy:id,full_name'])
            ->find($this->requisitionId);

        if ($mrf === null || blank($mrf->approver_phone) || $mrf->approval_token === null) {
            return;
        }

        // Sudah terkirim: jangan diulang. Antrean bisa menjalankan job lagi
        // setelah gangguan, dan atasan yang menerima permintaan yang sama tiga
        // kali akan berhenti membacanya — termasuk yang keempat, yang genting.
        if ($mrf->notify_status === MaterialRequisition::NOTIFY_SENT) {
            return;
        }

        // Permintaan yang sudah diputus tidak perlu tautan lagi. Tanpa pagar
        // ini, percobaan ulang yang terlambat mengirim tautan ke permintaan
        // yang sudah ditolak — dan yang membukanya menemukan halaman yang
        // tidak bisa ditekan apa pun.
        if ($mrf->status !== MaterialRequisition::STATUS_PENDING_APPROVAL) {
            return;
        }

        $hasil = $sender->send($mrf->approver_phone, $mrf->pesanWhatsAppApprover());

        $mrf->forceFill([
            'notify_status' => $hasil->status,
            'notify_error' => $hasil->error,
            'notify_attempts' => $mrf->notify_attempts + 1,
            'notified_at' => $hasil->berhasil() ? now() : $mrf->notified_at,
        ])->save();

        if ($hasil->status === MaterialRequisition::NOTIFY_FAILED) {
            // Dilempar supaya antrean mencoba lagi. Statusnya SUDAH tersimpan
            // lebih dulu, jadi walaupun seluruh percobaan habis, layar tetap
            // menampilkan kegagalan beserta alasannya — bukan diam.
            throw new \RuntimeException($hasil->error ?? 'Pengiriman WhatsApp gagal.');
        }
    }

    public function failed(?Throwable $e): void
    {
        MaterialRequisition::where('id', $this->requisitionId)
            ->where('notify_status', '<>', MaterialRequisition::NOTIFY_SENT)
            ->update([
                'notify_status' => MaterialRequisition::NOTIFY_FAILED,
                'notify_error' => $e?->getMessage() ?? 'Pengiriman WhatsApp gagal setelah beberapa percobaan.',
            ]);
    }
}
