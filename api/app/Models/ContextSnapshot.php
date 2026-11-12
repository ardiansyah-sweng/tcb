<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Model;

/** The "registers" saved when a task leaves Running. */
class ContextSnapshot extends Model
{
    use BelongsToOwner, BelongsToTask, UsesTimestampTz;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_done' => 'float',
            'links' => 'array',
        ];
    }
}
