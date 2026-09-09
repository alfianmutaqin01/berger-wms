<?php

namespace App\Support\Outbound;

use App\Models\DeliveryNote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto "barang sampai" yang diambil supir di lokasi — Fase 12.
 *
 * APA YANG SEBENARNYA BISA DIJAMIN, DAN APA YANG TIDAK
 * ----------------------------------------------------
 * Permintaannya: fotonya diambil LANGSUNG dengan kamera, bukan dilampirkan
 * dari galeri. Halaman supir memenuhinya dengan membuka kamera di dalam
 * halaman (getUserMedia) — tidak ada tombol pilih berkas sama sekali, dan
 * gambarnya dibentuk dari cuplikan kamera saat itu juga.
 *
 * Yang TIDAK bisa dijamin oleh teknologi web mana pun: bahwa gambar yang
 * sampai ke server benar-benar berasal dari kamera. Peramban tidak
 * menandatangani hasil jepretan, jadi siapa pun yang paham perkakas
 * pengembang bisa mengirim berkas apa saja ke alamat yang sama. Karena itu
 * kelas ini TIDAK berpura-pura memeriksa keaslian; yang dilakukannya:
 *
 *   - menyimpan ASALNYA ('camera' atau 'file') apa adanya, supaya Logistik
 *     tahu mana yang lewat kamera dan mana yang lewat jalur cadangan;
 *   - mencatat WAKTU pengambilan menurut server, bukan menurut perangkat
 *     supir — jam HP bisa disetel mundur, jam server tidak.
 *
 * Mengaku sejauh ini saja jauh lebih berguna daripada memasang label
 * "terverifikasi kamera" yang tidak ada dasarnya: yang membaca label itu
 * akan berhenti curiga justru pada saat ia paling perlu curiga.
 *
 * JALUR CADANGAN MEMANG ADA, DAN ITU DISENGAJA
 * --------------------------------------------
 * Kamera di dalam halaman hanya hidup pada koneksi aman (HTTPS) dan setelah
 * supir mengizinkannya. Kalau salah satunya tidak terpenuhi, halaman jatuh
 * ke pemilih berkas dengan `capture` — dan barisnya ditandai 'file'.
 * Tanpa jalur itu, satu penolakan izin di HP supir berarti pengiriman yang
 * sudah sampai tidak pernah bisa dicatat sampai, dan barang yang benar-benar
 * diterima pelanggan menggantung selamanya di status "dalam pengiriman".
 * Menghukum seluruh alur karena satu izin peramban bukan pengerasan, itu
 * kerusakan.
 */
class ArrivalPhoto
{
    private const DISK = 'local';

    private const FOLDER = 'arrival-photos';

    /** Foto kamera ponsel modern jarang di bawah ini; 8 MB sudah lapang. */
    public const MAKS_BYTE = 8 * 1024 * 1024;

    public const MIME_DIIZINKAN = ['image/jpeg', 'image/png', 'image/webp'];

    public const SUMBER_KAMERA = 'camera';

    public const SUMBER_BERKAS = 'file';

    /**
     * Menyimpan berkasnya dan mengembalikan kolom yang harus ditulis.
     *
     * TIDAK menyentuh basis data. Penyimpanan berkas berada di luar transaksi
     * — kalau transaksinya nanti gagal, yang tertinggal cuma berkas yatim di
     * disk, dan itu jauh lebih murah daripada baris basis data yang menunjuk
     * berkas yang gagal ditulis.
     *
     * @return array{
     *     arrival_photo_path: string, arrival_photo_mime: string,
     *     arrival_photo_size: int, arrival_photo_source: string,
     *     arrival_photo_taken_at: Carbon
     * }
     */
    public function simpan(UploadedFile $foto, string $sumber): array
    {
        if (! in_array($sumber, [self::SUMBER_KAMERA, self::SUMBER_BERKAS], true)) {
            $sumber = self::SUMBER_BERKAS;
        }

        $path = $foto->store(self::FOLDER, self::DISK);

        if ($path === false) {
            throw new RuntimeException('Foto gagal disimpan. Coba ambil ulang fotonya.');
        }

        return [
            'arrival_photo_path' => $path,
            'arrival_photo_mime' => $foto->getMimeType() ?? 'image/jpeg',
            'arrival_photo_size' => (int) $foto->getSize(),
            'arrival_photo_source' => $sumber,
            // Waktu SERVER. Jam di HP supir bisa disetel mundur; jam server
            // tidak, dan justru waktulah yang membuat foto ini jadi bukti.
            'arrival_photo_taken_at' => now(),
        ];
    }

    /** Membuang berkas yang terlanjur tersimpan saat penyimpanan barisnya gagal. */
    public function buang(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Menyajikan fotonya ke layar Logistik.
     *
     * Lewat rute berizin, BUKAN dari folder publik. Foto ini memperlihatkan
     * alamat dan halaman pelanggan; ditaruh di storage/public, tautannya bisa
     * dibuka siapa pun yang menebak namanya.
     */
    public function tampilkan(DeliveryNote $note): StreamedResponse
    {
        abort_if(blank($note->arrival_photo_path), 404);
        abort_unless(Storage::disk(self::DISK)->exists($note->arrival_photo_path), 404);

        return Storage::disk(self::DISK)->response(
            $note->arrival_photo_path,
            'bukti-sampai-'.$note->document_no.'.jpg',
            ['Content-Type' => $note->arrival_photo_mime ?? 'image/jpeg'],
        );
    }
}
