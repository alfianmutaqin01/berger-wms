<?php

namespace App\Http\Requests\Wms;

use App\Models\Customer;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\OrderCutoff;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Isian form Buat Pesanan jalur internal (Admin/Manager).
 *
 * BENTUKNYA SAMA DENGAN MILIK SALES, TETAPI DUA KOLOM LEBIH — dan justru
 * kedua kolom itulah yang paling perlu dijaga:
 *
 *   sales_user_id  atas nama siapa pesanan ini dicatat.
 *   warehouse_id   gudang mana yang memprosesnya (hanya untuk akun lintas
 *                  gudang; Manager terkunci ke gudangnya sendiri).
 *
 * KENAPA GUDANG MUNCUL DI SINI PADAHAL DI FORM SALES DIHAPUS. Di sana ia
 * dihapus karena Sales SELALU punya gudang, sehingga kolomnya cuma jadi
 * tempat memalsukan tujuan pesanan. Super Admin tidak punya gudang sama
 * sekali (`warehouse_id` NULL berarti "tidak dibatasi"), jadi tanpa kolom
 * ini WarehouseScope::require() akan menolaknya 403 — fiturnya mati justru
 * untuk peran yang paling berhak memakainya. Nilainya tetap dijepit
 * WarehouseScope, jadi Manager yang mengetik gudang lain tetap ditolak.
 *
 * BATAS JAM CUTOFF TETAP BERLAKU, dan itu keputusan yang perlu diketahui:
 * cutoff ada supaya gudang bisa merencanakan picking hari itu, bukan untuk
 * mendisiplinkan Sales. Membebaskan jalur internal darinya berarti membuka
 * cara mengacaukan rencana picking yang tidak pernah disepakati siapa pun —
 * dan karena jalur ini tidak melewati Sales, tidak ada yang akan protes.
 */
class InternalOrderRequest extends FormRequest
{
    public function wantsSubmit(): bool
    {
        return $this->input('action') === 'submit';
    }

    public function authorize(): bool
    {
        return Permission::allows($this->user(), Permission::OUTBOUND_ORDER_INTERNAL);
    }

    /** Gudang yang benar-benar dipakai, sudah dijepit ke kewenangan. */
    public function gudangTujuan(): ?int
    {
        $batas = WarehouseScope::boundary($this->user());

        // Yang dibatasi TIDAK pernah membaca isian: apa pun yang diketik,
        // gudangnya tetap gudangnya sendiri.
        return $batas ?? ($this->filled('warehouse_id') ? (int) $this->input('warehouse_id') : null);
    }

