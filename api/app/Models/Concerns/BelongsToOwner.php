<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every TCB table carries owner_id (CLAUDE.md rule 7). The owner global scope
 * is added together with authentication (R1-03).
 */
trait BelongsToOwner
{
    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
