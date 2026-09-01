<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        return [
            // Nomor asli dari pembangkit yang sama dengan yang dipakai
            // aplikasi — supaya test ikut menguji keunikan nomornya.
            'order_number' => DB::transaction(fn () => DocumentNumber::forSalesOrder()),
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'warehouse_id' => Warehouse::factory(),
            'payment_term_id' => fn () => PaymentTerm::firstOrCreate(
                ['code' => 'cash'],
                ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
            )->id,
            'status' => SalesOrder::STATUS_DRAFT,
            'order_source' => SalesOrder::SOURCE_MANUAL,
            'submitted_at' => null,
        ];
    }

    /** Sudah dikirim ke Logistik: terkunci dari perubahan Sales. */
    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => SalesOrder::STATUS_PENDING,
            'submitted_at' => now(),
        ]);
    }

    /** Bermetode dokumen: berkasnya wajib ada (check constraint di DB). */
    public function fromDocument(): static
    {
        return $this->state(fn () => [
            'order_source' => SalesOrder::SOURCE_DOCUMENT,
            'customer_po_number' => 'PO-CUST-'.fake()->numerify('####'),
            'document_path' => 'sales-orders/contoh.pdf',
            'document_name' => 'po-customer.pdf',
            'document_size' => 1024,
            'document_mime' => 'application/pdf',
        ]);
    }
}
