<?php

namespace App\Http\Requests\Wms;

use App\Models\BillingPayment;
use App\Models\CustomerBilling;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Konfirmasi lunas satu atau beberapa tagihan (F-BILL-02).
 *
 * TANPA NOMINAL — keputusan pemilik produk. Yang diminta hanya tanggal bukti
 * bayar diterima, metodenya, dan nomor rujukan. Nomor WAJIB untuk giro: giro
 * bisa ditolak bank berminggu-minggu kemudian, dan satu-satunya cara
 * menemukan tagihan mana yang harus dibuka lagi adalah nomornya.
 */
class ConfirmBillingPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Pagar gudang per tagihan dijalankan Piutang::lunasi() di dalam kunci,
        // sekaligus dengan pemeriksaan "sudah lunas".
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_ids' => ['required', 'array', 'min:1', 'max:100'],
            'billing_ids.*' => ['integer', 'distinct', Rule::exists(CustomerBilling::class, 'id')],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(BillingPayment::METHOD_LABELS))],
            'reference' => ['nullable', 'string', 'max:60', 'required_if:method,'.BillingPayment::METHOD_GIRO],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'billing_ids' => 'tagihan',
            'paid_on' => 'tanggal bukti bayar diterima',
            'method' => 'metode pelunasan',
            'reference' => 'nomor giro / referensi',
            'notes' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'billing_ids.required' => 'Pilih minimal satu tagihan yang dilunasi.',
            'reference.required_if' => 'Nomor giro wajib diisi untuk pelunasan dengan giro.',
            'paid_on.before_or_equal' => 'Tanggal bukti bayar tidak boleh di masa depan.',
        ];
    }
}
