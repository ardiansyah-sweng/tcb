<?php

use App\Services\Recommendation\RecommendationScorer;
use App\Services\Recommendation\ScoredTask;
use App\Services\Recommendation\ScoringCandidate;
use App\Services\Recommendation\ScoringProfile;
use Psr\Clock\ClockInterface;

/*
| Fixed clock: Friday 13 Nov 2026, 09:00 Asia/Jakarta (02:00 UTC).
| Default profile: Mon–Fri 08:00–17:00 (540 working minutes a day),
| weights goal .30, urgency .25, priority .20, aging .15 (fit unused → ÷ 0.90).
*/

const SCORER_NOW = '2026-11-13 09:00:00';

function scorerClock(string $localTime = SCORER_NOW): ClockInterface
{
    return new class(new DateTimeImmutable($localTime, new DateTimeZone('Asia/Jakarta'))) implements ClockInterface
    {
        public function __construct(private DateTimeImmutable $now) {}

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };
}

function scorer(string $localTime = SCORER_NOW): RecommendationScorer
{
    return new RecommendationScorer(scorerClock($localTime));
}

function jakarta(string $localTime): DateTimeImmutable
{
    return new DateTimeImmutable($localTime, new DateTimeZone('Asia/Jakarta'));
}

function scored(array $candidate, ?ScoringProfile $profile = null, string $now = SCORER_NOW): ScoredTask
{
    return scorer($now)->score(new ScoringCandidate(...$candidate), $profile ?? ScoringProfile::defaults());
}

describe('eligibility', function () {
    it('accepts a Ready task without blockers', function () {
        expect(scorer()->isEligible(new ScoringCandidate(taskId: 1)))->toBeTrue();
    });

    it('rejects tasks that are not Ready', function (string $state) {
        expect(scorer()->isEligible(new ScoringCandidate(taskId: 1, state: $state)))->toBeFalse();
    })->with(['new', 'running', 'waiting', 'suspended', 'done', 'cancelled']);

    it('rejects deleted tasks', function () {
        expect(scorer()->isEligible(new ScoringCandidate(taskId: 1, deleted: true)))->toBeFalse();
    });

    it('rejects tasks with open dependencies', function () {
        expect(scorer()->isEligible(new ScoringCandidate(taskId: 1, openDependencies: 1)))->toBeFalse();
    });

    it('rejects parents that still have open subtasks', function () {
        expect(scorer()->isEligible(new ScoringCandidate(taskId: 1, openSubtasks: 2)))->toBeFalse();
    });

    it('leaves ineligible tasks out of the ranking', function () {
        $ranked = scorer()->rank([
            new ScoringCandidate(taskId: 1, state: 'waiting'),
            new ScoringCandidate(taskId: 2, deleted: true),
            new ScoringCandidate(taskId: 3, openDependencies: 1),
            new ScoringCandidate(taskId: 4, openSubtasks: 1),
            new ScoringCandidate(taskId: 5),
        ], ScoringProfile::defaults());

        expect(array_map(fn (ScoredTask $s) => $s->taskId, $ranked))->toBe([5]);
    });
});

describe('component G (goal)', function () {
    it('is KR weight × (1 − KR progress)', function () {
        expect(scored(['taskId' => 1, 'keyResultWeight' => 0.8, 'keyResultProgress' => 0.25])->breakdown['goal'])
            ->toBe(0.6);
    });

    it('is 0 for an orphan task', function () {
        expect(scored(['taskId' => 1])->breakdown['goal'])->toBe(0.0);
    });

    it('is 0 when the Key Result is complete', function () {
        expect(scored(['taskId' => 1, 'keyResultWeight' => 1.0, 'keyResultProgress' => 1.0])->breakdown['goal'])
            ->toBe(0.0);
    });
});

