<?php

namespace App\Support\Outbound;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\SoNumberChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Koreksi nomor SO yang salah ketik — Fase 6 tahap 5.
 *
 * MASALAHNYA: nomor SO diketik manusia saat menerima pesanan. Salah satu
 * digit membuat Surat Jalan dari BC tidak pernah menemukan pesanannya, dan
 * baru ketahuan berhari-hari kemudian ketika SJ-nya sudah terbit.
 *
 * DUA PINTU, dan yang utama sengaja BUKAN pengetikan ulang:
 *
 *   pair()   — pintu utama. Salah ketik selalu ketahuan dari sisi Surat
 *              Jalan ("belum menemukan pesanannya"), dan di situ nomor yang
 *              BENAR sudah tersedia hitam di atas putih. Sistem yang
 *              menyalinnya; jari yang tadi salah ketik tidak diminta
 *              mengetik ulang angka yang sama.
 *
 *   rename() — pintu kecil. Untuk salah ketik yang ketahuan sendiri sebelum
 *              SJ-nya terbit, ketika belum ada dokumen untuk disalin.
 *              DIBATASI pada pesanan yang belum berangkat: mengubah nomor
 *              setelah barang jalan berarti menulis ulang sejarah dokumen
 *              yang sudah dipakai menagih.
 *
 * Keduanya menyimpan nomor lama di so_number_changes.
 */
class SoNumberFixer
{
    /**
     * Pesanan yang nomornya masih boleh dikoreksi manual.
     *
     * Sesudah READY_TO_SHIP, pesanan menunggu Surat Jalan-nya — dan sejak
     * titik itu pair() adalah cara yang benar, karena dokumennya sudah ada.
     */
    private const BOLEH_DIUBAH_MANUAL = [
        SalesOrder::STATUS_APPROVED,
        SalesOrder::STATUS_PICKING,
        SalesOrder::STATUS_READY_TO_SHIP,
    ];

