<?php

namespace Database\Factories;

use App\Models\KeyResult;
use App\Models\Objective;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the owner explicitly (->for($user, 'owner')) so it matches the objective's owner.
 *
 * @extends Factory<KeyResult>
 */
class KeyResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'objective_id' => Objective::factory(),
            'owner_id' => fn (array $attributes) => Objective::find($attributes['objective_id'])->owner_id,
            'title' => fake()->sentence(3),
        ];
    }
}
