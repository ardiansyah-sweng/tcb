<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only transition log. Rows are written by the trg_tasks_log_transition
 * trigger; the database rejects updates and deletes.
 */
class StateTransition extends Model
{
    use BelongsToOwner, BelongsToTask, UsesTimestampTz;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
