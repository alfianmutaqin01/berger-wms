<?php

namespace App\Support\Imaji;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Penyusut foto bukti — satu pintu untuk kedua jalur unggah.
 *
 * MASALAH YANG DIPECAHKAN. Foto dari HP berukuran 2–4 MB, tidak pernah
 * dikompres, dan tidak pernah dihapus. Dengan lima puluh pesanan sehari, disk
 * server habis sekitar 90 GB setahun — dan server yang kehabisan disk tidak
 * melambat, ia berhenti. Disusutkan di sini, angkanya turun ke sekitar 11 GB
 * setahun tanpa satu pun foto hilang dari riwayat.
 *
 * KENAPA 2000 PIKSEL, BUKAN LEBIH KECIL. Yang difoto sistem ini DOKUMEN,
 * bukan pemandangan: ada kolom qty berangka kecil, tanda tangan, dan stempel.
 * Di 1200 piksel angka qty mulai pecah — dan foto yang tidak terbaca akan
 * ditolak Logistik, lalu Sales memotret ulang. Penghematan yang menciptakan
 * pekerjaan baru bukan penghematan.
 *
 * FORMATNYA DIPERTAHANKAN. JPEG tetap JPEG, PNG tetap PNG. Mengubah PNG jadi
 * JPEG memang lebih hemat lagi, tetapi nama asli berkasnya ikut tersimpan dan
 * dipakai saat Logistik mengunduh — berkas bernama .png yang isinya JPEG akan
 * gagal dibuka di sebagian komputer, dan itu terjadi berbulan-bulan kemudian
 * pada arsip yang justru sedang dibutuhkan.
 *
 * GAGAL DENGAN AMAN. Setiap keadaan yang tidak bisa diolah — format tak
 * dikenal, berkas rusak, gambar terlalu besar untuk memori — berakhir dengan
 * foto ASLI yang disimpan apa adanya. Bukti tidak boleh hilang gara-gara
 * penghematan tempat.
 */
class SusutkanFoto
{
    /**
     * Batas jumlah piksel yang aman didekode dengan memory_limit 256M.
     *
     * Satu piksel memakan sekitar 4 byte saat berada di memori, jadi 25 juta
     * piksel sudah sekitar 100 MB — dan salinan hasil penyusutannya masih
     * harus muat di sisanya. Yang lebih besar dari ini disimpan apa adanya:
     * berkasnya tetap di bawah 5 MB karena validasi unggah sudah menjaganya.
     */
    private const MAKS_PIKSEL = 25_000_000;

    /**
     * Foto kecil yang sudah telanjur hemat tidak disentuh.
     *
     * Mengodekan ulang JPEG yang sudah 300 KB hanya menurunkan mutunya tanpa
     * menghemat apa pun yang berarti.
     */
    private const AMBANG_BYTE = 512_000;

    /**
     * Menyimpan foto unggahan dalam ukuran yang sudah disusutkan.
     *
     * @return array{path: string, size: int, mime: string}
     */
    public function simpan(UploadedFile $foto, string $folder, string $disk): array
    {
        $mime = $foto->getMimeType() ?? 'image/jpeg';
        $isi = $this->olah((string) $foto->getRealPath(), $mime, (int) $foto->getSize());

        if ($isi === null) {
            $path = $foto->store($folder, $disk);

            if ($path === false) {
                throw new \RuntimeException('Foto gagal disimpan. Coba ambil ulang fotonya.');
            }

            return ['path' => $path, 'size' => (int) $foto->getSize(), 'mime' => $mime];
        }

        // hashName(), bukan nama acak sendiri: inilah penamaan yang dipakai
        // store() bawaan Laravel, jadi berkas hasil penyusutan tidak bisa
        // dibedakan dari berkas lama saat ditelusuri di disk.
        $path = rtrim($folder, '/').'/'.$foto->hashName();

        Storage::disk($disk)->put($path, $isi);

        return ['path' => $path, 'size' => strlen($isi), 'mime' => $mime];
    }

    /**
     * Menyusutkan foto yang SUDAH tersimpan — dipakai perintah wms:susutkan-foto.
     *
     * @return int|null ukuran baru dalam byte, atau NULL bila tidak diubah
     */
    public function susutkanTersimpan(string $path, string $disk, string $mime): ?int
    {
        $penyimpanan = Storage::disk($disk);

        if (! $penyimpanan->exists($path)) {
            return null;
        }

        $sementara = tempnam(sys_get_temp_dir(), 'susut');

        if ($sementara === false) {
            return null;
        }

        try {
            file_put_contents($sementara, $penyimpanan->get($path));

            $isi = $this->olah($sementara, $mime, (int) filesize($sementara));

            if ($isi === null) {
                return null;
            }

            $penyimpanan->put($path, $isi);

            return strlen($isi);
        } catch (Throwable $e) {
            Log::warning('Foto gagal disusutkan: '.$path.' — '.$e->getMessage());

            return null;
        } finally {
            @unlink($sementara);
        }
    }

