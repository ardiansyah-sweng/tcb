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

    /** @return BelongsTo<Task, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id')->withTrashed();
    }
}