    /**
     * Memasangkan Surat Jalan yatim ke pesanannya, sekaligus membetulkan
     * nomor SO pesanan itu agar sama dengan dokumen BC.
     *
     * @throws RuntimeException
     */
    public function pair(DeliveryNote $note, SalesOrder $order, int $userId): void
    {
        DB::transaction(function () use ($note, $order, $userId) {
            $sj = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);
            $pesanan = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            $this->pastikanSjBolehDipasangkan($sj);
            $this->pastikanPesananBolehMenerimaSj($pesanan);

            // Pelanggan berbeda hampir selalu berarti pesanan yang dipilih
            // keliru — dan memasangkannya akan memindahkan bukti pengiriman
            // ke pelanggan yang salah.
            if ($sj->customer_id !== null && $sj->customer_id !== $pesanan->customer_id) {
                throw new RuntimeException(sprintf(
                    'Surat Jalan %s milik pelanggan %s, sedangkan pesanan %s milik pelanggan lain. '
                    .'Periksa lagi pesanan yang dipilih.',
                    $sj->document_no,
                    $sj->customer_code ?: 'tidak diketahui',
                    $pesanan->order_number,
                ));
            }

            $nomorBaru = trim((string) $sj->bc_so_number);
            $nomorLama = $pesanan->bc_so_number;

            if ($nomorBaru === '') {
                throw new RuntimeException(sprintf(
                    'Surat Jalan %s tidak memuat nomor SO, jadi tidak ada yang bisa disalin.',
                    $sj->document_no,
                ));
            }

            if (! $this->samaTanpaPeduliHuruf($nomorLama, $nomorBaru)) {
                $this->pastikanNomorBelumDipakai($nomorBaru, $pesanan->id);

                $pesanan->forceFill(['bc_so_number' => $nomorBaru])->save();

                $this->catat($pesanan, $nomorLama, $nomorBaru, SoNumberChange::SOURCE_PAIRING, $userId, $sj->id);
            }

            $sj->forceFill([
                'sales_order_id' => $pesanan->id,
                // Gudang dan pelanggan SJ yatim kosong (importir mengambilnya
                // dari pesanan yang saat itu tidak ketemu). Diisi sekarang,
                // supaya dokumen ini ikut terlihat di daftar gudangnya.
                'warehouse_id' => $pesanan->warehouse_id,
                'customer_id' => $sj->customer_id ?? $pesanan->customer_id,
            ])->save();
        });
    }

    /**
     * Koreksi manual nomor SO, sebelum Surat Jalan-nya terbit.
     *
     * @throws RuntimeException
     */
    public function rename(SalesOrder $order, string $nomorBaru, ?string $alasan, int $userId): void
    {
        $nomorBaru = trim($nomorBaru);

        if ($nomorBaru === '') {
            throw new RuntimeException('Nomor SO baru wajib diisi.');
        }

        DB::transaction(function () use ($order, $nomorBaru, $alasan, $userId) {
            $pesanan = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $nomorLama = $pesanan->bc_so_number;

            if ($this->samaTanpaPeduliHuruf($nomorLama, $nomorBaru)) {
                throw new RuntimeException('Nomor SO-nya sama dengan yang sekarang; tidak ada yang perlu diubah.');
            }

            if ($pesanan->cancelled_at !== null) {
                throw new RuntimeException('Pesanan ini sudah dibatalkan.');
            }

            if (! in_array($pesanan->status, self::BOLEH_DIUBAH_MANUAL, true)) {
                throw new RuntimeException(sprintf(
                    'Nomor SO pesanan %s tidak bisa diketik ulang karena statusnya sudah "%s". '
                    .'Kalau Surat Jalan-nya sudah terbit, betulkan lewat tombol Pasangkan di Surat Jalan itu '
                    .'supaya nomornya disalin langsung dari dokumen BC.',
                    $pesanan->order_number,
                    $pesanan->status_label,
                ));
            }

            // Pesanan tambahan menumpang nomor SO induknya. Mengubahnya di
            // sini akan memutus penggabungan invoice tanpa ada yang memintanya.
            if ($pesanan->so_merged_into_id !== null) {
                throw new RuntimeException(
                    'Pesanan ini digabung ke invoice pesanan lain, jadi nomor SO-nya mengikuti pesanan induk.'
                );
            }

            $this->pastikanNomorBelumDipakai($nomorBaru, $pesanan->id);

            $pesanan->forceFill(['bc_so_number' => $nomorBaru])->save();

            // Pesanan tambahan yang menumpang nomor induk ikut berpindah,
            // kalau tidak penggabungan invoice-nya diam-diam pecah.
            SalesOrder::query()
                ->where('so_merged_into_id', $pesanan->id)
                ->update(['bc_so_number' => $nomorBaru]);

            $this->catat($pesanan, $nomorLama, $nomorBaru, SoNumberChange::SOURCE_MANUAL, $userId, null, $alasan);

            $this->pasangkanSjYangMenunggu($pesanan, $nomorBaru);
        });
    }

    /**
     * Pesanan yang masuk akal dipasangkan dengan SJ ini.
     *
     * Sengaja SEMPIT. Daftar panjang berisi pesanan yang tidak mungkin benar
     * hanya memperbesar peluang salah pilih, dan salah pasang jauh lebih
     * mahal daripada tidak menemukan pilihan.
     *
     * @return Collection<int, SalesOrder>
     */
    public function kandidat(DeliveryNote $note, int $batas = 20)
    {
        return SalesOrder::query()
            ->with(['customer:id,code,name', 'warehouse:id,code,name'])
            ->whereIn('status', [
                SalesOrder::STATUS_PICKING,
                SalesOrder::STATUS_READY_TO_SHIP,
            ])
            ->whereNull('cancelled_at')
            ->whereNull('so_merged_into_id')
            // Pesanan yang SJ-nya sudah ada tidak butuh SJ kedua.
            ->whereDoesntHave('deliveryNotes')
            ->when($note->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->when(
                $note->customer_id === null && filled($note->customer_code),
                fn ($q) => $q->whereHas(
                    'customer',
                    fn ($c) => $c->whereRaw('UPPER(code) = ?', [strtoupper((string) $note->customer_code)])
                )
            )
            ->orderByDesc('id')
            ->limit($batas)
            ->get();
    }

    /* ------------------------------------------------------------- Dalam */

    private function pastikanSjBolehDipasangkan(DeliveryNote $sj): void
    {
        if ($sj->sales_order_id !== null) {
            throw new RuntimeException(sprintf(
                'Surat Jalan %s sudah berpasangan dengan sebuah pesanan.',
                $sj->document_no,
            ));
        }

        if ($sj->status !== DeliveryNote::STATUS_IMPORTED) {
            throw new RuntimeException(sprintf(
                'Surat Jalan %s sudah berstatus "%s"; pemasangannya sudah terlambat.',
                $sj->document_no,
                $sj->status_label,
            ));
        }
    }

    private function pastikanPesananBolehMenerimaSj(SalesOrder $pesanan): void
    {
        if ($pesanan->cancelled_at !== null) {
            throw new RuntimeException(sprintf('Pesanan %s sudah dibatalkan.', $pesanan->order_number));
        }

        if ($pesanan->so_merged_into_id !== null) {
            throw new RuntimeException(sprintf(
                'Pesanan %s digabung ke invoice pesanan lain. Pasangkan Surat Jalan ini ke pesanan induknya.',
                $pesanan->order_number,
            ));
        }

        if (! in_array($pesanan->status, [
            SalesOrder::STATUS_PICKING,
            SalesOrder::STATUS_READY_TO_SHIP,
        ], true)) {
            throw new RuntimeException(sprintf(
                'Pesanan %s berstatus "%s". Surat Jalan hanya bisa dipasangkan ke pesanan yang belum berangkat.',
                $pesanan->order_number,
                $pesanan->status_label,
            ));
        }

        if ($pesanan->deliveryNotes()->exists()) {
            throw new RuntimeException(sprintf(
                'Pesanan %s sudah punya Surat Jalan.',
                $pesanan->order_number,
            ));
        }
    }

    private function pastikanNomorBelumDipakai(string $nomor, int $kecualiOrderId): void
    {
        $pemegang = SalesOrder::query()
            ->with('customer:id,name')
            ->whereRaw('UPPER(bc_so_number) = ?', [strtoupper($nomor)])
            ->whereNull('so_merged_into_id')
            ->whereNull('cancelled_at')
            ->whereKeyNot($kecualiOrderId)
            ->first();

        if ($pemegang !== null) {
            throw new RuntimeException(sprintf(
                'Nomor SO %s sedang dipakai pesanan %s (%s). Satu nomor SO tidak boleh dipegang dua pesanan.',
                $nomor,
                $pemegang->order_number,
                $pemegang->customer?->name ?? 'pelanggan tidak diketahui',
            ));
        }
    }

    /**
     * Menyambungkan SJ yatim yang ternyata memakai nomor yang baru dibetulkan.
     *
     * Tanpa ini, Logistik harus mengimpor ulang berkas yang sama hanya supaya
     * pencocokannya diulang — dan berkas itu sudah dibuang.
     */
    private function pasangkanSjYangMenunggu(SalesOrder $pesanan, string $nomor): void
    {
        DeliveryNote::query()
            ->whereNull('sales_order_id')
            ->where('status', DeliveryNote::STATUS_IMPORTED)
            ->whereRaw('UPPER(bc_so_number) = ?', [strtoupper($nomor)])
            ->update([
                'sales_order_id' => $pesanan->id,
                'warehouse_id' => $pesanan->warehouse_id,
            ]);
    }

    private function samaTanpaPeduliHuruf(?string $a, ?string $b): bool
    {
        return strtoupper(trim((string) $a)) === strtoupper(trim((string) $b));
    }

    private function catat(
        SalesOrder $pesanan,
        ?string $lama,
        string $baru,
        string $sumber,
        int $userId,
        ?int $noteId = null,
        ?string $alasan = null,
    ): void {
        SoNumberChange::create([
            'sales_order_id' => $pesanan->id,
            'old_number' => $lama,
            'new_number' => $baru,
            'source' => $sumber,
            'delivery_note_id' => $noteId,
            'reason' => filled($alasan) ? trim($alasan) : null,
            'changed_by' => $userId,
        ]);
    }
}
