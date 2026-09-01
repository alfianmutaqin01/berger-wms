<?php

namespace App\Support\Import;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\PackSize;
use App\Support\PalletCapacity;
use Illuminate\Support\Facades\DB;

/**
 * Mengimpor Master Produk dari ekspor ERP Berger.
 *
 * Kolom yang dikenali (judul dicocokkan setelah dinormalkan, sehingga
 * "Base Unit of Measure" dan "base unit of measure" sama saja):
 *   No. | Description | Product Code | Shade Code | Pack Code |
 *   Base Unit of Measure | Net Weight | Gross Weight | Unit Volume | Product Type
 *
 * Kolom "Inventory" pada ekspor SENGAJA DIABAIKAN — jumlah stok bukan data
 * master, melainkan hasil penjumlahan `inventory_stocks` per gudang/batch.
 */
class ProductImporter extends Importer
{
    protected function requiredHeaders(): array
    {
        return ['description', 'product_code', 'shade_code', 'pack_code'];
    }

    protected function keyColumn(): string
    {
        return 'sku';
    }

    protected function table(): string
    {
        return 'products';
    }

    /** Nama kolom sebagaimana tertulis di berkas ekspor ERP. */
    protected function columnLabels(): array
    {
        return [
            'sku' => 'No.',
            'name' => 'Description',
            'product_code' => 'Product Code',
            'shade_code' => 'Shade Code',
            'pack_code' => 'Pack Code',
            'uom' => 'Base Unit of Measure',
        ];
    }

    protected function existingKeys(): array
    {
        return Product::withTrashed()->pluck('sku')->all();
    }

    /**
     * Menyusun satu baris menjadi data siap simpan.
     *
     * @return array{key: string, label: string, data: array}|null
     */
    protected function mapRow(array $row): ?array
    {
        $name = $this->value($row, ['description', 'name']);
        $productCode = $this->value($row, ['product_code']);
        $shadeCode = $this->value($row, ['shade_code']);
        $packCode = $this->value($row, ['pack_code']);

        if (blank($name)) {
            $this->fail('Kolom Description kosong.');

            return null;
        }

        if (blank($productCode) || blank($shadeCode) || blank($packCode)) {
            $this->fail('Product Code, Shade Code, dan Pack Code wajib terisi.');

            return null;
        }

        // SKU dari berkas dipakai apa adanya bila ada; bila tidak, dibentuk
        // dari tiga kode. Ini menjaga SKU lama tetap utuh sekaligus tetap
        // bekerja untuk berkas yang tidak memuat kolom No.
        $sku = $this->value($row, ['no', 'sku', 'sapsku']);
        $sku = filled($sku)
            ? strtoupper($sku)
            : Product::buildSku($productCode, $shadeCode, $packCode);

        // Ukuran kemasan nominal dibaca dari nama produk ("20Ltr"), BUKAN dari
        // Unit Volume yang berisi volume isi sebenarnya (pail 20 L bisa 19.4 L).
        $pack = PackSize::parse($name);

        return [
            'key' => $sku,
            'label' => $name,
            'data' => [
                'name' => $name,
                'product_code' => $productCode,
                'shade_code' => $shadeCode,
                'pack_code' => $packCode,
                'category_id' => $this->resolveCategoryId($this->value($row, ['product_type', 'category'])),
                'uom' => $this->value($row, ['base_unit_of_measure', 'uom']) ?: 'PCS',
                'pack_size' => $pack['size'] ?? null,
                'pack_unit' => $pack['unit'] ?? null,
                'unit_volume' => $this->decimal($this->value($row, ['unit_volume'])),
                'net_weight' => $this->decimal($this->value($row, ['net_weight'])),
                'gross_weight' => $this->decimal($this->value($row, ['gross_weight'])),
                'max_qty_per_pallet' => PalletCapacity::resolve($pack['unit'] ?? null, $pack['size'] ?? null),
                'shelf_life_months' => 30,
                'stock_threshold_low' => 50,
                'is_active' => true,
            ],
        ];
    }

    protected function persist(string $key, array $data): bool
    {
        return DB::transaction(function () use ($key, $data) {
            $product = Product::withTrashed()->where('sku', $key)->first();

            if ($product) {
                // Impor ulang TIDAK menghidupkan kembali produk yang sengaja
                // dinonaktifkan Manager, dan tidak menimpa kapasitas palet yang
                // sudah diisi manual untuk ukuran di luar aturan gudang.
                unset($data['is_active']);

                if ($product->max_qty_per_pallet !== null && $data['max_qty_per_pallet'] === null) {
                    unset($data['max_qty_per_pallet']);
                }

                $product->update($data);

                return false;
            }

            Product::create($data + ['sku' => $key, 'created_by' => $this->actorId]);

            return true;
        });
    }

    /** Kategori dibuat otomatis bila belum ada, kecuali penanda ERP "Tidak ditemukan". */
    private function resolveCategoryId(?string $name): ?int
    {
        $name = trim((string) $name);

        // "Tidak ditemukan" adalah penanda bahwa pencarian kategori di ERP
        // gagal — bukan nama kategori. Dibiarkan kosong agar masalahnya
        // terlihat, bukan tersamarkan menjadi kategori palsu.
        if ($name === '' || strcasecmp($name, 'Tidak ditemukan') === 0) {
            return null;
        }

        return ProductCategory::firstOrCreate(['name' => $name], ['is_active' => true])->id;
    }
}
