<?php

namespace App\Support\Outbound;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteHandover;
use App\Models\DeliveryNoteHandoverItem;
use App\Models\Notification;
use App\Models\User;
use App\Support\Activity;
use App\Support\DocumentNumber;
use App\Support\Notifier;
use App\Support\Permission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Serah terima Surat Jalan fisik — seluruh aturannya, satu tempat.
 *
 * Controller di sisi gudang dan controller di sisi Kantor Pusat sama-sama
 * memanggil kelas ini. Keduanya menyentuh baris yang sama dari dua arah yang
 * berlawanan, dan aturan "kapan sebuah lembar dianggap kembali ke daftar
 * kirim" harus diputuskan di satu tempat saja — kalau tidak, amplop yang
 * dibatalkan dan lembar yang hilang akan diperlakukan berbeda oleh dua layar
 * yang menampilkan daftar yang sama.
 */
class SjHandover
{
    /**
     * Menyusun satu amplop dan menyatakannya berangkat.
     *
     * @param  array<int, int>  $deliveryNoteIds
     * @param  array{carrier_type: string, carrier_name: string, tracking_no?: ?string, notes?: ?string}  $data
     */
    public function buat(array $deliveryNoteIds, array $data, User $aktor): DeliveryNoteHandover
    {
        $ids = array_values(array_unique(array_map('intval', $deliveryNoteIds)));

        if ($ids === []) {
            throw new RuntimeException('Tidak ada Surat Jalan yang dipilih.');
        }

        return DB::transaction(function () use ($ids, $data, $aktor) {
            /*
             * DIPERIKSA ULANG DI SINI, bukan sekadar percaya pada apa yang
             * dikirim formulir. Antara layar dibuka dan tombol ditekan, rekan
             * di meja sebelah bisa sudah memasukkan lembar yang sama ke
             * amplopnya sendiri — dan kotak centang di layar ini tidak tahu
             * apa-apa soal itu.
             */
            $sj = DeliveryNote::query()
                ->siapKeHo()
                ->whereIn('id', $ids)
                ->with('customer:id,name')
                ->get();

            if ($sj->count() !== count($ids)) {
                throw new RuntimeException(
                    'Sebagian Surat Jalan yang dipilih sudah masuk paket lain atau tidak lagi memenuhi syarat. '
                    .'Muat ulang halaman lalu pilih kembali.'
                );
            }

            /*
             * SATU AMPLOP, SATU GUDANG. Amplop berangkat dari satu meja;
             * mencampur Surat Jalan Karawang dan Pekanbaru dalam satu paket
             * berarti tidak ada satu pun orang yang bisa menyerahkannya.
             */
            if ($sj->pluck('warehouse_id')->unique()->count() > 1) {
                throw new RuntimeException('Surat Jalan dari gudang berbeda tidak bisa digabung dalam satu paket.');
            }

            $paket = DeliveryNoteHandover::create([
                'code' => DocumentNumber::forSjHandover(),
                'warehouse_id' => $sj->first()->warehouse_id,
                'carrier_type' => $data['carrier_type'],
                'carrier_name' => $data['carrier_name'],
                'tracking_no' => $data['tracking_no'] ?? null,
                'status' => DeliveryNoteHandover::STATUS_SENT,
                'sent_at' => now(),
                'sent_by' => $aktor->id,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($sj as $lembar) {
                $paket->items()->create(['delivery_note_id' => $lembar->id]);
            }

            Activity::record(
                action: 'sj_handover.kirim',
                description: 'Mengirim '.$sj->count().' Surat Jalan fisik ke Kantor Pusat lewat '
                    .DeliveryNoteHandover::CARRIER_LABELS[$data['carrier_type']].' ('.$data['carrier_name'].')',
                subject: $paket,
                warehouseId: $paket->warehouse_id,
                properties: ['surat_jalan' => $sj->pluck('document_no')->all()],
                user: $aktor,
            );

            Notifier::toPermission(
                izin: Permission::OUTBOUND_SJ_HANDOVER_RECEIVE,
                // NULL, bukan gudang asalnya: CA duduk di kantor pusat dan
                // tidak terikat gudang mana pun. Membatasinya ke gudang
                // pengirim berarti loncengnya tidak pernah berbunyi.
                warehouseId: null,
                type: Notification::SJ_HANDOVER_SENT,
                title: 'Paket Surat Jalan '.$paket->code.' dikirim',
                body: $sj->count().' lembar Surat Jalan dikirim lewat '
                    .$paket->carrier_label.' ('.$paket->carrier_name.').',
                url: '/wms/outbound/sj-fisik/masuk/'.$paket->id,
                subject: $paket,
            );

            return $paket;
        });
    }

    /**
     * CA menutup sebuah amplop setelah memeriksa seluruh isinya.
     *
     * @param  array<int, array{status: string, note?: ?string}>  $keputusan  dikunci per id baris isi
     */
    public function konfirmasiTerima(
        DeliveryNoteHandover $paket,
        array $keputusan,
        ?string $catatan,
        User $aktor,
    ): DeliveryNoteHandover {
        if (! $paket->dalamPerjalanan()) {
            throw new RuntimeException('Paket ini sudah ditutup sebelumnya.');
        }

        return DB::transaction(function () use ($paket, $keputusan, $catatan, $aktor) {
            $isi = $paket->items()->lockForUpdate()->get();

            /*
             * SELURUH BARIS HARUS DIPUTUSKAN. Menutup amplop dengan satu baris
             * yang belum dilihat berarti menyatakan lengkap sesuatu yang belum
             * dihitung — dan baris itulah yang paling mungkin justru tidak ada
             * di dalamnya.
             */
            $belum = $isi->reject(fn ($item) => isset($keputusan[$item->id]));

            if ($belum->isNotEmpty()) {
                throw new RuntimeException('Masih ada '.$belum->count().' Surat Jalan yang belum diperiksa.');
            }

            $hilang = 0;

            foreach ($isi as $item) {
                $status = $keputusan[$item->id]['status'];
                $alasan = trim((string) ($keputusan[$item->id]['note'] ?? '')) ?: null;

                $item->fill([
                    'check_status' => $status,
                    'check_note' => $alasan,
                    'checked_at' => now(),
                    'checked_by' => $aktor->id,
                ]);

                /*
                 * Yang tidak ada di dalam amplop DILEPAS kembali ke daftar
                 * kirim. Barisnya tidak dihapus: jejak "pernah berangkat lalu
                 * tidak sampai" justru yang dibutuhkan orang yang harus
                 * mencari kertasnya.
                 */
                if ($status === DeliveryNoteHandoverItem::CHECK_MISSING) {
                    $item->released_at = now();
                    $item->released_reason = DeliveryNoteHandoverItem::RELEASED_MISSING;
                    $hilang++;
                }

                $item->save();
            }

            $paket->update([
                'status' => DeliveryNoteHandover::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $aktor->id,
                'received_notes' => $catatan,
            ]);

            $paket->setRelation('items', $isi);

            Activity::record(
                action: 'sj_handover.terima',
                description: 'Menerima paket '.$paket->code.' berisi '.$isi->count().' Surat Jalan'
                    .($hilang > 0 ? ' — '.$hilang.' lembar tidak ada di dalamnya' : ''),
                subject: $paket,
                warehouseId: $paket->warehouse_id,
                properties: ['tidak_ada' => $hilang, 'bermasalah' => $paket->jumlahBermasalah()],
                user: $aktor,
            );

            $this->kabarkanHasil($paket, $hilang);

            return $paket;
        });
    }

    /** Amplop yang terlanjur salah dibuat — isinya kembali ke daftar kirim. */
    public function batalkan(DeliveryNoteHandover $paket, string $alasan, User $aktor): DeliveryNoteHandover
    {
        if (! $paket->bolehDibatalkan()) {
            throw new RuntimeException('Paket yang sudah dikonfirmasi Kantor Pusat tidak bisa dibatalkan.');
        }

        return DB::transaction(function () use ($paket, $alasan, $aktor) {
            $paket->items()->aktif()->update([
                'released_at' => now(),
                'released_reason' => DeliveryNoteHandoverItem::RELEASED_CANCELLED,
                'updated_at' => now(),
            ]);

            $paket->update([
                'status' => DeliveryNoteHandover::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $aktor->id,
                'cancel_reason' => $alasan,
            ]);

            Activity::record(
                action: 'sj_handover.batal',
                description: 'Membatalkan paket '.$paket->code.': '.$alasan,
                subject: $paket,
                warehouseId: $paket->warehouse_id,
                user: $aktor,
            );

            return $paket;
        });
    }

    /**
     * Kabar balik ke gudang.
     *
     * DUA LONCENG BERBEDA, dan itu disengaja. "Paket sampai" adalah kabar
     * baik yang boleh dibaca besok; "ada lembar yang tidak ada" adalah
     * pekerjaan yang harus dimulai hari ini, selagi orang yang membawa
     * amplopnya masih ingat. Menyatukan keduanya dalam satu lonceng bernada
     * sama membuat yang kedua ikut terbaca sebagai kabar baik.
     */
    private function kabarkanHasil(DeliveryNoteHandover $paket, int $hilang): void
    {
        $tautan = '/wms/outbound/sj-fisik/'.$paket->id;

        Notifier::toUser(
            userId: $paket->sent_by,
            type: Notification::SJ_HANDOVER_RECEIVED,
            title: 'Paket '.$paket->code.' diterima Kantor Pusat',
            body: $paket->items->count().' lembar diperiksa'
                .($paket->jumlahBermasalah() > 0 ? ', '.$paket->jumlahBermasalah().' dengan catatan' : ' dan semuanya sesuai').'.',
            url: $tautan,
            warehouseId: $paket->warehouse_id,
            subject: $paket,
        );

        if ($hilang > 0) {
            Notifier::toPermission(
                izin: Permission::OUTBOUND_SJ_HANDOVER,
                warehouseId: $paket->warehouse_id,
                type: Notification::SJ_HANDOVER_MISSING,
                title: $hilang.' Surat Jalan tidak ada di paket '.$paket->code,
                body: 'Kantor Pusat tidak menemukan lembarnya di dalam amplop. '
                    .'Surat Jalan itu kembali ke daftar belum dikirim.',
                url: $tautan,
                subject: $paket,
            );
        }
    }
}
