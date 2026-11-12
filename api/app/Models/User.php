<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestampTz;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The owner of all TCB data, with their settings (WIP limit, score weights,
 * work hours, time zone). next_task_seq is maintained by the task_key trigger.
 */
#[Fillable([
    'name', 'email', 'password', 'timezone', 'wip_limit', 'score_weights',
    'work_hours', 'default_deadline_offset_minutes',
])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, UsesTimestampTz;

    /** No remember_token column: authentication uses Sanctum tokens only. */
    protected $rememberTokenName = '';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'wip_limit' => 'integer',
            'score_weights' => 'array',
            'work_hours' => 'array',
            'default_deadline_offset_minutes' => 'integer',
            'next_task_seq' => 'integer',
        ];
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'owner_id');
    }

    /** @return HasMany<Objective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class, 'owner_id');
    }
}
