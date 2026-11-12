<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Every TCB table carries owner_id (CLAUDE.md rule 7).
 *
 * While a user is signed in, all queries are limited to their rows and new
 * rows get their owner_id. Without a signed-in user (console commands, queued
 * jobs) there is no scope; such code must filter by owner itself.
 */
trait BelongsToOwner
{
    public static function bootBelongsToOwner(): void
    {
        static::addGlobalScope('owner', function (Builder $query) {
            if (Auth::hasUser()) {
                $query->where($query->qualifyColumn('owner_id'), Auth::id());
            }
        });

        static::creating(function ($model) {
            if ($model->owner_id === null && Auth::hasUser()) {
                $model->owner_id = Auth::id();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
