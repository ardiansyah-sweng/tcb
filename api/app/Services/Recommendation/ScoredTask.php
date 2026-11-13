<?php

namespace App\Services\Recommendation;

/**
 * Result of scoring one eligible task.
 *
 * breakdown holds the raw components (0–1, rounded to 4 decimals) keyed
 * goal, urgency, priority, aging; F (fit) is absent until Rilis 2.
 * stale = more than 14 days in Ready ("kerjakan, tunda, atau batalkan").
 */
final readonly class ScoredTask
{
    /**
     * @param  array{goal: float, urgency: float, priority: float, aging: float}  $breakdown
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $taskId,
        public float $score,
        public array $breakdown,
        public array $reasons,
        public bool $stale,
    ) {}
}
