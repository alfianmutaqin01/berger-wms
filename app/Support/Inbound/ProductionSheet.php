<?php

namespace App\Support\Inbound;

use App\Models\Product;
use App\Support\Import\SpreadsheetReader;
use App\Support\PalletCapacity;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Membaca berkas produksi dari Tim Produksi dan memecahnya menjadi palet.
 *
 * Berkas berasal dari sistem produksi Berger dan memuat kolom A–L, tetapi
 * HANYA A–E yang dibaca:
 *
 *   A  No.                  -> nomor order produksi (RMO26080294)
 *   B  Source No.           -> SKU produk
 *   C  Description          -> nama produk (untuk ditampilkan di pratinjau)
 *   D  Quantity             -> total qty yang diproduksi
 *   E  QC Number            -> nomor batch
 *
 * Kolom F dan seterusnya (jadwal, status, user, routing) sengaja diabaikan —
 * tidak ada yang dibutuhkan proses gudang.
 *
 * Kelas ini TIDAK menyentuh basis data untuk menulis; ia hanya membaca master
 * produk lalu mengembalikan rencana. Pemisahan ini membuat pratinjau benar-benar
 * aman: apa yang tampil di layar dihitung dengan jalur kode yang sama persis
 * dengan yang nanti disimpan.
 */
class ProductionSheet
{
    /** Nama kolom yang diterima, sudah dinormalkan SpreadsheetReader. */
    private const COL_ORDER_NO = ['no', 'no_', 'nomor', 'production_order_no'];

    private const COL_SKU = ['source_no', 'sku', 'source_number', 'item_no'];

    private const COL_DESCRIPTION = ['description', 'deskripsi', 'nama'];

    private const COL_QTY = ['quantity', 'qty', 'q', 'jumlah', 'total_qty'];

    private const COL_BATCH = ['qc_number', 'qc_no', 'batch', 'batch_no', 'batch_number'];

    /**
     * Menyusun rencana inbound dari sebuah berkas.
     *
     * @return array{
     *     rows: list<array>,
     *     summary: array{total:int, siap:int, gagal:int, palet:int, qty:int}
     * }
     *
     * @throws RuntimeException bila berkas tidak terbaca atau kolom wajib hilang
     */
    public function plan(string $path): array
    {
        $raw = SpreadsheetReader::rows($path);

        $this->assertHeaders($raw[0]);

        // Produk diambil sekali untuk seluruh berkas, bukan satu query per
        // baris — berkas produksi harian bisa berisi puluhan baris.
        $skus = collect($raw)
            ->map(fn ($row) => strtoupper((string) $this->value($row, self::COL_SKU)))
            ->filter()
            ->unique();

        $products = Product::whereIn('sku', $skus)->get()->keyBy('sku');

        $rows = [];
        $summary = ['total' => 0, 'siap' => 0, 'gagal' => 0, 'palet' => 0, 'qty' => 0];

        foreach ($raw as $index => $row) {
            $summary['total']++;
            $planned = $this->planRow($row, $index + 2, $products);

            if ($planned['status'] === 'gagal') {
                $summary['gagal']++;
            } else {
                $summary['siap']++;
                $summary['palet'] += count($planned['pallets']);
                $summary['qty'] += $planned['qty'];
            }

            $rows[] = $planned;
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @param Collection<string, Product> $products */
    private function planRow(array $row, int $line, $products): array
    {
        $orderNo = $this->value($row, self::COL_ORDER_NO);
        $sku = strtoupper((string) $this->value($row, self::COL_SKU));
        $description = $this->value($row, self::COL_DESCRIPTION);
        $batch = $this->value($row, self::COL_BATCH);
        $qtyRaw = $this->value($row, self::COL_QTY);

        $base = [
            'line' => $line,
            'production_order_no' => $orderNo,
            'sku' => $sku ?: '—',
            'description' => $description ?: '—',
            'batch_no' => $batch,
            'qty' => 0,
            'product_id' => null,
            'pallets' => [],
            'capacity' => null,
            'status' => 'gagal',
            'message' => null,
        ];

        if ($sku === '') {
            return ['message' => 'Kolom Source No. (SKU) kosong.'] + $base;
        }

        if (blank($batch)) {
            return ['message' => 'Kolom QC Number (batch) kosong.'] + $base;
        }

        // Angka dari Excel bisa terbaca "235" atau "235.0"; keduanya sah.
        $qty = (int) round((float) str_replace(',', '.', (string) $qtyRaw));

        if ($qty <= 0) {
            return ['message' => 'Quantity harus lebih dari 0.'] + $base;
        }

        $base['qty'] = $qty;

        $product = $products->get($sku);

        if (! $product) {
            // Master produk sengaja TIDAK diisi otomatis dari berkas produksi:
            // salah ketik di sini akan menjadi produk permanen yang sulit
            // ditelusuri. Baris ditolak agar dilengkapi lewat Master Produk.
            return ['message' => 'SKU belum terdaftar di Master Produk.'] + $base;
        }

        $base['product_id'] = $product->id;
        $base['description'] = $product->name;
        $base['capacity'] = $product->max_qty_per_pallet;

        if (! $product->max_qty_per_pallet) {
            return [
                'message' => 'Kapasitas palet produk ini belum diisi di Master Produk.',
            ] + $base;
        }

        return [
            'pallets' => PalletCapacity::split($qty, $product->max_qty_per_pallet),
            'status' => 'siap',
        ] + $base;
    }

    private function value(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($row[$candidate]) && $row[$candidate] !== '') {
                return trim($row[$candidate]);
            }
        }

        return null;
    }

    private function assertHeaders(array $firstRow): void
    {
        $headers = array_keys($firstRow);

        $required = [
            'Source No.' => self::COL_SKU,
            'Quantity' => self::COL_QTY,
            'QC Number' => self::COL_BATCH,
        ];

        $missing = [];

        foreach ($required as $label => $candidates) {
            if (array_intersect($candidates, $headers) === []) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Kolom wajib tidak ditemukan: '.implode(', ', $missing).
                '. Pastikan baris pertama berkas berisi judul kolom.'
            );
        }
    }
}
