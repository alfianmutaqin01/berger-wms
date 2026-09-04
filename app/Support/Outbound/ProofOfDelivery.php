<?php

namespace App\Support\Outbound;

use App\Models\DeliveryProof;
use App\Models\SalesOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Bukti Surat Jalan bertanda tangan — PRD §6.5 F-OUT-05 & F-OUT-06.
 *
 * Sales memotret Surat Jalan yang sudah ditandatangani pelanggan, Logistik
 * memeriksanya, lalu pesanan dinyatakan selesai.
 *
 * SATU-SATUNYA PENULIS status pesanan pada tahap ini. Menyebar penulisan
 * status ke controller membuat "kapan pesanan boleh selesai" punya beberapa
 * jawaban yang perlahan berbeda satu sama lain.
 */
class ProofOfDelivery
{
    /**
     * Disk PRIVAT, bukan 'public'. Foto Surat Jalan memuat tanda tangan,
     * nama, dan alamat pelanggan. Menaruhnya di disk publik berarti siapa pun
     * yang menebak nama berkasnya bisa mengunduhnya tanpa login sama sekali.
     */
    private const DISK = 'local';

    private const FOLDER = 'delivery-proofs';

    /**
     * Status pesanan yang buktinya boleh diunggah.
     *
     * SHIPPING ikut, bukan hanya PROOF_UPLOADED: Sales sering sudah sampai di
     * toko dan memegang Surat Jalan bertanda tangan sebelum supir sempat
     * menekan tautan konfirmasinya. Menolak unggahan karena supir belum
     * menekan tombol berarti menahan pekerjaan yang sudah selesai.
     */
    private const BOLEH_UNGGAH = [
        SalesOrder::STATUS_SHIPPING,
        SalesOrder::STATUS_PROOF_UPLOADED,
    ];

    /**
     * Menyimpan foto-foto yang diunggah Sales.
     *
     * @param  array<int, UploadedFile>  $berkas
     * @return int jumlah foto yang tersimpan
     *
     * @throws RuntimeException
     */
    public function upload(SalesOrder $order, array $berkas, int $userId): int
    {
        if ($berkas === []) {
            throw new RuntimeException('Tidak ada foto yang dipilih.');
        }

        return DB::transaction(function () use ($order, $berkas, $userId) {
            $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            $this->pastikanBolehDiunggah($terkunci);

            /*
             * Sisa kuota dihitung DI DALAM kunci. Sales di HP yang menekan
             * kirim dua kali karena sinyalnya lambat akan mengirim dua
             * permintaan; tanpa kunci keduanya membaca "sudah ada 2" dan
             * keduanya lolos.
             */
            $terpakai = $terkunci->proofs()->masihBerlaku()->count();
            $sisa = DeliveryProof::MAKS_FOTO - $terpakai;

            if (count($berkas) > $sisa) {
                throw new RuntimeException(sprintf(
                    'Pesanan ini sudah punya %d foto yang berlaku, jadi hanya bisa menambah %s lagi (maksimal %d).',
                    $terpakai,
                    $sisa < 1 ? 'tidak ada' : $sisa.' foto',
                    DeliveryProof::MAKS_FOTO,
                ));
            }

            // SJ yang dibuktikan: yang sudah dinyatakan berangkat untuk
            // pesanan ini. Kalau nomor SO di BC berbeda dan tidak ada SJ yang
            // terpasang, kolomnya dibiarkan kosong — buktinya tetap sah.
            $noteId = $terkunci->deliveryNotes()->value('id');

            $tersimpan = 0;

            foreach ($berkas as $satu) {
                $path = $satu->store(self::FOLDER, self::DISK);

                if ($path === false) {
                    throw new RuntimeException('Foto gagal disimpan. Coba unggah ulang.');
                }

                DeliveryProof::create([
                    'sales_order_id' => $terkunci->id,
                    'delivery_note_id' => $noteId,
                    'path' => $path,
                    'original_name' => $satu->getClientOriginalName(),
                    'size' => $satu->getSize(),
                    'mime' => $satu->getMimeType(),
                    'status' => DeliveryProof::STATUS_PENDING,
                    'uploaded_by' => $userId,
                    'uploaded_at' => now(),
                ]);

                $tersimpan++;
            }

            if ($terkunci->status === SalesOrder::STATUS_SHIPPING) {
                $terkunci->forceFill(['status' => SalesOrder::STATUS_PROOF_UPLOADED])->save();
            }

            return $tersimpan;
        });
    }

    /**
     * Logistik menyatakan bukti sah dan pesanan selesai (F-OUT-06 #4).
     *
     * @return string status akhir pesanan
     *
     * @throws RuntimeException
     */
    public function complete(SalesOrder $order, int $userId): string
    {
        return DB::transaction(function () use ($order, $userId) {
            $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($this->sudahSelesai($terkunci)) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s sudah dinyatakan selesai sebelumnya.',
                    $terkunci->order_number,
                ));
            }

