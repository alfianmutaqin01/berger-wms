<?php

namespace App\Http\Requests\Wms;

use App\Models\SalesOrder;
use App\Models\User;
use App\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Menerima pesanan (Fase 6 tahap 1).
 *
 * NOMOR SO WAJIB DAN UNIK. Nomor itu didapat Logistik dari sistem BC saat
 * pesanan dimasukkan ke sana; nomor yang terulang berarti pesanan ini
 * BELUM benar-benar masuk BC dan Logistik sedang memakai ulang nomor
 * pesanan lain. Keunikannya juga dijaga indeks unik di database — dua
 * Logistik yang menekan Terima pada detik yang sama sama-sama lolos
 * pemeriksaan di sini sebelum salah satunya menyimpan.
 *
 * QTY DISETUJUI BOLEH MELEBIHI STOK, TAPI TIDAK BOLEH MELEBIHI PESANAN.
 * Yang pertama disengaja: barang bisa sudah ada di gudang tetapi belum
 * di-putaway, dan pesanan tidak boleh tertahan karenanya. Yang kedua
 * dilarang karena itu bukan lagi partial fulfillment melainkan mengirim
 * barang yang tidak diminta — dan `sales_order_details` punya
 * CHECK (qty_approved <= qty_ordered) yang akan menolaknya sebagai galat
 * mentah bila lolos sampai ke database.
 */
class AcceptSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hak fiturnya ditegakkan middleware can:outbound.approval pada route.
        // Yang diperiksa di sini hak atas OBJEKNYA: pesanan gudang lain harus
        // dijawab 403, dan jawaban itu tidak boleh menunggu isian formulir
        // dinyatakan sah lebih dulu — validasi berjalan sesudah authorize().
        $order = $this->route('order');

        return WarehouseScope::allows($order?->warehouse_id, $this->user());
    }

    public function rules(): array
    {
        return [
            // Keunikannya TIDAK ditulis sebagai rule bawaan lagi: sejak ada
            // penggabungan invoice, "sudah dipakai" bukan lagi jawaban
            // tunggal. Pemeriksaannya ada di tolakNomorSoBermasalah(), yang
            // bisa membedakan pemakaian ulang yang sah dari yang keliru.
            'bc_so_number' => ['required', 'string', 'max:50'],
            'approval_note' => ['nullable', 'string', 'max:1000'],

            // Pesanan tambahan yang sengaja berbagi nomor SO dengan pesanan
            // yang sudah ada — satu invoice, dua pengiriman.
            'gabung_invoice' => ['nullable', 'boolean'],
            'merge_with_order_id' => [
                'required_if_accepted:gabung_invoice',
                'nullable', 'integer', 'exists:sales_orders,id',
            ],

            'item' => ['required', 'array', 'min:1', 'max:500'],
            'item.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'item.*.qty_approved' => ['required', 'integer', 'min:0', 'max:999999'],
            // Hanya dipakai untuk pesanan bermetode dokumen, yang barisnya
            // memang belum ada. Pada metode rincian nilainya diabaikan —
            // qty pesanan milik Sales tidak boleh diubah Logistik.
            'item.*.qty_ordered' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'bc_so_number.required' => 'Nomor SO dari sistem BC wajib diisi sebelum pesanan bisa diterima.',
            'merge_with_order_id.required_if_accepted' => 'Pesanan yang akan digabung belum dipilih.',
            'item.required' => 'Rincian item belum diisi. Tempelkan daftar dari sistem BC lebih dulu.',
            'item.min' => 'Rincian item belum diisi. Tempelkan daftar dari sistem BC lebih dulu.',
        ];
    }

    /**
     * Aturan yang butuh melihat pesanan yang sedang dinilai, jadi tidak bisa
     * ditulis sebagai rule biasa.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $order = $this->pesanan();

            if ($order === null) {
                return;
            }

            $item = $this->input('item', []);

            if (! is_array($item)) {
                return;
            }

            $this->tolakProdukGanda($v, $item);
            $this->tolakMelebihiPesanan($v, $order, $item);
            $this->tolakSeluruhnyaNol($v, $item);
            $this->tolakNomorSoBermasalah($v, $order);
        });
    }

    /**
     * Aturan nomor SO — inti perubahan setelah temuan lapangan.
     *
     * Nomor SO unik SELAMA PESANANNYA MASIH HIDUP, bukan selamanya. Ada dua
     * cara nomor yang sama sah dipakai lagi:
     *
     *   1. Pemegang lamanya sudah DIBATALKAN — nomornya sudah dikosongkan
     *      OrderCanceller, jadi pencarian di bawah tidak akan menemukannya
     *      sama sekali dan tidak ada yang perlu dilakukan di sini.
     *   2. Ini PESANAN TAMBAHAN untuk pelanggan yang sama, digabung ke satu
     *      invoice — harus dinyatakan eksplisit lewat `gabung_invoice`.
     *
     * PELANGGAN YANG SAMA adalah pembeda yang menentukan. Nomor SO yang sama
     * muncul di pelanggan BERBEDA hampir selalu berarti Logistik belum
     * benar-benar memasukkan pesanan ini ke BC dan sedang memakai ulang nomor
     * pesanan orang lain — itulah yang aturan ini ada untuk menangkap, dan
     * itu tetap ditolak keras.
     *
     * Pencariannya DIBATASI GUDANG PENGGUNA. Selain sejalan dengan
     * pembatasan gudang, ini mencegah pesan galat membocorkan nama pelanggan
     * gudang lain kepada orang yang tidak berhak melihatnya.
     */
    private function tolakNomorSoBermasalah(Validator $v, SalesOrder $order): void
    {
        $nomor = trim((string) $this->input('bc_so_number'));

        if ($nomor === '') {
            return;
        }

        $pemegang = self::pemegangNomorSo($nomor, $this->user(), $order->id);

        // Nomor bebas: entah belum pernah dipakai, atau pemegang lamanya
        // sudah dibatalkan sehingga nomornya kembali ke kolam.
        if ($pemegang === null) {
            if ($this->boolean('gabung_invoice')) {
                $v->errors()->add(
                    'bc_so_number',
                    'Nomor SO ini tidak sedang dipakai pesanan mana pun, jadi tidak ada yang bisa digabung. '.
                    'Hapus centang "gabung invoice" lalu terima seperti biasa.'
                );
            }

            return;
        }

        if (! $this->boolean('gabung_invoice')) {
            $v->errors()->add('bc_so_number', $this->pesanNomorTerpakai($order, $pemegang));

            return;
        }

        if ((int) $this->input('merge_with_order_id') !== $pemegang->id) {
            $v->errors()->add(
                'merge_with_order_id',
                "Pesanan yang dipilih untuk digabung bukan pemegang nomor SO {$nomor}. Muat ulang halaman lalu coba lagi."
            );

            return;
        }

        if ($pemegang->customer_id !== $order->customer_id) {
            $v->errors()->add('bc_so_number', $this->pesanNomorTerpakai($order, $pemegang));
        }
    }

    /** Pesan tolak yang membedakan dua sebab, karena tindak lanjutnya berbeda. */
    private function pesanNomorTerpakai(SalesOrder $order, SalesOrder $pemegang): string
    {
        if ($pemegang->customer_id !== $order->customer_id) {
            return sprintf(
                'Nomor SO ini sedang dipakai pesanan %s milik pelanggan LAIN (%s). '.
                'Penggabungan invoice hanya berlaku untuk pelanggan yang sama — pastikan pesanan ini benar-benar sudah dimasukkan ke sistem BC.',
                $pemegang->order_number,
                $pemegang->customer?->name ?? 'tidak diketahui'
            );
        }

        return sprintf(
            'Nomor SO ini sedang dipakai pesanan %s milik pelanggan yang sama. '.
            'Kalau ini pesanan tambahan yang digabung ke satu invoice, centang "Gabung ke invoice pesanan ini" lebih dulu.',
            $pemegang->order_number
        );
    }

    /**
     * Pesanan yang SEDANG memegang nomor SO ini, bila ada.
     *
     * Hanya pesanan INDUK yang dianggap pemegang: pesanan tambahan memang
     * sengaja menumpang nomor yang sama, dan mengembalikannya di sini akan
     * membuat penggabungan berantai yang tidak pernah dimaksudkan.
     *
     * Dipakai bersama oleh validasi dan endpoint pemeriksaan di layar
     * penerimaan, supaya keduanya tidak pernah menjawab berbeda.
     */
    public static function pemegangNomorSo(string $nomor, ?User $user, ?int $kecualiOrderId = null): ?SalesOrder
    {
        return WarehouseScope::apply(SalesOrder::query(), $user)
            ->with('customer:id,code,name')
            ->whereRaw('UPPER(bc_so_number) = ?', [strtoupper(trim($nomor))])
            ->whereNull('so_merged_into_id')
            ->when($kecualiOrderId, fn ($q, $id) => $q->whereKeyNot($id))
            ->first();
    }

    /**
     * Rincian siap simpan.
     *
     * @return list<array{product_id:int, qty_approved:int, qty_ordered:int}>
     */
    public function itemData(): array
    {
        $order = $this->pesanan();
        $qtyPesanan = $order?->details->pluck('qty_ordered', 'product_id') ?? collect();

        return collect($this->validated('item'))
            ->map(fn (array $baris) => [
                'product_id' => (int) $baris['product_id'],
                'qty_approved' => (int) $baris['qty_approved'],
                // Baris yang SUDAH ADA memakai qty pesanan milik Sales;
                // yang dikirim form hanya berlaku untuk baris baru (metode
                // dokumen). Tanpa pembedaan ini, Logistik bisa menaikkan
                // qty_ordered lalu menyetujui lebih dari yang Sales pesan.
                'qty_ordered' => (int) ($qtyPesanan[(int) $baris['product_id']] ?? $baris['qty_ordered']),
            ])
            ->values()
            ->all();
    }

    private function pesanan(): ?SalesOrder
    {
        $order = $this->route('order');

        return $order instanceof SalesOrder ? $order->loadMissing('details') : null;
    }

    /** @param array<int, mixed> $item */
    private function tolakProdukGanda(Validator $v, array $item): void
    {
        $id = array_map(fn ($baris) => is_array($baris) ? ($baris['product_id'] ?? null) : null, $item);
        $id = array_filter($id, fn ($n) => $n !== null);

        if (count($id) !== count(array_unique($id))) {
            // sales_order_details punya unique(sales_order_id, product_id).
            // Tanpa pemeriksaan ini, dua baris SKU sama lolos ke database dan
            // gagal sebagai galat constraint mentah — dan lebih buruk lagi,
            // ketersediaan SKU itu terhitung dua kali saat alokasi.
            $v->errors()->add('item', 'Ada SKU yang muncul lebih dari sekali. Gabungkan qty-nya menjadi satu baris.');
        }
    }

    /** @param array<int, mixed> $item */
    private function tolakMelebihiPesanan(Validator $v, SalesOrder $order, array $item): void
    {
        $qtyPesanan = $order->details->pluck('qty_ordered', 'product_id');

        foreach ($item as $i => $baris) {
            if (! is_array($baris)) {
                continue;
            }

            $diminta = $qtyPesanan[(int) ($baris['product_id'] ?? 0)] ?? null;

            // Baris baru (metode dokumen) belum punya pembanding — qty
            // pesanannya justru berasal dari tempelan ini.
            if ($diminta === null) {
                continue;
            }

            if ((int) ($baris['qty_approved'] ?? 0) > $diminta) {
                $v->errors()->add(
                    "item.{$i}.qty_approved",
                    "Qty disetujui tidak boleh melebihi qty pesanan ({$diminta})."
                );
            }
        }
    }

    /** @param array<int, mixed> $item */
    private function tolakSeluruhnyaNol(Validator $v, array $item): void
    {
        $total = array_sum(array_map(
            fn ($baris) => is_array($baris) ? (int) ($baris['qty_approved'] ?? 0) : 0,
            $item
        ));

        if ($total < 1) {
            // Menerima pesanan yang seluruh itemnya nol bukan "menerima" —
            // tidak ada yang akan dipicking maupun dikirim. Yang dimaksud
            // Logistik hampir pasti menolak, dan itu punya tombolnya sendiri
            // beserta alasan yang terbaca Sales.
            $v->errors()->add(
                'item',
                'Tidak ada satu pun item dengan qty di atas nol. Kalau memang tidak bisa dipenuhi, gunakan tombol Tolak.'
            );
        }
    }

    /**
     * Nama kolom yang terbaca manusia di pesan galat.
     *
     * Dinomori dari 1 mengikuti nomor baris di layar; tanpa ini pesannya
     * berbunyi "item.0.qty approved" dan Logistik harus menghitung sendiri
     * baris ke berapa yang bermasalah.
     */
    public function attributes(): array
    {
        $label = [];

        foreach (array_keys((array) $this->input('item', [])) as $i) {
            $label["item.{$i}.qty_approved"] = 'qty baris '.((int) $i + 1);
            $label["item.{$i}.qty_ordered"] = 'qty pesanan baris '.((int) $i + 1);
            $label["item.{$i}.product_id"] = 'SKU baris '.((int) $i + 1);
        }

        return $label;
    }
}
