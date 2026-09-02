<?php

namespace App\Http\Requests\Wms;

use App\Models\Location;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Menambahkan baris stok yang belum pernah tercatat sistem — PRD §6.4
 * F-INV-02 diperluas, akses Manager & Super Admin saja.
 *
 * MENGAPA PINTU INI ADA
 * ---------------------
 * F-INV-02 yang lama hanya bisa MENGOREKSI baris yang sudah ada ("80 jadi
 * 75"). Sistem ini dipasang di gudang yang SUDAH BERJALAN: banyak barang
 * fisiknya ada di rak tetapi belum pernah masuk sistem sama sekali,
 * sehingga tidak ada baris untuk dikoreksi. Tanpa pintu ini, satu-satunya
 * jalan adalah memalsukan dokumen inbound.
 *
 * BATCH, TANGGAL PRODUKSI, DAN LOKASI TETAP WAJIB — tidak ada kelonggaran
 * untuk "stok lama". Ketiganya adalah tumpuan FIFO, sweep kedaluwarsa, dan
 * Stok DDP. Kalau boleh kosong, seluruh mesin kedaluwarsa yang dibangun di
 * Fase 4 melemah diam-diam, dan justru di kondisi ini dampaknya paling
 * besar karena hampir seluruh stok gudang masuk lewat pintu ini.
 * Keputusan pemilik produk, tercatat di rencana pembangunan Fase 6.
 */
class StoreInventoryStockRequest extends FormRequest
{
    /** Diisi withValidator() supaya controller tidak mencarinya lagi. */
    public ?Product $produk = null;

    public ?Location $lokasi = null;

    public function authorize(): bool
    {
        // Otorisasi ditegakkan middleware can:inventory.adjust pada route.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => filled($this->input('sku')) ? strtoupper(trim($this->input('sku'))) : null,
            'location_code' => filled($this->input('location_code'))
                ? strtoupper(trim($this->input('location_code')))
                : null,
            'batch_no' => filled($this->input('batch_no')) ? strtoupper(trim($this->input('batch_no'))) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50'],
            'location_code' => ['required', 'string', 'max:20'],
            'batch_no' => ['required', 'string', 'max:50'],
            // Tanggal produksi di masa depan membuat tanggal kedaluwarsa
            // ikut meleset ke depan, dan batch itu akan selalu tampak
            // paling muda sehingga FIFO tidak pernah mengeluarkannya.
            'production_date' => ['required', 'date', 'before_or_equal:today'],
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'production_date.before_or_equal' => 'Tanggal produksi tidak boleh di masa depan.',
            'reason.min' => 'Alasan terlalu singkat. Tulis minimal 5 karakter agar koreksi ini bisa diaudit.',
        ];
    }

    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'location_code' => 'kode lokasi',
            'batch_no' => 'nomor batch',
            'production_date' => 'tanggal produksi',
            'reason' => 'alasan penambahan',
        ];
    }

    /** Memeriksa SKU dan lokasi ke database, sekaligus menyimpan hasilnya. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $produk = Product::where('is_active', true)
                ->whereRaw('UPPER(sku) = ?', [$this->input('sku')])
                ->first();

            if ($produk === null) {
                $v->errors()->add('sku', 'SKU tidak ditemukan atau produknya tidak aktif.');
            }

            $lokasi = Location::active()
                ->whereRaw('UPPER(code) = ?', [$this->input('location_code')])
                ->first();

            if ($lokasi === null) {
                $v->errors()->add('location_code', 'Kode lokasi tidak ditemukan atau lokasinya tidak aktif.');
            }

            $this->produk = $produk;
            $this->lokasi = $lokasi;
        });
    }
}
