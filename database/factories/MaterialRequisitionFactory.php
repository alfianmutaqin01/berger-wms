<?php

namespace Database\Factories;

use App\Models\MaterialRequisition;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MaterialRequisition>
 */
class MaterialRequisitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Nomor acak, BUKAN lewat DocumentNumber: factory dipakai di luar
            // transaksi dan penomoran sungguhan menuntut baris urutannya
            // dikunci. Yang diuji di sini isinya, bukan penomorannya.
            'mrf_number' => 'MRF'.now()->format('ym').fake()->unique()->numerify('###'),
            'warehouse_id' => Warehouse::factory(),
            'requested_by' => User::factory(),
            'department_id' => null,
            'department_name' => 'Produksi',
            'request_type' => MaterialRequisition::TYPE_REPROSES,
            'purpose' => 'Reproses DDP batch lama menjadi warna Off White.',
            'status' => MaterialRequisition::STATUS_PENDING_APPROVAL,
            'approver_name' => 'Pak Gandhi',
            'approver_phone' => '628123456789',
            'approval_token' => Str::random(64),
            'notify_status' => MaterialRequisition::NOTIFY_PENDING,
        ];
    }

    /** Sudah disetujui atasan; yang ditunggu Logistik. */
    public function menungguLogistik(): static
    {
        return $this->state(fn () => [
            'status' => MaterialRequisition::STATUS_PENDING_LOGISTICS,
            'approved_at' => now(),
        ]);
    }

    public function ditolakAtasan(): static
    {
        return $this->state(fn () => [
            'status' => MaterialRequisition::STATUS_REJECTED_APPROVAL,
            'approver_rejected_at' => now(),
            'approver_rejection_reason' => 'Belum perlu bulan ini.',
        ]);
    }

    public function jenis(string $jenis): static
    {
        return $this->state(fn () => ['request_type' => $jenis]);
    }
}
