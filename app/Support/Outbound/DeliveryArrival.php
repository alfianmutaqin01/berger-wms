<?php

namespace App\Support\Outbound;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyatakan barang SAMPAI — PRD §6.5 F-OUT-04 #10: lewat konfirmasi supir
 * di tautan e-POD, atau ditandai Logistik saat supir tidak bisa membukanya.
 *
 * Keberangkatannya ada di Shipment; bukti Surat Jalan yang menyusul ada di
 * ProofOfDelivery.
 */
class DeliveryArrival
{
    /**
     * Konfirmasi dari supir lewat tautan tanpa login.
     *
     * @throws RuntimeException
     */
    /**
     * @param  array<string, mixed>  $foto  kolom foto bukti sampai; WAJIB terisi
     */
    public function confirmDelivery(DeliveryNote $note, ?string $penerima, array $foto = []): void
    {
        DB::transaction(function () use ($note, $penerima, $foto) {
            $terkunci = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);

            /*
             * FOTO WAJIB — Fase 12.
             *
             * Sebelum ini, menekan "Barang Sudah Sampai" sudah cukup untuk
             * membuat pengiriman tercatat sampai. Tidak ada apa pun yang
             * membedakan barang yang benar-benar diterima pelanggan dari
             * barang yang masih ada di bak mobil, selain perkataan supir yang
             * hari itu mungkin bukan karyawan perusahaan ini.
             *
             * Diperiksa DI DALAM transaksi bersama pemeriksaan status, bukan
             * hanya di controller: halaman supir bukan satu-satunya yang bisa
             * memanggil metode ini, dan aturan sepenting ini tidak boleh
             * tinggal di lapisan yang paling mudah dilewati.
             */
            if (blank($foto['arrival_photo_path'] ?? null)) {
                throw new RuntimeException(
                    'Foto barang di lokasi wajib diambil sebelum pengiriman '
                    .'bisa dinyatakan sampai.'
                );
            }

            if ($terkunci->status === DeliveryNote::STATUS_DELIVERED) {
                // Bukan galat: supir yang menekan dua kali, atau membuka
                // tautannya lagi untuk memastikan. Diam-diam menerima
                // konfirmasi kedua akan menggeser waktu sampainya.
                throw new RuntimeException('Pengiriman ini sudah dikonfirmasi sebelumnya. Terima kasih.');
            }

            if ($terkunci->status !== DeliveryNote::STATUS_SHIPPED) {
                throw new RuntimeException('Pengiriman ini belum dinyatakan berangkat, jadi belum bisa dikonfirmasi.');
            }

            $this->tandaiSampai($terkunci, array_merge([
                'received_by_name' => filled($penerima) ? trim($penerima) : null,
            ], $foto));
        });
    }

    /**
     * Logistik menandai sampai karena supir tidak bisa melakukannya sendiri.
     *
     * JALAN KELUAR, BUKAN JALAN PINTAS. Sejak bukti Surat Jalan hanya terbuka
     * setelah barang dinyatakan sampai, tautan ePOD supir menjadi satu-satunya
     * pintu — dan supir yang kehilangan tautannya atau nomornya salah ketik
     * membuat pesanan itu macet tanpa ada seorang pun yang bisa menutupnya.
     *
     * TANPA FOTO, DAN ITU DISENGAJA. confirmDelivery() mewajibkan foto karena
     * yang menekannya berdiri di tempat tujuan; yang memakai jalur ini justru
     * TIDAK di sana, jadi meminta foto hanya akan melahirkan foto karangan.
     * Gantinya: pelakunya dicatat bernama, alasannya wajib, dan keduanya
     * disimpan di kolom tersendiri supaya audit tidak pernah salah membaca
     * keterangan kantor sebagai kesaksian lapangan.
     *
     * @throws RuntimeException
     */
    public function markArrivedManually(
        DeliveryNote $note,
        string $penerima,
        string $alasan,
        ?int $userId,
    ): void {
        if (trim($alasan) === '') {
            throw new RuntimeException(
                'Alasan wajib diisi — ini satu-satunya catatan kenapa supir tidak menekan konfirmasinya sendiri.'
            );
        }

        DB::transaction(function () use ($note, $penerima, $alasan, $userId) {
            $terkunci = DeliveryNote::query()->lockForUpdate()->findOrFail($note->id);

            if ($terkunci->status === DeliveryNote::STATUS_DELIVERED) {
                throw new RuntimeException(sprintf(
                    'Surat Jalan %s sudah tercatat sampai, jadi tidak perlu ditandai lagi.',
                    $terkunci->document_no,
                ));
            }

            if ($terkunci->status !== DeliveryNote::STATUS_SHIPPED) {
                throw new RuntimeException(
                    'Pengiriman ini belum dinyatakan berangkat, jadi belum bisa ditandai sampai.'
                );
            }

            $this->tandaiSampai($terkunci, [
                'received_by_name' => trim($penerima),
                'arrival_manual_by' => $userId,
                'arrival_manual_reason' => trim($alasan),
            ]);
        });
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Satu tempat yang memindahkan SJ dan pesanannya ke keadaan "sampai".
     *
     * Dipakai kedua jalur — konfirmasi supir dan penandaan manual Logistik —
     * supaya keduanya tidak pernah bisa berbeda dalam hal yang sama: waktu
     * sampainya, status SJ-nya, dan perpindahan pesanan ke antrean bukti.
     * Yang membedakan keduanya hanya kolom yang dititipkan lewat $kolom.
     *
     * WAJIB dipanggil di dalam transaksi pemanggilnya, pada baris yang sudah
     * dikunci dan sudah diperiksa statusnya.
     *
     * @param  array<string, mixed>  $kolom
     */
    private function tandaiSampai(DeliveryNote $terkunci, array $kolom): void
    {
        $terkunci->fill(array_merge([
            'status' => DeliveryNote::STATUS_DELIVERED,
            'delivered_at' => now(),
        ], $kolom))->save();

        $order = SalesOrder::query()->lockForUpdate()->find($terkunci->sales_order_id);

        if ($order !== null && $order->status === SalesOrder::STATUS_SHIPPING) {
            $order->forceFill([
                // Barang sampai, tetapi belum selesai: bukti Surat Jalan
                // bertanda tangan masih harus diunggah dan diverifikasi
                // (F-OUT-05, tahap 5).
                'status' => SalesOrder::STATUS_PROOF_UPLOADED,
                'delivered_at' => now(),
            ])->save();
        }
    }
}
