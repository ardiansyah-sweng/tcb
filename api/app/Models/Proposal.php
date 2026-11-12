<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\BelongsToTask;
use App\Models\Concerns\UsesTimestampTz;
use Illuminate\Database\Eloquent\Model;

/** A suggested transition (from the calendar or the system) awaiting the user's decision. */
class Proposal extends Model
{
    use BelongsToOwner, BelongsToTask, UsesTimestampTz;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }
}
