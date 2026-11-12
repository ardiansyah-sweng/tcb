<?php

namespace Database\Factories;

use App\Models\Objective;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Objective>
 */
class ObjectiveFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'title' => fake()->sentence(3),
            'period_start' => '2026-08-01',
            'period_end' => '2026-12-31',
        ];
    }
}
