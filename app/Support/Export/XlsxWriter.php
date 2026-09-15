<?php

namespace App\Support\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menulis satu tabel menjadi berkas .xlsx yang langsung terunduh.
 *
 * BARIS JUDUL BUKAN HIASAN
 * ------------------------
 * Tiga baris pertama memuat nama laporan, rentang tanggal, gudang, dan waktu
 * unduh. Berkas Excel hidup lebih lama daripada layar yang melahirkannya: ia
 * di-forward, disimpan, dan dibuka lagi berbulan-bulan kemudian oleh orang
 * yang tidak pernah melihat penyaringnya. Tanpa keterangan itu, tidak ada
 * cara membedakan laporan September dari laporan Oktober selain menebak.
 *
 * ANGKA DITULIS SEBAGAI ANGKA
 * ---------------------------
 * Kolom yang didaftarkan numerik ditulis dengan tipe NUMERIC, bukan dibiarkan
 * ditebak. Yang ditebak salah akan mendarat sebagai teks, dan SUM() di Excel
 * mengembalikan nol tanpa keluhan apa pun — kesalahan yang tidak terlihat
 * sampai seseorang memakai angkanya.
 */
class XlsxWriter
{
    /** Warna kepala tabel, mengikuti biru utama tampilan WMS. */
    private const WARNA_KEPALA = 'FF123962';

    /**
     * @param  list<string>  $kolom
     * @param  list<array>  $baris
     * @param  list<int>  $angka  indeks kolom yang harus ditulis sebagai bilangan
     * @param  array<string, string>  $keterangan  baris "Label: nilai" di atas tabel
     */
    public static function unduh(
        string $namaBerkas,
        string $judul,
        array $kolom,
        array $baris,
        array $angka = [],
        array $keterangan = [],
    ): StreamedResponse {
        $sheet = self::susun($judul, $kolom, $baris, $angka, $keterangan);

        return response()->streamDownload(function () use ($sheet) {
            (new Xlsx($sheet))->save('php://output');
            $sheet->disconnectWorksheets();
        }, $namaBerkas, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // Tanpa ini, proxy atau browser bisa menyajikan berkas lama saat
            // rentang tanggalnya diganti — dan yang membukanya tidak akan
            // curiga, karena isinya memang berupa laporan yang masuk akal.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private static function susun(
        string $judul,
        array $kolom,
        array $baris,
        array $angka,
        array $keterangan,
    ): Spreadsheet {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();

        // Nama tab dibatasi 31 karakter oleh format xlsx itu sendiri; melebihi
        // itu berkasnya rusak, bukan sekadar terpotong.
        $ws->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $judul), 0, 31));

        $lebar = max(count($kolom), 1);
        $angka = array_flip($angka);

        $ws->setCellValue('A1', $judul);
        $ws->mergeCells('A1:'.self::huruf($lebar).'1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $ket = [];

        foreach ($keterangan as $label => $nilai) {
            $ket[] = $label.': '.$nilai;
        }

        $ws->setCellValue('A2', implode('   ·   ', $ket));
        $ws->mergeCells('A2:'.self::huruf($lebar).'2');
        $ws->getStyle('A2')->getFont()->setSize(9)->getColor()->setARGB('FF6B7280');

        $barisKepala = 4;

        foreach ($kolom as $i => $judulKolom) {
            $ws->setCellValue([$i + 1, $barisKepala], $judulKolom);
        }

        $rentangKepala = self::huruf(1).$barisKepala.':'.self::huruf($lebar).$barisKepala;
        $gaya = $ws->getStyle($rentangKepala);
        $gaya->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $gaya->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::WARNA_KEPALA);
        $gaya->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getRowDimension($barisKepala)->setRowHeight(24);

        $r = $barisKepala + 1;

        foreach ($baris as $satu) {
            foreach (array_values($satu) as $i => $nilai) {
                $sel = $ws->getCell([$i + 1, $r]);

                if ($nilai === null || $nilai === '') {
                    continue;
                }

                if (isset($angka[$i]) && is_numeric($nilai)) {
                    $sel->setValueExplicit($nilai + 0, DataType::TYPE_NUMERIC);

                    continue;
                }

                // Selebihnya DIPAKSA teks. Tanpa ini Excel menafsirkan sendiri:
                // batch "0012" kehilangan nolnya, "1-2" berubah jadi tanggal,
                // dan nomor yang seharusnya bisa dicocokkan jadi tidak cocok
                // lagi dengan sumbernya.
                $sel->setValueExplicit((string) $nilai, DataType::TYPE_STRING);
            }

            $r++;
        }

        $akhir = max($r - 1, $barisKepala);

        $ws->getStyle(self::huruf(1).$barisKepala.':'.self::huruf($lebar).$akhir)
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FFD1D5DB');

        // Baris kepala dibekukan supaya judul kolom tetap terlihat saat
        // digulir — laporan 4.000 baris tanpa ini praktis tidak terbaca.
        $ws->freezePane('A'.($barisKepala + 1));
        $ws->setAutoFilter(self::huruf(1).$barisKepala.':'.self::huruf($lebar).$akhir);

        for ($c = 1; $c <= $lebar; $c++) {
            $ws->getColumnDimension(self::huruf($c))->setAutoSize(true);
        }

        return $book;
    }

    /** Nomor kolom (1) menjadi hurufnya ("A"). */
    private static function huruf(int $nomor): string
    {
        $huruf = '';

        while ($nomor > 0) {
            $sisa = ($nomor - 1) % 26;
            $huruf = chr(65 + $sisa).$huruf;
            $nomor = intdiv($nomor - $sisa, 26);
        }

        return $huruf ?: 'A';
    }
}