describe('component U (urgency)', function () {
    it('is 0 without a deadline', function () {
        expect(scored(['taskId' => 1])->breakdown['urgency'])->toBe(0.0);
    });

    it('is 1 once the deadline has passed', function () {
        expect(scored(['taskId' => 1, 'deadlineAt' => jakarta('2026-11-13 08:59')])->breakdown['urgency'])
            ->toBe(1.0);
    });

    it('is 1 − slack/14, with slack in working days minus the estimate', function () {
        // Mon 9 Nov 08:00 → Fri 13 Nov 17:00 = 5 working days; estimate 540 min = 1 day; slack 4.
        $s = scored(
            ['taskId' => 1, 'deadlineAt' => jakarta('2026-11-13 17:00'), 'estimateMinutes' => 540],
            now: '2026-11-09 08:00',
        );

        expect($s->breakdown['urgency'])->toBe(round(1 - 4 / 14, 4));
    });

    it('counts only working hours, skipping the weekend', function () {
        // Fri 09:00 → Mon 16 Nov 17:00: 480 min Friday + 540 min Monday = 1020 min.
        // Estimate 60 min. slack = (1020 − 60) / 540 = 1.7778 days.
        $s = scored(['taskId' => 1, 'deadlineAt' => jakarta('2026-11-16 17:00'), 'estimateMinutes' => 60]);

        expect($s->breakdown['urgency'])->toBe(round(1 - (960 / 540) / 14, 4));
    });

    it('treats an empty estimate as 60 minutes', function () {
        $withDefault = scored(['taskId' => 1, 'deadlineAt' => jakarta('2026-11-16 17:00')]);
        $explicit = scored(['taskId' => 1, 'deadlineAt' => jakarta('2026-11-16 17:00'), 'estimateMinutes' => 60]);

        expect($withDefault->breakdown['urgency'])->toBe($explicit->breakdown['urgency']);
    });

    it('is 0 when the slack is 14 working days or more', function () {
        $s = scored(['taskId' => 1, 'deadlineAt' => jakarta('2027-03-01 17:00'), 'estimateMinutes' => 60]);

        expect($s->breakdown['urgency'])->toBe(0.0);
    });

    it('is capped at 1 when the estimate no longer fits before the deadline', function () {
        $s = scored(['taskId' => 1, 'deadlineAt' => jakarta('2026-11-13 12:00'), 'estimateMinutes' => 600]);

        expect($s->breakdown['urgency'])->toBe(1.0);
    });

    it('follows the user work hours and timezone', function () {
        // UTC user working 09:00–13:00 on Fridays only. Now = 02:00 UTC Friday.
        // Deadline Friday 13:00 UTC: 240 working minutes left, 240 per day → 1 day; estimate 60 → slack 0.75.
        $profile = new ScoringProfile(
            weights: ScoringProfile::DEFAULT_WEIGHTS,
            workHours: ['fri' => ['09:00', '13:00']],
            timezone: 'UTC',
        );
        $s = scored(
            ['taskId' => 1, 'deadlineAt' => new DateTimeImmutable('2026-11-13 13:00', new DateTimeZone('UTC')), 'estimateMinutes' => 60],
            $profile,
        );

        expect($s->breakdown['urgency'])->toBe(round(1 - 0.75 / 14, 4));
    });
});

describe('component P (priority)', function () {
    it('is (priority − 1) / 3', function (int $priority, float $expected) {
        expect(scored(['taskId' => 1, 'priority' => $priority])->breakdown['priority'])->toBe($expected);
    })->with([
        [1, 0.0],
        [2, 0.3333],
        [3, 0.6667],
        [4, 1.0],
    ]);
});

describe('component A (aging)', function () {
    it('is days since ready_since / 14', function () {
        $s = scored(['taskId' => 1, 'readySince' => jakarta('2026-11-04 09:00')]);

        expect($s->breakdown['aging'])->toBe(round(9 / 14, 4));
    });

    it('is capped at 1 after 14 days', function () {
        expect(scored(['taskId' => 1, 'readySince' => jakarta('2026-10-01 09:00')])->breakdown['aging'])->toBe(1.0);
    });

    it('is 0 without ready_since', function () {
        expect(scored(['taskId' => 1])->breakdown['aging'])->toBe(0.0);
    });
});

