<?php

namespace App\Services\Recommendation;

use DateTimeImmutable;

/**
 * Everything the scorer needs to know about one task, already loaded.
 *
 * Built by the caller (R1-14) from the database, so RecommendationScorer
 * itself never does I/O. A task without a Key Result (orphan) has both
 * keyResult* fields null.
 */
final readonly class ScoringCandidate
{
    public function __construct(
        public int $taskId,
        public string $state = 'ready',
        public bool $deleted = false,
        public int $openDependencies = 0,
        public int $openSubtasks = 0,
        public ?float $keyResultWeight = null,
        public ?float $keyResultProgress = null,
        public int $priority = 2,
        public ?DateTimeImmutable $deadlineAt = null,
        public ?int $estimateMinutes = null,
        public ?DateTimeImmutable $readySince = null,
    ) {}

    public function isOrphan(): bool
    {
        return $this->keyResultWeight === null;
    }
}
