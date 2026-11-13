<?php

namespace App\Services\Recommendation;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Scores Ready tasks for the recommendation engine (SRS bagian 6, R1-13).
 *
 * Pure: no database, no global clock. The caller loads ScoringCandidates
 * and the user's ScoringProfile; "now" comes from the injected clock, so
 * the scorer can be tested and replaced on its own (NFR-10).
 *
 *   S = wG·G + wU·U + wP·P + wA·A   (F is not used in Rilis 1; the four
 *                                    remaining weights are renormalised)
 */
final class RecommendationScorer
{
    /** Components used in Rilis 1, in tie-break order for reasons. */
    public const COMPONENTS = ['goal', 'urgency', 'priority', 'aging'];

    /** Days of slack at which urgency drops to 0; also the aging horizon. */
    public const HORIZON_DAYS = 14;

    /** Tasks longer than this in Ready are stale. */
    public const STALE_AFTER_DAYS = 14;

    /** Estimate used when a task has none (SRS bagian 4, New → Ready). */
    public const DEFAULT_ESTIMATE_MINUTES = 60;

    private const SECONDS_PER_DAY = 86400;

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    private const MONTH_NAMES = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const PRIORITY_NAMES = [2 => 'sedang', 3 => 'tinggi', 4 => 'kritis'];

    public function __construct(private readonly ClockInterface $clock) {}

    /**
     * Ready, not deleted, no open dependency, and not a parent with open subtasks.
     */
    public function isEligible(ScoringCandidate $task): bool
    {
        return $task->state === 'ready'
            && ! $task->deleted
            && $task->openDependencies === 0
            && $task->openSubtasks === 0;
    }

    /**
     * Score the eligible candidates and return them best first.
     *
     * Ties (equal rounded score) go to the task that has waited longer in
     * Ready, then to the lower task id, so the order is deterministic.
     *
     * @param  iterable<ScoringCandidate>  $candidates
     * @return list<ScoredTask>
     */
    public function rank(iterable $candidates, ScoringProfile $profile, ?int $limit = null): array
    {
        $rows = [];
        foreach ($candidates as $candidate) {
            if ($this->isEligible($candidate)) {
                $rows[] = [$candidate, $this->score($candidate, $profile)];
            }
        }

        usort($rows, function (array $a, array $b): int {
            [$candidateA, $scoredA] = $a;
            [$candidateB, $scoredB] = $b;

            return [$scoredB->score, $this->waitingRank($candidateA), $candidateA->taskId]
                <=> [$scoredA->score, $this->waitingRank($candidateB), $candidateB->taskId];
        });

        $ranked = array_map(fn (array $row) => $row[1], $rows);

        return $limit === null ? $ranked : array_slice($ranked, 0, max(0, $limit));
    }

    /**
     * Score one task. Does not check eligibility; rank() does.
     */
    public function score(ScoringCandidate $task, ScoringProfile $profile): ScoredTask
    {
        $now = $this->clock->now();
        $timezone = $this->timezone($profile);
        $weights = $this->weights($profile);

        $components = [
            'goal' => $this->goal($task),
            'urgency' => $this->urgency($task, $profile, $now, $timezone),
            'priority' => $this->priority($task),
            'aging' => $this->aging($task, $now),
        ];

        $score = 0.0;
        foreach ($components as $name => $value) {
            $score += $weights[$name] * $value;
        }

        return new ScoredTask(
            taskId: $task->taskId,
            score: round(min(1.0, max(0.0, $score)), 4),
            breakdown: array_map(fn (float $value) => round($value, 4), $components),
            reasons: $this->reasons($task, $components, $weights, $now, $timezone),
            stale: $this->daysInReady($task, $now) > self::STALE_AFTER_DAYS,
        );
    }

