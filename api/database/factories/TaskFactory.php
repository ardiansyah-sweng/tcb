<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Only the title is required (input-minimal principle); everything else uses
 * database defaults. task_key is assigned by trigger, so call fresh() after
 * create() to read it.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'title' => fake()->sentence(4),
        ];
    }
}
