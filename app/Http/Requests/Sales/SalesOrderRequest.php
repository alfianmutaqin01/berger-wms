<?php

namespace App\Http\Requests\Sales;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Support\OrderCutoff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Aturan isian form Buat Pesanan — docs/1 §6.5 F-OUT-01, docs/4 §3.3.2.
 *
 * Dipakai bersama oleh store() dan update() karena bentuk formulirnya sama
 * persis; yang membedakan hanya pesanan mana yang sedang ditulis.
 */
class SalesOrderRequest extends FormRequest
{
    /** Aksi yang diminta tombol: simpan draft atau submit. */
    public function wantsSubmit(): bool
    {
        return $this->input('action') === 'submit';
    }

    public function authorize(): bool
    {
        // Penjagaan sesungguhnya ada di middleware portal:sales dan pada
        // pemeriksaan kepemilikan di controller.
        return true;
    }

    public function rules(): array
    {
        $dokumen = config('wms.order_document');

        return [
            'action' => ['required', 'in:draft,submit'],
            // TIDAK ADA `warehouse_id` di sini. Gudang tidak lagi dipilih di
            // formulir — Sales terkunci ke gudang akunnya, dan controller
            // mengisinya lewat WarehouseScope::require(). Menerimanya dari
            // isian berarti menyediakan lagi kolom yang bisa dipalsukan untuk
            // memesan atas nama gudang lain.
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'payment_term_id' => ['required', 'integer', 'exists:payment_terms,id'],
            'order_source' => ['required', 'in:'.SalesOrder::SOURCE_MANUAL.','.SalesOrder::SOURCE_DOCUMENT],
            'notes' => ['nullable', 'string', 'max:1000'],

            // --- Metode dokumen ---
            'customer_po_number' => [
                'required_if:order_source,'.SalesOrder::SOURCE_DOCUMENT,
                'nullable', 'string', 'max:50',
            ],
            'document' => [
                'nullable', 'file',
                'mimes:'.implode(',', $dokumen['mimes']),
                'max:'.$dokumen['max_kb'],
            ],

            // --- Metode rincian ---
            'items' => ['array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'customer',
            'payment_term_id' => 'syarat pembayaran',
            'customer_po_number' => 'nomor PO customer',
            'document' => 'dokumen pesanan',
            'items' => 'item pesanan',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_po_number.required_if' => 'Nomor PO customer wajib diisi pada pesanan bermetode dokumen.',
            'items.*.qty.min' => 'Qty setiap item minimal 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->pastikanIsiSesuaiMetode($validator);
            $this->pastikanTidakAdaSkuGanda($validator);
            $this->pastikanBelumLewatCutoff($validator);
            $this->pastikanCustomerTercakup($validator);
        });
    }

    /**
     * Pelanggan harus berada dalam cakupan wilayah gudang Sales ini.
     *
     * Diperiksa DI SINI, bukan hanya dengan menyembunyikannya dari hasil
     * pencarian. Kolom customer mengirim id, dan id bisa diketik langsung ke
     * permintaan — daftar yang disaring adalah kenyamanan, bukan pengamanan.
     *
     * Pembatasannya KERAS (keputusan pemilik produk): pesanannya ditolak,
     * bukan diteruskan dengan peringatan.
     */
    private function pastikanCustomerTercakup(Validator $validator): void
    {
        $gudang = $this->user()?->warehouse;
        $customer = Customer::find($this->input('customer_id'), ['id', 'name', 'territory_code']);

        if ($gudang === null || $customer === null || $gudang->servesTerritory($customer->territory_code)) {
            return;
        }

        $validator->errors()->add('customer_id', sprintf(
            'Pelanggan %s berada di wilayah %s, yang tidak dilayani gudang %s. Pesanan ini harus dibuat dari gudang lain.',
            $customer->name,
            $customer->territory_code,
            $gudang->name
        ));
    }

    /**
     * Tiap metode punya isian wajibnya sendiri.
     *
     * Metode dokumen boleh tanpa rincian item — justru itu gunanya. Tapi
     * metode rincian tanpa satu pun item bukan pesanan, dan pesanan dokumen
     * tanpa berkas tidak bisa diproses Logistik.
     */
    private function pastikanIsiSesuaiMetode(Validator $validator): void
    {
        if ($this->input('order_source') === SalesOrder::SOURCE_DOCUMENT) {
            // Saat mengubah draft, berkas lama tetap berlaku bila tidak
            // diganti — karena itu keberadaannya diperiksa di controller,
            // yang tahu pesanan mana yang sedang disunting.
            return;
        }

        if (blank($this->input('items'))) {
            $validator->errors()->add('items', 'Tambahkan minimal satu item pesanan.');
        }
    }

    /**
     * Satu SKU hanya boleh sekali dalam satu pesanan.
     *
     * Dua baris SKU yang sama membuat pemeriksaan stok saat approval
     * menghitung ketersediaan yang sama dua kali, sehingga sistem menjanjikan
     * barang yang sebenarnya tidak ada.
     */
    private function pastikanTidakAdaSkuGanda(Validator $validator): void
    {
        $ids = collect($this->input('items', []))->pluck('product_id')->filter();

        if ($ids->count() !== $ids->unique()->count()) {
            $validator->errors()->add('items', 'Ada produk yang dimasukkan lebih dari sekali. Gabungkan qty-nya menjadi satu baris.');
        }
    }

    /**
     * Cutoff HANYA mengunci submit, bukan simpan draft (PRD §7.5 + docs/4
     * §3.3.2). Kalau keduanya dikunci, Sales yang menerima pesanan sore hari
     * tidak punya tempat menyimpan apa pun.
     */
    private function pastikanBelumLewatCutoff(Validator $validator): void
    {
        if ($this->wantsSubmit() && ! OrderCutoff::isOpen()) {
            $validator->errors()->add('action', OrderCutoff::closedMessage());
        }
    }
}
