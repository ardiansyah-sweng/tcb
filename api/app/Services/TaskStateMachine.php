<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Valid task state transitions (SRS section 4, FR-05).
 *
 * The allowed_transitions table is the single source of transition rules
 * (CLAUDE.md rule 2): this class reads it once per instance and never keeps
 * a copy of the list in code. It is bound as a scoped singleton, so the table
 * is read at most once per request or job. The database trigger checks every
 * state change again; this class only exists to give friendly errors first.
 */
class TaskStateMachine
{
    /**
     * States that need a reason. Mirrors the chk_tasks_reason_required CHECK
     * on tasks, which stays the guard.
     */
    private const REASON_REQUIRED = ['waiting', 'cancelled'];

    /** States that finish a task's work (leaving Running for them is not a context switch). */
    private const FINISHED = ['done', 'cancelled'];

    /** @var array<string, list<string>>|null from_state => to_states, in lifecycle order */
    private ?array $targets = null;

    public function allows(string $from, string $to): bool
    {
        return in_array($to, $this->targetsFrom($from), true);
    }

    /**
     * @return list<string> states reachable from $from, in lifecycle order
     */
    public function targetsFrom(string $from): array
    {
        return $this->targets()[$from] ?? [];
    }

    public function requiresReason(string $to): bool
    {
        return in_array($to, self::REASON_REQUIRED, true);
    }

    /**
     * The task leaves Running without finishing (to Ready, Waiting, or
     * Suspended): it needs a context (FR-07) and counts as a switch (FR-10).
     * This is a property of the move, not a list of allowed transitions.
     */
    public function isContextSwitch(string $from, string $to): bool
    {
        return $from === 'running' && $to !== 'running' && ! in_array($to, self::FINISHED, true);
    }

    /**
     * Indonesian message for a rejected transition, e.g. "Task berstatus New
     * tidak bisa langsung Running. Pindahkan ke Ready dulu."
     */
    public function rejectionMessage(string $from, string $to): string
    {
        $fromLabel = self::label($from);
        $toLabel = self::label($to);

        if ($from === $to) {
            return "Task sudah berstatus {$fromLabel}.";
        }

        foreach ($this->targetsFrom($from) as $via) {
            if (in_array($to, $this->targetsFrom($via), true)) {
                return "Task berstatus {$fromLabel} tidak bisa langsung {$toLabel}. Pindahkan ke ".self::label($via).' dulu.';
            }
        }

        $targets = $this->targetsFrom($from);
        if ($targets === []) {
            return "Task berstatus {$fromLabel} tidak bisa dipindahkan ke status lain.";
        }

        return "Task berstatus {$fromLabel} tidak bisa pindah ke {$toLabel}. Status yang bisa dituju: "
            .implode(', ', array_map(self::label(...), $targets)).'.';
    }

    /** UI name of a state: new -> New. */
    public static function label(string $state): string
    {
        return ucfirst($state);
    }

    /**
     * @return array<string, list<string>>
     */
    private function targets(): array
    {
        if ($this->targets === null) {
            $order = array_flip(Task::STATES);
            $this->targets = [];

            foreach (DB::table('allowed_transitions')->get(['from_state', 'to_state']) as $row) {
                $this->targets[$row->from_state][] = $row->to_state;
            }

            foreach ($this->targets as &$to) {
                usort($to, fn (string $a, string $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
            }
            unset($to);
        }

        return $this->targets;
    }
}