describe('weights and score', function () {
    it('drops F and renormalises G, U, P, A to sum to 1', function () {
        $weights = scorer()->weights(ScoringProfile::defaults());

        expect(array_keys($weights))->toBe(['goal', 'urgency', 'priority', 'aging'])
            ->and(array_sum($weights))->toEqualWithDelta(1.0, 1e-9)
            ->and($weights['goal'])->toEqualWithDelta(0.30 / 0.90, 1e-9)
            ->and($weights['aging'])->toEqualWithDelta(0.15 / 0.90, 1e-9);
    });

    it('scores Σ weight × component, rounded to 4 decimals', function () {
        // G = 0.7, U = 0, P = 1/3, A = 1/14.
        $s = scored([
            'taskId' => 1,
            'keyResultWeight' => 1.0,
            'keyResultProgress' => 0.3,
            'priority' => 2,
            'readySince' => jakarta('2026-11-12 09:00'),
        ]);
        $expected = round((0.30 * 0.7 + 0.20 * (1 / 3) + 0.15 * (1 / 14)) / 0.90, 4);

        expect($s->score)->toBe($expected)->and($s->score)->toBe(0.3193);
    });

    it('keeps the score between 0 and 1', function () {
        $max = scored([
            'taskId' => 1,
            'keyResultWeight' => 1.0,
            'keyResultProgress' => 0.0,
            'priority' => 4,
            'deadlineAt' => jakarta('2026-11-01 09:00'),
            'readySince' => jakarta('2026-01-01 09:00'),
        ]);
        $min = scored(['taskId' => 1, 'priority' => 1]);

        expect($max->score)->toBe(1.0)->and($min->score)->toBe(0.0);
    });

    it('uses the user weights', function () {
        $profile = new ScoringProfile(
            weights: ['goal' => 0, 'urgency' => 0, 'priority' => 1, 'aging' => 0, 'fit' => 0.5],
            workHours: ScoringProfile::DEFAULT_WORK_HOURS,
            timezone: 'Asia/Jakarta',
        );

        expect(scored(['taskId' => 1, 'priority' => 3, 'keyResultWeight' => 1.0, 'keyResultProgress' => 0.0], $profile)->score)
            ->toBe(0.6667);
    });
});

describe('reasons', function () {
    it('names the two largest contributions, largest first', function () {
        $s = scored([
            'taskId' => 1,
            'keyResultWeight' => 1.0,
            'keyResultProgress' => 0.3,
            'priority' => 3,
            'readySince' => jakarta('2026-11-04 09:00'),
        ]);

        // Contributions: G .30×.7 = .21, P .20×.667 = .133, A .15×.643 = .096.
        expect($s->reasons)->toBe(['Key Result bobot tinggi, progres masih 30%', 'Prioritas tinggi']);
    });

    it('formats the deadline with the Indonesian day and month', function () {
        $s = scored(['taskId' => 1, 'deadlineAt' => jakarta('2026-11-14 12:00'), 'priority' => 1]);

        expect($s->reasons)->toBe(['Deadline Sabtu, 14 Nov']);
    });

    it('says when the deadline has passed', function () {
        $s = scored(['taskId' => 1, 'deadlineAt' => jakarta('2026-11-12 17:00'), 'priority' => 1]);

        expect($s->reasons)->toBe(['Lewat deadline Kamis, 12 Nov']);
    });

    it('adds the year to a deadline in another year', function () {
        // 19 500 working minutes left; a 15 000-minute estimate leaves 8.3 days of slack, so U > 0.
        $s = scored(['taskId' => 1, 'deadlineAt' => jakarta('2027-01-04 10:00'), 'estimateMinutes' => 15000, 'priority' => 1]);

        expect($s->reasons)->toBe(['Deadline Senin, 4 Jan 2027']);
    });

    it('reports whole days in Ready', function () {
        $s = scored(['taskId' => 1, 'priority' => 1, 'readySince' => jakarta('2026-11-04 08:00')]);

        expect($s->reasons)->toBe(['Sudah 9 hari di Ready']);
    });

    it('describes a lower Key Result weight', function () {
        $s = scored(['taskId' => 1, 'priority' => 1, 'keyResultWeight' => 0.5, 'keyResultProgress' => 0.0]);

        expect($s->reasons)->toBe(['Key Result bobot sedang, progres masih 0%']);
    });

    it('names priorities', function (int $priority, string $reason) {
        expect(scored(['taskId' => 1, 'priority' => $priority])->reasons)->toBe([$reason]);
    })->with([
        [2, 'Prioritas sedang'],
        [3, 'Prioritas tinggi'],
        [4, 'Prioritas kritis'],
    ]);

    it('gives no reason for components that contribute nothing', function () {
        expect(scored(['taskId' => 1, 'priority' => 1])->reasons)->toBe([]);
    });
});

