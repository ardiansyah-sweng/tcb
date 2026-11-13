<?php

namespace App\Services\Recommendation;

/**
 * The user's scoring settings: weights (users.score_weights), working hours
 * (users.work_hours, e.g. {"mon": ["08:00", "17:00"], ...}) and timezone.
 */
final readonly class ScoringProfile
{
    /** Same defaults as users.score_weights in the schema (SRS bagian 6). */
    public const DEFAULT_WEIGHTS = [
        'goal' => 0.30,
        'urgency' => 0.25,
        'priority' => 0.20,
        'aging' => 0.15,
        'fit' => 0.10,
    ];

    /** Same defaults as users.work_hours in the schema. */
    public const DEFAULT_WORK_HOURS = [
        'mon' => ['08:00', '17:00'],
        'tue' => ['08:00', '17:00'],
        'wed' => ['08:00', '17:00'],
        'thu' => ['08:00', '17:00'],
        'fri' => ['08:00', '17:00'],
    ];

    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    /**
     * @param  array<string, mixed>  $weights
     * @param  array<string, mixed>  $workHours
     */
    public function __construct(
        public array $weights,
        public array $workHours,
        public string $timezone,
    ) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULT_WEIGHTS, self::DEFAULT_WORK_HOURS, self::DEFAULT_TIMEZONE);
    }
}
