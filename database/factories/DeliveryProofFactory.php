<?php

namespace Database\Factories;

use App\Models\DeliveryProof;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryProof>
 */
class DeliveryProofFactory extends Factory
{
    public function definition(): array
    {
        return [
            'path' => 'delivery-proofs/'.fake()->uuid().'.jpg',
            'original_name' => 'surat-jalan.jpg',
            'size' => 250_000,
            'mime' => 'image/jpeg',
            'status' => DeliveryProof::STATUS_PENDING,
            'uploaded_at' => now(),
        ];
    }

    public function verified(int $userId): static
    {
        return $this->state(fn () => [
            'status' => DeliveryProof::STATUS_VERIFIED,
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);
    }

    public function rejected(int $userId, string $alasan = 'Tanda tangan tidak terlihat.'): static
    {
        return $this->state(fn () => [
            'status' => DeliveryProof::STATUS_REJECTED,
            'rejection_reason' => $alasan,
            'verified_by' => $userId,
            'verified_at' => now(),
        ]);
    }
}