    /**
     * Inti penyusutan.
     *
     * @return string|null isi berkas hasil, atau NULL bila foto asli harus dipakai
     */
    private function olah(string $berkasLokal, string $mime, int $ukuran): ?string
    {
        if (! config('wms.foto.aktif')) {
            return null;
        }

        // WebP ikut karena foto "barang sampai" memang menerimanya
        // (ArrivalPhoto::MIME_DIIZINKAN) — HP Android baru memotret ke format
        // itu. Yang di luar daftar ini disimpan apa adanya.
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! is_readable($berkasLokal)) {
            return null;
        }

        $ukuranGambar = @getimagesize($berkasLokal);

        if ($ukuranGambar === false) {
            return null;
        }

        [$lebar, $tinggi] = $ukuranGambar;
        $sisiMaks = (int) config('wms.foto.sisi_maks');

        if ($lebar * $tinggi > self::MAKS_PIKSEL) {
            return null;
        }

        // Sudah kecil DAN sudah hemat — tidak ada yang bisa diperbaiki.
        if (max($lebar, $tinggi) <= $sisiMaks && $ukuran <= self::AMBANG_BYTE) {
            return null;
        }

        $asli = $this->baca($berkasLokal, $mime);

        if ($asli === null) {
            return null;
        }

        try {
            $asli = $this->luruskan($asli, $berkasLokal, $mime);

            $lebar = imagesx($asli);
            $tinggi = imagesy($asli);
            $skala = min(1, $sisiMaks / max($lebar, $tinggi));

            $baru = $skala < 1
                ? $this->perkecil($asli, (int) round($lebar * $skala), (int) round($tinggi * $skala), $mime)
                : $asli;

            $isi = $this->kodekan($baru, $mime);

            if ($baru !== $asli) {
                imagedestroy($baru);
            }
        } catch (Throwable $e) {
            Log::warning('Foto gagal diolah: '.$e->getMessage());

            return null;
        } finally {
            imagedestroy($asli);
        }

        // Hasil yang justru lebih besar dari aslinya dibuang. Terjadi pada
        // PNG kecil: mengodekan ulang bisa menambah, bukan mengurangi.
        return $isi !== null && strlen($isi) < $ukuran ? $isi : null;
    }

    private function baca(string $berkas, string $mime): ?\GdImage
    {
        $gambar = match ($mime) {
            'image/png' => @imagecreatefrompng($berkas),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($berkas) : false,
            default => @imagecreatefromjpeg($berkas),
        };

        return $gambar === false ? null : $gambar;
    }

    /**
     * Memutar foto sesuai arah yang dicatat kamera.
     *
     * HP menyimpan arah putaran di data EXIF dan membiarkan gambarnya sendiri
     * dalam posisi sensor. GD mengabaikan catatan itu — inilah sebabnya foto
     * yang tegak di HP tampil rebah di layar Logistik. Setelah diputar di
     * sini, EXIF-nya ikut hilang bersama pengodean ulang, termasuk koordinat
     * GPS yang tidak pernah dipakai sistem ini sebagai bukti (yang dipakai
     * adalah waktu server) tetapi memuat alamat pelanggan.
     */
    private function luruskan(\GdImage $gambar, string $berkas, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $gambar;
        }

        $exif = @exif_read_data($berkas);
        $arah = (int) ($exif['Orientation'] ?? 1);

        $derajat = match ($arah) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($derajat === 0) {
            return $gambar;
        }

        $diputar = @imagerotate($gambar, $derajat, 0);

        if ($diputar === false) {
            return $gambar;
        }

        imagedestroy($gambar);

        return $diputar;
    }

    private function perkecil(\GdImage $asli, int $lebar, int $tinggi, string $mime): \GdImage
    {
        $baru = imagecreatetruecolor($lebar, $tinggi);

        if ($mime === 'image/png' || $mime === 'image/webp') {
            // Tanpa dua baris ini, bagian tembus pandang berubah jadi hitam.
            imagealphablending($baru, false);
            imagesavealpha($baru, true);
        }

        imagecopyresampled($baru, $asli, 0, 0, 0, 0, $lebar, $tinggi, imagesx($asli), imagesy($asli));

        return $baru;
    }

    private function kodekan(\GdImage $gambar, string $mime): ?string
    {
        ob_start();

        $kualitas = (int) config('wms.foto.kualitas');

        $berhasil = match ($mime) {
            'image/png' => imagepng($gambar, null, 6),
            'image/webp' => imagewebp($gambar, null, $kualitas),
            default => imagejpeg($gambar, null, $kualitas),
        };

        $isi = ob_get_clean();

        return $berhasil && is_string($isi) && $isi !== '' ? $isi : null;
    }
}
