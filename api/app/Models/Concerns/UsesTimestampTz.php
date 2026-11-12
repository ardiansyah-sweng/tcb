<?php

namespace App\Models\Concerns;

/**
 * TCB stores times as timestamptz (CLAUDE.md rule 8). Eloquent's default format
 * ('Y-m-d H:i:s') drops the UTC offset, so '2026-11-14 09:00+07:00' would be
 * saved as 09:00 UTC. Writing the offset keeps the instant correct; reads
 * parse PostgreSQL's '...+00' output.
 */
trait UsesTimestampTz
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }
}
