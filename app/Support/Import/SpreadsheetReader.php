<?php

namespace App\Support\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

/**
 * Membaca berkas .xlsx menjadi larik baris asosiatif.
 *
 * Memakai PhpSpreadsheet langsung, BUKAN maatwebsite/excel. Alasannya: versi
 * modern maatwebsite/excel belum mendukung Laravel 13 — composer justru
 * menawarkan v1.1.5 (rilis 2014) yang bergantung pada phpoffice/phpexcel yang
 * sudah ditinggalkan. PhpSpreadsheet adalah mesin yang dipakai paket tersebut
 * di baliknya, aktif dipelihara, dan tidak terikat versi Laravel.
 */
class SpreadsheetReader
{
    /** Batas baris agar berkas raksasa tidak menghabiskan memori. */
    public const MAX_ROWS = 5000;

    /**
     * @return list<array<string, string>> Baris data; kunci = judul kolom
     *                                     yang sudah dinormalkan
     *
     * @throws RuntimeException bila berkas tidak terbaca atau tidak berisi data
     */
    public static function rows(string $path): array
    {
        try {
            // setReadDataOnly: abaikan gaya/format sel — kita hanya butuh
            // nilainya, dan ini memangkas penggunaan memori secara drastis.
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (ReaderException $e) {
            throw new RuntimeException('Berkas tidak dapat dibaca. Pastikan formatnya .xlsx atau .xls yang sah.');
        }

        $raw = $sheet->toArray(null, true, false, false);

        if ($raw === []) {
            throw new RuntimeException('Berkas kosong.');
        }

        $headers = self::normalizeHeaders(array_shift($raw));

        if ($headers === []) {
            throw new RuntimeException('Baris judul kolom tidak ditemukan pada baris pertama.');
        }

        $rows = [];

        foreach ($raw as $line) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($line[$index] ?? ''));
            }

            // Lewati baris yang seluruh selnya kosong — lazim muncul di akhir
            // berkas Excel akibat sel yang pernah tersentuh lalu dikosongkan.
            if (collect($row)->every(fn ($value) => $value === '')) {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('Tidak ada baris data setelah baris judul.');
        }

        return $rows;
    }

    /**
     * Menyeragamkan judul kolom agar pencocokan tidak bergantung pada
     * huruf besar/kecil, spasi ganda, atau tanda baca.
     *
     * "Base Unit of Measure" -> "base_unit_of_measure"
     * "No."                  -> "no"
     */
    public static function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';

        return trim($header, '_');
    }

    /** @return array<int, string> */
    private static function normalizeHeaders(array $line): array
    {
        $headers = [];

        foreach ($line as $index => $value) {
            $header = self::normalizeHeader((string) $value);

            if ($header !== '') {
                $headers[$index] = $header;
            }
        }

        return $headers;
    }
}
