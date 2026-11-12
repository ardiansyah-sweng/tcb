<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\UsesTimestampTz;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Task Control Block.
 *
 * task_key, state_changed_at, and ready_since are maintained by triggers.
 * State may only change through TransitionService (CLAUDE.md rule 1), so the
 * state columns are not mass assignable.
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToOwner, HasFactory, SoftDeletes, UsesTimestampTz;

    /**
     * All task states, in lifecycle order (mirrors the CHECK on tasks.state).
     * This is the list of states only; the allowed transitions live in the
     * allowed_transitions table (CLAUDE.md rule 2).
     */
    public const STATES = ['new', 'ready', 'running', 'waiting', 'suspended', 'done', 'cancelled'];

    protected $guarded = [
        'id', 'task_key', 'state', 'state_reason', 'state_changed_at', 'ready_since',
    ];

    protected function casts(): array
    {
        return [
            'state_changed_at' => 'datetime',
            'ready_since' => 'datetime',
            'priority' => 'integer',
            'deadline_at' => 'datetime',
            'estimate_minutes' => 'integer',
            'unit_done' => 'float',
            'unit_total' => 'float',
            'time_spent_minutes' => 'integer',
            'switch_count' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * {task} in a URL is a numeric ID or a task_key such as TCB-31 (any case).
     * The owner and soft-delete scopes still apply; anything else is a 404.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $value = (string) $value;

        // Up to 18 digits always fits in a bigint, so PostgreSQL never rejects the cast.
        if (preg_match('/^\d{1,18}$/', $value)) {
            return $this->newQuery()->whereKey((int) $value)->first();
        }

        if (preg_match('/^TCB-\d+$/i', $value)) {
            return $this->newQuery()->where('task_key', strtoupper($value))->first();
        }

        return null;
    }

    /** @return BelongsTo<KeyResult, $this> */
    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(KeyResult::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /** @return HasMany<Task, $this> */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /** @return HasMany<ContextSnapshot, $this> */
    public function contextSnapshots(): HasMany
    {
        return $this->hasMany(ContextSnapshot::class);
    }

    /** @return HasMany<StateTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(StateTransition::class);
    }

    /** @return HasMany<WorkSession, $this> */
    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    /**
     * Tasks or external blockers this task waits for.
     *
     * @return HasMany<Dependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(Dependency::class);
    }

    /**
     * Dependencies this task still waits for, oldest first.
     *
     * @return HasMany<Dependency, $this>
     */
    public function openDependencies(): HasMany
    {
        return $this->dependencies()->where('status', 'open')->orderBy('id');
    }

    /**
     * Dependencies of other tasks that wait for this one.
     *
     * @return HasMany<Dependency, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(Dependency::class, 'depends_on_task_id');
    }

    /** @return HasMany<CalendarLink, $this> */
    public function calendarLinks(): HasMany
    {
        return $this->hasMany(CalendarLink::class);
    }
}
