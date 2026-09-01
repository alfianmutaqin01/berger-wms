<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserSession>
 */
class UserSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'session_id' => Str::random(64),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'last_activity_at' => now(),
            'created_at' => now(),
        ];
    }
}
