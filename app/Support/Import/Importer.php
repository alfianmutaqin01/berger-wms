<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * Kerangka bersama impor master data dari berkas spreadsheet.
 *
 * Alurnya dua tahap, disengaja: `preview()` hanya membaca dan memeriksa tanpa
 * menyentuh database, lalu `import()` benar-benar menyimpan. Karena impor
 * bersifat memperbarui data yang sudah ada, satu berkas yang keliru bisa
 * menimpa data yang benar — pratinjau memberi kesempatan membatalkan sebelum
 * itu terjadi.
 */
abstract class Importer
{
    protected ?int $actorId = null;

    /** Pesan galat baris yang sedang diproses. */
    private ?string $rowError = null;

    public function __construct(?int $actorId = null)
    {
        $this->actorId = $actorId;
    }

    /** Judul kolom (sudah dinormalkan) yang wajib ada di berkas. */
    abstract protected function requiredHeaders(): array;

    /** Nama kolom kunci untuk mencocokkan data lama, contoh: 'sku'. */
    abstract protected function keyColumn(): string;

    /** @return array{key: string, label: string, data: array}|null */
    abstract protected function mapRow(array $row): ?array;

    /** @return bool TRUE bila baris baru dibuat, FALSE bila memperbarui data lama */
    abstract protected function persist(string $key, array $data): bool;

    /**
     * Memeriksa berkas tanpa menyimpan apa pun.
     *
     * @return array{rows: list<array>, summary: array{total:int, baru:int, perbarui:int, gagal:int}}
     */
    public function preview(string $path): array
    {
        $rows = SpreadsheetReader::rows($path);

        $this->assertHeaders($rows[0]);

        $existing = $this->existingKeys();
        $seen = [];
        $result = [];
        $summary = ['total' => 0, 'baru' => 0, 'perbarui' => 0, 'gagal' => 0];

        foreach ($rows as $index => $row) {
            $summary['total']++;
            $lineNumber = $index + 2; // +1 karena judul, +1 karena Excel mulai dari 1

            $this->rowError = null;
            $mapped = $this->mapRow($row);

            if ($mapped === null) {
                $summary['gagal']++;
                $result[] = [
                    'line' => $lineNumber,
                    'key' => $this->value($row, [$this->keyColumn(), 'no', 'code']) ?: '—',
                    'label' => $this->value($row, ['description', 'name']) ?: '—',
                    'status' => 'gagal',
                    'message' => $this->rowError ?? 'Baris tidak dapat dibaca.',
                ];

                continue;
            }

            // Kunci ganda di dalam satu berkas: baris terakhir yang menang saat
            // impor, jadi diberi tahu di pratinjau agar tidak mengagetkan.
            $duplicate = isset($seen[$mapped['key']]);
            $seen[$mapped['key']] = true;

            $isNew = ! in_array($mapped['key'], $existing, true);
            $isNew ? $summary['baru']++ : $summary['perbarui']++;

            $result[] = [
                'line' => $lineNumber,
                'key' => $mapped['key'],
                'label' => $mapped['label'],
                'status' => $isNew ? 'baru' : 'perbarui',
                'message' => $duplicate ? 'Kunci ini muncul lebih dari sekali di berkas; baris terakhir yang dipakai.' : null,
            ];
        }

        return ['rows' => $result, 'summary' => $summary];
    }

    /**
     * Menyimpan isi berkas ke database.
     *
     * @return array{baru:int, perbarui:int, gagal:int}
     */
    public function import(string $path): array
    {
        $rows = SpreadsheetReader::rows($path);

        $this->assertHeaders($rows[0]);

        $summary = ['baru' => 0, 'perbarui' => 0, 'gagal' => 0];

        foreach ($rows as $row) {
            $this->rowError = null;
            $mapped = $this->mapRow($row);

            if ($mapped === null) {
                $summary['gagal']++;

                continue;
            }

            $this->persist($mapped['key'], $mapped['data'])
                ? $summary['baru']++
                : $summary['perbarui']++;
        }

        return $summary;
    }

    /** Menandai baris yang sedang diproses sebagai gagal, dengan alasannya. */
    protected function fail(string $message): void
    {
        $this->rowError = $message;
    }

    /** Mengambil nilai kolom pertama yang tersedia dari beberapa nama alternatif. */
    protected function value(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($row[$candidate]) && $row[$candidate] !== '') {
                return $row[$candidate];
            }
        }

        return null;
    }

    /**
     * Angka Excel berbahasa Indonesia memakai koma sebagai pemisah desimal
     * ("4,05"). Nilai 0 dari ERP diperlakukan sebagai "tidak diisi", karena
     * pada ekspor mereka kolom kosong memang tertulis 0.
     */
    protected function decimal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (! is_numeric($normalized) || (float) $normalized == 0.0) {
            return null;
        }

        return $normalized;
    }

    /** Daftar kunci yang sudah ada di database, untuk membedakan baru vs perbarui. */
    abstract protected function existingKeys(): array;

    private function assertHeaders(array $firstRow): void
    {
        $missing = array_diff($this->requiredHeaders(), array_keys($firstRow));

        if ($missing !== []) {
            throw new RuntimeException(
                'Kolom wajib tidak ditemukan: '.implode(', ', $missing).
                '. Pastikan baris pertama berkas berisi judul kolom.'
            );
        }
    }
}
