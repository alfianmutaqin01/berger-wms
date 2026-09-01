<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'IDI'.fake()->unique()->numberBetween(10000, 99999),
            'ship_to_code' => (string) fake()->numberBetween(1061600001, 1061699999),
            'name' => 'PT '.strtoupper(fake()->words(2, true)),
            'phone' => '62'.fake()->numerify('8##########'),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'address' => 'JL. '.strtoupper(fake()->streetName()).' NO. '.fake()->numberBetween(1, 99),
            'address_2' => strtoupper(fake()->city()),
            'territory_code' => 'PROJECT',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Pelanggan yang belum terdaftar di ERP. */
    public function withoutShipToCode(): static
    {
        return $this->state(fn () => ['ship_to_code' => null]);
    }
}