    /**
     * The user's G, U, P, A weights, renormalised to sum to 1 (F dropped).
     * Missing or negative weights fall back to the default / 0; if nothing
     * is left, the defaults are used.
     *
     * @return array{goal: float, urgency: float, priority: float, aging: float}
     */
    public function weights(ScoringProfile $profile): array
    {
        $pick = function (array $source): array {
            $weights = [];
            foreach (self::COMPONENTS as $name) {
                $value = $source[$name] ?? ScoringProfile::DEFAULT_WEIGHTS[$name];
                $weights[$name] = is_numeric($value) ? max(0.0, (float) $value) : ScoringProfile::DEFAULT_WEIGHTS[$name];
            }

            return $weights;
        };

        $weights = $pick($profile->weights);
        if (array_sum($weights) <= 0) {
            $weights = $pick(ScoringProfile::DEFAULT_WEIGHTS);
        }

        $sum = array_sum($weights);

        return array_map(fn (float $weight) => $weight / $sum, $weights);
    }

    /** G = KR weight × (1 − KR progress); orphan = 0. */
    private function goal(ScoringCandidate $task): float
    {
        if ($task->isOrphan()) {
            return 0.0;
        }

        return $this->clamp($task->keyResultWeight * (1 - $this->clamp($task->keyResultProgress ?? 0.0)));
    }

    /**
     * U = 1 past the deadline; 0 without one; otherwise 1 − slack/14 (clamped),
     * where slack = working days left − estimate in working days.
     */
    private function urgency(ScoringCandidate $task, ScoringProfile $profile, DateTimeImmutable $now, DateTimeZone $timezone): float
    {
        if ($task->deadlineAt === null) {
            return 0.0;
        }
        if ($now >= $task->deadlineAt) {
            return 1.0;
        }

        // Never empty: falls back to the default work hours, so minutesPerDay > 0.
        $windows = $this->workWindows($profile);
        $minutesPerDay = $this->averageMinutesPerDay($windows);
        $estimate = $task->estimateMinutes ?? self::DEFAULT_ESTIMATE_MINUTES;

        // Beyond this many working minutes the slack is ≥ 14 days and U is 0 anyway.
        $enough = $estimate + self::HORIZON_DAYS * $minutesPerDay;
        $available = $this->workingMinutes($now, $task->deadlineAt, $windows, $timezone, $enough);

        $slackDays = ($available - $estimate) / $minutesPerDay;

        return $this->clamp(1 - $slackDays / self::HORIZON_DAYS);
    }

    /** P = (priority − 1) / 3. */
    private function priority(ScoringCandidate $task): float
    {
        return (max(1, min(4, $task->priority)) - 1) / 3;
    }

    /** A = min(1, days since ready_since / 14). */
    private function aging(ScoringCandidate $task, DateTimeImmutable $now): float
    {
        return min(1.0, $this->daysInReady($task, $now) / self::HORIZON_DAYS);
    }

    private function daysInReady(ScoringCandidate $task, DateTimeImmutable $now): float
    {
        if ($task->readySince === null) {
            return 0.0;
        }

        return max(0, $now->getTimestamp() - $task->readySince->getTimestamp()) / self::SECONDS_PER_DAY;
    }

    /** Earlier ready_since sorts first; tasks without one sort last. */
    private function waitingRank(ScoringCandidate $task): int
    {
        return $task->readySince?->getTimestamp() ?? PHP_INT_MAX;
    }

    /**
     * The two largest contributions (weight × component) as sentences.
     * Components that round to 0 or have weight 0 give no reason.
     *
     * @param  array<string, float>  $components
     * @param  array<string, float>  $weights
     * @return list<string>
     */
    private function reasons(ScoringCandidate $task, array $components, array $weights, DateTimeImmutable $now, DateTimeZone $timezone): array
    {
        $contributions = [];
        foreach (self::COMPONENTS as $name) {
            if (round($components[$name], 4) > 0 && $weights[$name] > 0) {
                $contributions[$name] = $weights[$name] * $components[$name];
            }
        }

        // arsort keeps the COMPONENTS order for equal contributions.
        arsort($contributions);

        $reasons = [];
        foreach (array_slice(array_keys($contributions), 0, 2) as $name) {
            $reasons[] = match ($name) {
                'goal' => $this->goalReason($task),
                'urgency' => $this->deadlineReason($task->deadlineAt, $now, $timezone),
                'priority' => 'Prioritas '.self::PRIORITY_NAMES[max(2, min(4, $task->priority))],
                'aging' => $this->agingReason($task, $now),
            };
        }

        return $reasons;
    }

