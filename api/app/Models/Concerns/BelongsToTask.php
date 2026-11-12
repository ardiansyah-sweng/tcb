<?php

namespace App\Models\Concerns;

use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTask
{
    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
