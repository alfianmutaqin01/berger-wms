<?php

namespace App\Support\Inventory;

use App\Models\StockTake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dasar persetujuan yang dilampirkan saat laporan stocktake disahkan.
 *
 * Lazimnya berita acara stock opname yang sudah ditandatangani: hasil pindai
 * PDF, atau foto lembarnya dari HP. Sistem tidak menerbitkan dokumen itu dan
 * tidak memeriksa isinya — yang dijaganya hanya bahwa dokumennya ADA,
 * tersimpan, dan bisa dibuka lagi oleh orang yang berhak.
 *
 * DISK PRIVAT, SEPERTI BUKTI SURAT JALAN. Berita acara memuat nama, tanda
 * tangan, dan angka selisih stok satu gudang. Ditaruh di disk publik, siapa
 * pun yang menebak nama berkasnya bisa mengunduhnya tanpa login.
 */
class StockTakeApproval
{
    private const DISK = 'local';

    private const FOLDER = 'stocktake-approvals';

    /**
     * 10 MB. Lebih longgar daripada foto Surat Jalan (5 MB) karena berita
     * acara sering berupa pindaian beberapa halaman, dan mesin pindai kantor
     * jarang diatur hemat ukuran. Ditolak karena "terlalu besar" pada dokumen
     * yang sah cuma melahirkan kebiasaan memotret layar monitor.
     */
    public const MAKS_KB = 10240;

    /** PDF untuk hasil pindai, gambar untuk foto lembarnya dari HP. */
    public const EKSTENSI_DIIZINKAN = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /**
     * Menyimpan berkasnya dan mengembalikan kolom yang harus ditulis.
     *
     * TIDAK menyentuh basis data, dan sengaja dipanggil di LUAR transaksi
     * pengesahan. Kalau transaksinya gagal, yang tertinggal hanya berkas yatim
     * di disk — jauh lebih murah daripada baris yang menunjuk berkas yang
     * gagal ditulis. Berkas yatim itu dibuang lewat `buang()`.
     *
     * @return array{
     *     approval_doc_path: string, approval_doc_name: string,
     *     approval_doc_mime: string, approval_doc_size: int
     * }
     *
     * @throws RuntimeException
     */
    public function simpan(UploadedFile $berkas): array
    {
        $path = $berkas->store(self::FOLDER, self::DISK);

        if ($path === false) {
            throw new RuntimeException('Lampiran gagal disimpan. Coba unggah ulang berkasnya.');
        }

        return [
            'approval_doc_path' => $path,
            // Nama asli ikut disimpan supaya yang mengunduhnya bertahun
            // kemudian menerima "BA Opname Gudang A Okt 2026.pdf", bukan nama
            // acak yang dipakai di disk.
            'approval_doc_name' => $this->namaAman($berkas->getClientOriginalName()),
            'approval_doc_mime' => $berkas->getMimeType() ?? 'application/octet-stream',
            'approval_doc_size' => (int) $berkas->getSize(),
        ];
    }

    /** Membuang berkas yang terlanjur tersimpan saat pengesahannya gagal. */
    public function buang(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Menyajikan lampirannya lewat rute berizin, bukan dari folder publik.
     *
     * Ditampilkan di jendela peramban (inline), bukan dipaksa diunduh: yang
     * membukanya biasanya sedang memeriksa satu laporan, bukan mengarsipkan.
     */
    public function tampilkan(StockTake $sesi): StreamedResponse
    {
        abort_if(blank($sesi->approval_doc_path), 404);
        abort_unless(Storage::disk(self::DISK)->exists($sesi->approval_doc_path), 404);

        return Storage::disk(self::DISK)->response(
            $sesi->approval_doc_path,
            $sesi->approval_doc_name ?: 'dasar-pengesahan-'.$sesi->reference,
            ['Content-Type' => $sesi->approval_doc_mime ?: 'application/octet-stream'],
        );
    }

    /**
     * Nama berkas dari komputer orang lain tidak pernah dipercaya apa adanya.
     *
     * Nama ini dipakai ulang sebagai nama unduhan, jadi ia berakhir di header
     * HTTP dan di halaman. Yang dibuang: jalur direktori (`../`), pemisah
     * jalur, dan karakter kendali — sisanya dibiarkan supaya nama yang wajar
     * tetap terbaca seperti aslinya.
     */
    private function namaAman(?string $nama): string
    {
        $bersih = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string) $nama)) ?? '';
        $bersih = trim(str_replace(['\\', '/'], '-', $bersih));

        return $bersih === '' ? 'dasar-pengesahan' : mb_substr($bersih, 0, 255);
    }
}