describe('stale flag', function () {
    it('marks tasks more than 14 days in Ready', function () {
        expect(scored(['taskId' => 1, 'readySince' => jakarta('2026-10-29 08:59')])->stale)->toBeTrue();
    });

    it('does not mark tasks at exactly 14 days or less', function () {
        expect(scored(['taskId' => 1, 'readySince' => jakarta('2026-10-30 09:00')])->stale)->toBeFalse()
            ->and(scored(['taskId' => 1])->stale)->toBeFalse();
    });
});

describe('ranking three sample tasks', function () {
    beforeEach(function () {
        $this->candidates = [
            // KR task: G .7, P 1/3, A 1/14 → 0.3193
            new ScoringCandidate(
                taskId: 11,
                keyResultWeight: 1.0,
                keyResultProgress: 0.3,
                priority: 2,
                readySince: jakarta('2026-11-12 09:00'),
            ),
            // Urgent orphan: U = 1 − ((480 − 120)/540)/14, P 1, A 2/14 → 0.5106
            new ScoringCandidate(
                taskId: 12,
                priority: 4,
                deadlineAt: jakarta('2026-11-14 12:00'),
                estimateMinutes: 120,
                readySince: jakarta('2026-11-11 09:00'),
            ),
            // Forgotten orphan: A 1 → 0.1667, stale
            new ScoringCandidate(
                taskId: 13,
                priority: 1,
                readySince: jakarta('2026-10-24 09:00'),
            ),
            // Blocked: never ranked
            new ScoringCandidate(taskId: 14, priority: 4, openDependencies: 1),
        ];
    });

    it('orders by score, highest first', function () {
        $ranked = scorer()->rank($this->candidates, ScoringProfile::defaults());

        expect(array_map(fn (ScoredTask $s) => $s->taskId, $ranked))->toBe([12, 11, 13])
            ->and(array_map(fn (ScoredTask $s) => $s->score, $ranked))->toBe([0.5106, 0.3193, 0.1667]);
    });

    it('explains each task', function () {
        [$first, $second, $third] = scorer()->rank($this->candidates, ScoringProfile::defaults());

        expect($first->reasons)->toBe(['Deadline Sabtu, 14 Nov', 'Prioritas kritis'])
            ->and($second->reasons)->toBe(['Key Result bobot tinggi, progres masih 30%', 'Prioritas sedang'])
            ->and($third->reasons)->toBe(['Sudah 20 hari di Ready'])
            ->and([$first->stale, $second->stale, $third->stale])->toBe([false, false, true]);
    });

    it('limits the result', function () {
        $ranked = scorer()->rank($this->candidates, ScoringProfile::defaults(), limit: 2);

        expect(array_map(fn (ScoredTask $s) => $s->taskId, $ranked))->toBe([12, 11]);
    });

    it('breaks score ties by longer time in Ready, then task id', function () {
        $ranked = scorer()->rank([
            new ScoringCandidate(taskId: 3, priority: 1),
            new ScoringCandidate(taskId: 2, priority: 1),
            new ScoringCandidate(taskId: 1, priority: 1, readySince: jakarta('2026-11-13 08:59:59')),
        ], ScoringProfile::defaults());

        // Task 1's aging rounds to 0.0 too, but it has waited longer than the tasks without ready_since.
        expect(array_map(fn (ScoredTask $s) => $s->taskId, $ranked))->toBe([1, 2, 3]);
    });

    it('does not depend on the real clock', function () {
        $later = scorer('2026-11-20 09:00')->rank($this->candidates, ScoringProfile::defaults());

        // A week later the deadline has passed: task 12 gets U = 1.
        expect($later[0]->taskId)->toBe(12)->and($later[0]->breakdown['urgency'])->toBe(1.0);
    });
});