            if ($terkunci->status !== SalesOrder::STATUS_PROOF_UPLOADED) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s berstatus "%s", belum sampai tahap verifikasi bukti.',
                    $terkunci->order_number,
                    $terkunci->status_label,
                ));
            }

            $menunggu = $terkunci->proofs()->menunggu()->lockForUpdate()->get();

            if ($menunggu->isEmpty()) {
                throw new RuntimeException(
                    'Belum ada foto Surat Jalan yang bisa diperiksa. '
                    .'Pesanan tidak bisa diselesaikan tanpa bukti.'
                );
            }

            foreach ($menunggu as $bukti) {
                $bukti->forceFill([
                    'status' => DeliveryProof::STATUS_VERIFIED,
                    'verified_by' => $userId,
                    'verified_at' => now(),
                ])->save();
            }

            /*
             * PERCABANGAN TERMIN (PRD F-OUT-06 #5). Bayar di muka selesai
             * sepenuhnya; tempo selesai TAPI masih ditagih, sehingga tetap
             * terlihat di Billing nanti. Membuat keduanya 'completed' berarti
             * piutangnya lenyap dari layar begitu barang sampai.
             */
            $selesai = ($terkunci->paymentTerm?->isImmediate() ?? true)
                ? SalesOrder::STATUS_COMPLETED
                : SalesOrder::STATUS_COMPLETED_BILLING;

            $terkunci->forceFill([
                'status' => $selesai,
                'completed_at' => now(),
                'completed_by' => $userId,
                'sla_hours' => $this->slaJam($terkunci),
            ])->save();

            return $selesai;
        });
    }

    /**
     * Logistik menolak bukti; Sales harus mengunggah ulang (F-OUT-06).
     *
     * Foto yang ditolak TIDAK dihapus dari disk. Kalau nanti pelanggan dan
     * gudang berbeda pendapat soal apa yang diterima, justru foto yang pernah
     * ditolak itulah yang menjelaskan kenapa prosesnya berputar.
     *
     * @return int jumlah foto yang ditolak
     *
     * @throws RuntimeException
     */
    public function reject(SalesOrder $order, string $alasan, int $userId): int
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw new RuntimeException('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($order, $alasan, $userId) {
            $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($this->sudahSelesai($terkunci)) {
                throw new RuntimeException(sprintf(
                    'Pesanan %s sudah selesai; buktinya tidak bisa ditolak lagi.',
                    $terkunci->order_number,
                ));
            }

            $menunggu = $terkunci->proofs()->menunggu()->lockForUpdate()->get();

            if ($menunggu->isEmpty()) {
                throw new RuntimeException('Tidak ada foto yang sedang menunggu diperiksa.');
            }

            foreach ($menunggu as $bukti) {
                $bukti->forceFill([
                    'status' => DeliveryProof::STATUS_REJECTED,
                    'rejection_reason' => $alasan,
                    'verified_by' => $userId,
                    'verified_at' => now(),
                ])->save();
            }

            /*
             * Status pesanan SENGAJA tidak berubah. Pemilik produk memilih
             * tidak menambah status baru supaya tampilan di HP Sales tetap
             * sederhana; yang membedakan "perlu diunggah" dari "perlu
             * diperiksa" adalah ADA-TIDAKNYA foto yang menunggu, bukan
             * statusnya. Lihat scopeMenunggu / scopeMasihBerlaku.
             */

            return $menunggu->count();
        });
    }

    /** Alasan penolakan terakhir, untuk ditampilkan ke Sales. */
    public function alasanTerakhir(SalesOrder $order): ?string
    {
        if ($order->proofs()->menunggu()->exists()) {
            return null;
        }

        return $order->proofs()
            ->where('status', DeliveryProof::STATUS_REJECTED)
            ->latest('verified_at')
            ->value('rejection_reason');
    }

    /** Unduhan foto; dipakai Sales maupun Logistik. */
    public function download(DeliveryProof $proof)
    {
        abort_unless(Storage::disk(self::DISK)->exists($proof->path), 404);

        return Storage::disk(self::DISK)->download($proof->path, $proof->original_name);
    }

    /** Isi berkas untuk ditampilkan inline (pratinjau di layar). */
    public function response(DeliveryProof $proof)
    {
        abort_unless(Storage::disk(self::DISK)->exists($proof->path), 404);

        return Storage::disk(self::DISK)->response(
            $proof->path,
            $proof->original_name,
            ['Content-Type' => $proof->mime],
        );
    }

    /* ------------------------------------------------------------- Dalam */

    private function pastikanBolehDiunggah(SalesOrder $order): void
    {
        if ($order->cancelled_at !== null) {
            throw new RuntimeException('Pesanan ini sudah dibatalkan.');
        }

        if ($this->sudahSelesai($order)) {
            throw new RuntimeException(sprintf(
                'Pesanan %s sudah selesai; buktinya tidak perlu diunggah lagi.',
                $order->order_number,
            ));
        }

        if (! in_array($order->status, self::BOLEH_UNGGAH, true)) {
            throw new RuntimeException(sprintf(
                'Bukti hanya bisa diunggah setelah barang berangkat. Pesanan ini masih berstatus "%s".',
                $order->status_label,
            ));
        }
    }

    private function sudahSelesai(SalesOrder $order): bool
    {
        return in_array($order->status, [
            SalesOrder::STATUS_COMPLETED,
            SalesOrder::STATUS_COMPLETED_BILLING,
        ], true);
    }

    /**
     * Argo mulai berjalan saat barang berangkat (bukan saat pesanan dibuat),
     * dan berhenti saat barang sampai. Verifikasi bukti bisa terlambat
     * berhari-hari karena Sales belum sempat ke toko — memasukkan jeda itu ke
     * dalam SLA akan menghukum gudang atas pekerjaan yang bukan miliknya.
     */
    private function slaJam(SalesOrder $order): ?float
    {
        if ($order->shipped_at === null) {
            return null;
        }

        return round($order->shipped_at->diffInMinutes($order->delivered_at ?? now()) / 60, 2);
    }
}
