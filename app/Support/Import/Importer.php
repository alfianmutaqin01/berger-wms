<?php

namespace App\Support\Import;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
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

    /** Batas panjang kolom, dibaca sekali dari skema lalu disimpan. */
    private ?array $columnLimits = null;

    public function __construct(?int $actorId = null)
    {
        $this->actorId = $actorId;
    }

    /** Judul kolom (sudah dinormalkan) yang wajib ada di berkas. */
    abstract protected function requiredHeaders(): array;

    /** Nama kolom kunci untuk mencocokkan data lama, contoh: 'sku'. */
    abstract protected function keyColumn(): string;

    /** Tabel tujuan — dipakai untuk membaca batas panjang kolom dari skema. */
    abstract protected function table(): string;

    /**
     * Nama kolom berkas untuk tiap kolom database, dipakai di pesan galat.
     *
     * Tanpa ini pesan menyebut nama kolom database ("contact_name"), padahal
     * yang perlu diperbaiki pengguna adalah kolom di berkas Excel ("Contact").
     *
     * @return array<string, string>
     */
    protected function columnLabels(): array
    {
        return [];
    }

    /**
     * Keterangan tambahan setelah impor selesai, di luar hitungan baris.
     *
     * Sebagian impor MENGUBAH hal lain selain barisnya sendiri — stok awal
     * menyedot barang ke pesanan yang menunggu, impor Surat Jalan menemukan
     * dokumen yang tidak berpasangan. Perubahan semacam itu tidak muncul di
     * angka "sekian ditambahkan, sekian diperbarui", dan impor yang diam-diam
     * mengubah sesuatu di tempat lain adalah persis yang paling sulit
     * ditelusuri belakangan.
     */
    public function catatanTambahan(): ?string
    {
        return null;
    }

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

            $mapped = $this->prepareRow($row);

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
     * Satu baris bermasalah TIDAK boleh menghentikan sisa berkas. Tiap
     * `persist()` adalah transaksinya sendiri, jadi galat yang dibiarkan naik
     * akan meninggalkan impor separuh jalan: baris sebelumnya sudah tersimpan,
     * baris sesudahnya tidak pernah dicoba, dan pengguna hanya melihat pesan
     * SQLSTATE mentah. Persis itu yang terjadi pada impor 1.863 pelanggan yang
     * berhenti di baris 1.731. Kini galat basis data dicatat sebagai kegagalan
     * BARIS ITU saja lalu dilaporkan.
     *
     * Yang ditangkap hanya DUA: RowRejected (penolakan yang disengaja
     * importer, pesannya sudah ditulis untuk pengguna) dan QueryException
     * (kesalahan basis data memang milik datanya). Galat jenis lain tetap
     * dibiarkan naik karena itu cacat program, bukan cacat berkas, dan
     * menyembunyikannya justru mempersulit.
     *
     * @return array{baru:int, perbarui:int, gagal:int, galat: list<string>}
     */
    public function import(string $path): array
    {
        $rows = SpreadsheetReader::rows($path);

        $this->assertHeaders($rows[0]);

        $summary = ['baru' => 0, 'perbarui' => 0, 'gagal' => 0, 'galat' => []];

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2;
            $mapped = $this->prepareRow($row);

            if ($mapped === null) {
                $summary['gagal']++;
                $summary['galat'][] = "Baris {$lineNumber}: ".($this->rowError ?? 'Baris tidak dapat dibaca.');

                continue;
            }

            try {
                $this->persist($mapped['key'], $mapped['data'])
                    ? $summary['baru']++
                    : $summary['perbarui']++;
            } catch (RowRejected $e) {
                // Penolakan yang DISENGAJA importer, dengan alasan yang sudah
                // ditulis untuk dibaca pengguna.
                $summary['gagal']++;
                $summary['galat'][] = "Baris {$lineNumber} ({$mapped['key']}): {$e->getMessage()}.";
            } catch (QueryException $e) {
                $summary['gagal']++;
                $summary['galat'][] = "Baris {$lineNumber} ({$mapped['key']}): ditolak basis data.";
                report($e);
            }
        }

        return $summary;
    }

    /**
     * Memetakan satu baris lalu memeriksa panjangnya terhadap skema tabel.
     *
     * @return array{key: string, label: string, data: array}|null
     */
    private function prepareRow(array $row): ?array
    {
        $this->rowError = null;

        $mapped = $this->mapRow($row);

        if ($mapped === null) {
            return null;
        }

        $tooLong = $this->tooLong($mapped['data'] + [$this->keyColumn() => $mapped['key']]);

        if ($tooLong !== null) {
            $this->fail($tooLong);

            return null;
        }

        return $mapped;
    }

    /**
     * Mencari nilai yang melebihi panjang kolomnya, bila ada.
     *
     * Batasnya DIBACA DARI SKEMA, bukan ditulis ulang di sini. Daftar panjang
     * yang disalin tangan pasti berselisih dengan migrasi cepat atau lambat,
     * dan selisih itu muncul sebagai galat SQLSTATE mentah di tengah impor —
     * tepat bentuk kegagalan yang hendak dicegah pemeriksaan ini.
     */
    private function tooLong(array $data): ?string
    {
        foreach ($this->columnLimits() as $column => $limit) {
            $value = $data[$column] ?? null;

            if (! is_string($value) || mb_strlen($value) <= $limit) {
                continue;
            }

            $label = $this->columnLabels()[$column] ?? $column;

            return sprintf(
                'Kolom %s terlalu panjang: %d karakter, maksimum %d. Isi: "%s".',
                $label,
                mb_strlen($value),
                $limit,
                mb_strimwidth($value, 0, 40, '…')
            );
        }

        return null;
    }

    /**
     * Panjang maksimum tiap kolom VARCHAR pada tabel tujuan.
     *
     * @return array<string, int>
     */
    private function columnLimits(): array
    {
        if ($this->columnLimits !== null) {
            return $this->columnLimits;
        }

        $limits = [];

        foreach (Schema::getColumns($this->table()) as $column) {
            // "character varying(25)" -> 25. Sengaja hanya tipe teks
            // berpanjang-tetap: "timestamp(0)" juga memuat angka dalam kurung,
            // tetapi angka itu presisi detik, bukan batas panjang.
            if (preg_match('/^(?:character varying|character|varchar|char|bpchar)\((\d+)\)$/i', trim((string) $column['type']), $match)) {
                $limits[$column['name']] = (int) $match[1];
            }
        }

        return $this->columnLimits = $limits;
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
