<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A valid state transition (from_state -> to_state). The table is the single
 * source of transition rules; TaskStateMachine reads it (CLAUDE.md rule 2).
 * Rows come from AllowedTransitionsSeeder.
 */
class AllowedTransition extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
