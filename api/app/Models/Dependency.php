<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A task waits for another task or for an external blocker (exactly one). */
class Dependency extends Model
{
    use BelongsToOwner, BelongsToTask, UsesTimestampTz;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * {dependency} in a URL is a numeric ID. The owner scope applies, and a
     * dependency of a soft-deleted task is hidden like the task itself.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        // Up to 18 digits always fits in a bigint, so PostgreSQL never rejects the cast.
        if (! preg_match('/^\d{1,18}$/', (string) $value)) {
            return null;
        }

        return $this->newQuery()
            ->whereKey((int) $value)
            ->whereIn('task_id', Task::query()->select('id'))
            ->first();
    }

    public function isExternal(): bool
    {
        return $this->depends_on_task_id === null;
    }

    /** @return BelongsTo<Task, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id')->withTrashed();
    }
}
