<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;

/**
 * The application clock for code that takes a ClockInterface (e.g.
 * RecommendationScorer). Backed by Carbon, so tests can move it with
 * travelTo() / Carbon::setTestNow().
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }
}
