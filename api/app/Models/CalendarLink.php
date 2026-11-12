<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Model;

/** Focus block, deadline event, or deadline anchor event linked to a task (Release 2). */
class CalendarLink extends Model
{
    use BelongsToOwner, BelongsToTask, UsesTimestampTz;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'offset_minutes' => 'integer',
            'event_deleted' => 'boolean',
        ];
    }
}
