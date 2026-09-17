<?php

namespace App\Jobs;

use App\Models\MaterialRequisition;
use App\Support\Messaging\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mengabari pemohon lewat tautan divisi bahwa materialnya bisa diambil.
 *
 * KEMBARAN SendMrfApprovalRequest, dan kemiripannya disengaja: keduanya
 * mengirim satu pesan ke satu nomor lewat penyedia yang sama, dan keduanya
 * harus berperilaku sama saat penyedianya bermasalah.
 *
 * DIKIRIM LEWAT WHATSAPP, bukan lonceng di dalam WMS. Pemohonnya QC atau R&D
 * yang tidak punya akun — lonceng itu tidak akan pernah ia lihat, dan tanpa
 * kabar ini barangnya berdiri di titik transit sampai ada yang kebetulan
 * menanyakannya.
 *
 * KEGAGALANNYA TIDAK MENGUBAH APA PUN pada permintaannya. Barangnya sudah
 * turun dari rak dan tetap menunggu; yang gagal hanya kabarnya. Status
 * pengiriman tidak ditulis ke kolom notify_* karena kolom itu milik pesan
 * persetujuan — menumpanginya akan membuat layar MRF melaporkan pesan atasan
 * gagal padahal yang gagal pesan pengambilan.
 */
class SendMrfPickupReady implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $requisitionId) {}

    public function handle(WhatsAppSender $sender): void
    {
        $mrf = MaterialRequisition::with(['warehouse:id,name', 'handoverLocation:id,code,zone'])
            ->find($this->requisitionId);

        if ($mrf === null || blank($mrf->requester_phone)) {
            return;
        }

        // Sudah diambil orangnya sebelum pesan ini sempat berangkat. Mengirim
        // "silakan diambil" untuk barang yang sudah dibawa hanya membuat
        // penerimanya bolak-balik ke gudang.
        if ($mrf->status !== MaterialRequisition::STATUS_READY_FOR_PICKUP) {
            return;
        }

        $sender->send($mrf->requester_phone, $mrf->pesanSiapDiambil());
    }
}