    public function rules(): array
    {
        $dokumen = config('wms.order_document');

        return [
            'action' => ['required', 'in:draft,submit'],
            'sales_user_id' => ['required', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'payment_term_id' => ['required', 'integer', 'exists:payment_terms,id'],
            'order_source' => ['required', 'in:'.SalesOrder::SOURCE_MANUAL.','.SalesOrder::SOURCE_DOCUMENT],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Alasan WAJIB. Ini satu-satunya jalur yang membuat pesanan atas
            // nama orang lain, dan pemilik produk memutuskan pembuatnya boleh
            // menyetujuinya sendiri — jadi tidak ada mata kedua di rantainya.
            // Alasan yang harus diketik memaksa yang memakainya menyatakan
            // kenapa, dan kalimat itulah yang dibaca orang saat suatu hari
            // pesanan ini dipersoalkan.
            'reason' => ['required', 'string', 'min:10', 'max:500'],

            'customer_po_number' => [
                'required_if:order_source,'.SalesOrder::SOURCE_DOCUMENT,
                'nullable', 'string', 'max:50',
            ],
            'document' => [
                'nullable', 'file',
                'mimes:'.implode(',', $dokumen['mimes']),
                'max:'.$dokumen['max_kb'],
            ],

            'items' => ['array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sales_user_id' => 'Sales',
            'warehouse_id' => 'gudang',
            'customer_id' => 'customer',
            'payment_term_id' => 'syarat pembayaran',
            'customer_po_number' => 'nomor PO customer',
            'document' => 'dokumen pesanan',
            'items' => 'item pesanan',
            'reason' => 'alasan',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Tuliskan alasan pesanan ini dibuat dari sini, bukan oleh Sales-nya sendiri.',
            'reason.min' => 'Alasannya terlalu singkat untuk berguna saat dibaca lagi berbulan-bulan kemudian.',
            'customer_po_number.required_if' => 'Nomor PO customer wajib diisi pada pesanan bermetode dokumen.',
            'items.*.qty.min' => 'Qty setiap item minimal 1.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->pastikanGudangJelas($validator);
            $this->pastikanSalesnyaSah($validator);
            $this->pastikanIsiSesuaiMetode($validator);
            $this->pastikanTidakAdaSkuGanda($validator);
            $this->pastikanBelumLewatCutoff($validator);
            $this->pastikanCustomerTercakup($validator);
        });
    }

    private function pastikanGudangJelas(Validator $validator): void
    {
        $gudang = $this->gudangTujuan();

        if ($gudang === null) {
            $validator->errors()->add('warehouse_id', 'Pilih gudang yang akan memproses pesanan ini.');

            return;
        }

        // Dijepit, bukan dipercaya. Isian yang menunjuk gudang lain ditolak
        // di sini juga, bukan hanya disembunyikan dari daftar pilihannya.
        if (! WarehouseScope::allows($gudang, $this->user())) {
            $validator->errors()->add('warehouse_id', 'Gudang itu bukan wewenang Anda.');
        }
    }

    /**
     * Atas nama siapa pesanan ini boleh dicatat.
     *
     * TIGA SYARAT, dan ketiganya pernah jadi lubang di tempat lain:
     *   - harus berperan Sales. Mencatatkan pesanan atas nama Operator
     *     membuat pesanan itu tidak pernah muncul di layar siapa pun, karena
     *     Operator tidak punya Portal Sales.
     *   - harus AKTIF. Akun nonaktif tidak akan pernah membuka pemberitahuan
     *     maupun mengunggah bukti Surat Jalan.
     *   - harus di gudang yang sama. Sales Pekanbaru yang dicatat memesan
     *     untuk gudang Karawang tidak akan melihat pesanannya sendiri.
     */
    private function pastikanSalesnyaSah(Validator $validator): void
    {
        // Aturan dasar field ini sudah gagal (bukan angka, array, dll.):
        // mencarinya ke basis data hanya mengubah pesan validasi menjadi
        // galat 500. Temuan SQA.
        if ($validator->errors()->hasAny(['sales_user_id', 'warehouse_id'])) {
            return;
        }

        $gudang = $this->gudangTujuan();
        $sales = User::with('role')->find($this->input('sales_user_id'));

        if ($sales === null || $gudang === null) {
            return;
        }

        if (! $sales->hasRole(Role::SALES)) {
            $validator->errors()->add('sales_user_id',
                'Pesanan hanya bisa dicatat atas nama akun Sales — akun lain tidak punya Portal Sales untuk membukanya.');

            return;
        }

        if (! $sales->is_active) {
            $validator->errors()->add('sales_user_id',
                'Akun '.$sales->full_name.' sedang nonaktif, jadi tidak akan pernah membuka pesanannya maupun mengunggah bukti Surat Jalan.');

            return;
        }

        if ((int) $sales->warehouse_id !== $gudang) {
            $validator->errors()->add('sales_user_id', sprintf(
                '%s bukan Sales gudang %s, jadi pesanan ini tidak akan muncul di daftarnya.',
                $sales->full_name,
                Warehouse::find($gudang)?->name ?? 'itu',
            ));
        }
    }

    /**
     * Pelanggan harus dilayani gudang TUJUAN.
     *
     * Diperiksa terhadap gudang tujuan, bukan gudang si pembuat: Super Admin
     * tidak punya gudang sama sekali, dan memakai gudangnya sendiri akan
     * membuat pemeriksaan ini selalu lolos untuk peran yang paling berhak
     * dicurigai.
     */
    private function pastikanCustomerTercakup(Validator $validator): void
    {
        // Aturan dasar field ini sudah gagal (bukan angka, array, dll.):
        // mencarinya ke basis data hanya mengubah pesan validasi menjadi
        // galat 500. Temuan SQA.
        if ($validator->errors()->hasAny(['customer_id', 'warehouse_id'])) {
            return;
        }

        $gudang = Warehouse::find($this->gudangTujuan());
        $customer = Customer::find($this->input('customer_id'), ['id', 'name', 'territory_code']);

        if ($gudang === null || $customer === null || $gudang->servesTerritory($customer->territory_code)) {
            return;
        }

        $validator->errors()->add('customer_id', sprintf(
            'Pelanggan %s berada di wilayah %s, yang tidak dilayani gudang %s.',
            $customer->name,
            $customer->territory_code,
            $gudang->name,
        ));
    }

    private function pastikanIsiSesuaiMetode(Validator $validator): void
    {
        if ($this->input('order_source') === SalesOrder::SOURCE_DOCUMENT) {
            return;
        }

        if (blank($this->input('items'))) {
            $validator->errors()->add('items', 'Tambahkan minimal satu item pesanan.');
        }
    }

    /**
     * SKU ganda ditolak.
     *
     * sales_order_details punya kunci unik (sales_order_id, product_id), jadi
     * dua baris SKU yang sama bukan sekadar tidak rapi — penyimpanannya gagal
     * dengan galat basis data yang tidak bisa dibaca siapa pun.
     */
    private function pastikanTidakAdaSkuGanda(Validator $validator): void
    {
        $ids = collect($this->input('items', []))->pluck('product_id')->filter();

        if ($ids->count() !== $ids->unique()->count()) {
            $validator->errors()->add('items', 'Ada produk yang dimasukkan dua kali. Gabungkan qty-nya jadi satu baris.');
        }
    }

    private function pastikanBelumLewatCutoff(Validator $validator): void
    {
        if ($this->wantsSubmit() && ! OrderCutoff::isOpen()) {
            $validator->errors()->add('action', OrderCutoff::closedMessage());
        }
    }
}
