<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Model;

/** Opened when a task enters Running and closed when it leaves (TransitionService). */
class WorkSession extends Model
{
    use BelongsToOwner, BelongsToTask, UsesTimestampTz;

    public $timestamps = false;

    /** duration_minutes is a generated column. */
    protected $guarded = ['id', 'duration_minutes'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }
}