    private function goalReason(ScoringCandidate $task): string
    {
        $weight = $task->keyResultWeight;
        $level = $weight >= 0.7 ? 'tinggi' : ($weight >= 0.4 ? 'sedang' : 'rendah');
        $percent = (int) floor(round($this->clamp($task->keyResultProgress ?? 0.0) * 100, 6));

        return "Key Result bobot {$level}, progres masih {$percent}%";
    }

    private function deadlineReason(DateTimeImmutable $deadline, DateTimeImmutable $now, DateTimeZone $timezone): string
    {
        $local = $deadline->setTimezone($timezone);
        $date = self::DAY_NAMES[(int) $local->format('w')].', '.$local->format('j').' '.self::MONTH_NAMES[(int) $local->format('n')];
        if ($local->format('Y') !== $now->setTimezone($timezone)->format('Y')) {
            $date .= ' '.$local->format('Y');
        }

        return ($now >= $deadline ? 'Lewat deadline ' : 'Deadline ').$date;
    }

    private function agingReason(ScoringCandidate $task, DateTimeImmutable $now): string
    {
        $days = (int) floor($this->daysInReady($task, $now));

        return $days < 1 ? 'Masuk Ready hari ini' : "Sudah {$days} hari di Ready";
    }

    /**
     * Working minutes between $from and $to, following the weekly windows in
     * the user's timezone. Stops counting once $stopAt is reached.
     *
     * @param  array<string, array{0: int, 1: int}>  $windows  day key => [start, end] in minutes after midnight
     */
    private function workingMinutes(DateTimeImmutable $from, DateTimeImmutable $to, array $windows, DateTimeZone $timezone, float $stopAt): float
    {
        $from = $from->setTimezone($timezone);
        $to = $to->setTimezone($timezone);
        $total = 0.0;

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $window = $windows[self::DAY_KEYS[(int) $day->format('w')]] ?? null;
            if ($window === null) {
                continue;
            }

            $start = max($from, $day->setTime(intdiv($window[0], 60), $window[0] % 60));
            $end = min($to, $day->setTime(intdiv($window[1], 60), $window[1] % 60));
            if ($end > $start) {
                $total += ($end->getTimestamp() - $start->getTimestamp()) / 60;
            }
            if ($total >= $stopAt) {
                break;
            }
        }

        return $total;
    }

    /**
     * Valid working windows from the profile, or the schema defaults if none.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function workWindows(ScoringProfile $profile): array
    {
        $parse = function (array $hours): array {
            $windows = [];
            foreach (self::DAY_KEYS as $key) {
                $range = $hours[$key] ?? null;
                if (! is_array($range) || count($range) !== 2) {
                    continue;
                }
                $start = $this->minutesOfDay(array_values($range)[0]);
                $end = $this->minutesOfDay(array_values($range)[1]);
                if ($start !== null && $end !== null && $end > $start) {
                    $windows[$key] = [$start, $end];
                }
            }

            return $windows;
        };

        return $parse($profile->workHours) ?: $parse(ScoringProfile::DEFAULT_WORK_HOURS);
    }

    /** "08:30" → 510; null when not a valid time of day (24:00 allowed). */
    private function minutesOfDay(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }
        $minutes = (int) $m[1] * 60 + (int) $m[2];

        return (int) $m[2] < 60 && $minutes <= 24 * 60 ? $minutes : null;
    }

    /** @param array<string, array{0: int, 1: int}> $windows */
    private function averageMinutesPerDay(array $windows): float
    {
        $total = array_sum(array_map(fn (array $w) => $w[1] - $w[0], $windows));

        return $total / count($windows);
    }

    private function timezone(ScoringProfile $profile): DateTimeZone
    {
        try {
            return new DateTimeZone($profile->timezone);
        } catch (\Exception) {
            return new DateTimeZone(ScoringProfile::DEFAULT_TIMEZONE);
        }
    }

    private function clamp(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
