<?php

namespace App\Support;

use App\Exceptions\TransitionRejected;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\TransitionRequest;
use App\Models\Task;
use App\Services\TaskStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Maps rule violations raised by the database (the last guard, CLAUDE.md
 * rule 3) to the same API responses the application checks give, so a
 * request that slips past application validation never becomes a 500:
 *
 * - HINT invalid_transition -> 409 invalid_transition
 * - HINT wip_full           -> 409 wip_full with the running tasks
 * - chk_tasks_reason_required -> 422 reason_required
 * - other chk_* on tasks    -> 422 ValidationErrorBody on the field
 */
final class DatabaseErrors
{
    private const CHECK_VIOLATION = '23514';

    /** CHECK constraint => [field, message]. */
    private const CHECKS = [
        'chk_tasks_unit_le_total' => ['unit_done', 'Jumlah selesai tidak boleh melebihi total satuan.'],
        'chk_tasks_unit_needs_total' => ['unit_done', StoreTaskRequest::NEEDS_TOTAL_MESSAGE],
        'chk_tasks_not_own_parent' => ['parent_task_id', 'Task tidak bisa menjadi induk dirinya sendiri.'],
        'chk_tasks_review_on_suspended' => ['review_on', TransitionRequest::REVIEW_ON_MESSAGE],
    ];

    /**
     * Response for the exception renderer, or null when the error is not a known rule.
     * $ownerId lists the running tasks in a wip_full response.
     */
    public static function response(QueryException $e, ?int $ownerId = null): ?JsonResponse
    {
        if (($rejection = self::rejection($e, $ownerId)) !== null) {
            return $rejection->render();
        }

        if (($body = self::validationBody($e)) !== null) {
            return response()->json($body, 422);
        }

        return null;
    }

    /**
     * The state rule the database enforced, as the exception the application
     * check would have thrown. $to names the target state when the caller
     * knows it (the CHECK error does not carry it).
     */
    public static function rejection(QueryException $e, ?int $ownerId = null, ?string $to = null): ?TransitionRejected
    {
        if ($e->getCode() !== self::CHECK_VIOLATION) {
            return null;
        }

        $message = $e->getMessage();

        if (self::hasHint($message, 'invalid_transition')) {
            // tasks_check_transition(): 'Transisi tidak sah: <from> -> <to>'
            if (preg_match('/Transisi tidak sah: (\w+) -> (\w+)/', $message, $m)) {
                return TransitionRejected::invalidTransition(app(TaskStateMachine::class), $m[1], $m[2]);
            }

            return new TransitionRejected(409, [
                'code' => 'invalid_transition',
                'message' => 'Perubahan status ini tidak diizinkan.',
            ]);
        }

        if (self::hasHint($message, 'wip_full')) {
            // tasks_enforce_wip(): 'WIP limit penuh (<running> dari <limit>)'
            $limit = preg_match('/WIP limit penuh \((\d+) dari (\d+)\)/', $message, $m) ? (int) $m[2] : 1;

            return TransitionRejected::wipFull($limit, self::runningTasks($ownerId));
        }

        if (str_contains($message, '"chk_tasks_reason_required"')) {
            return TransitionRejected::reasonRequired($to);
        }

        return null;
    }

    /**
     * @return array{message: string, errors: array<string, array<int, string>>}|null
     */
    public static function validationBody(QueryException $e): ?array
    {
        if ($e->getCode() !== self::CHECK_VIOLATION) {
            return null;
        }

        foreach (self::CHECKS as $constraint => [$field, $message]) {
            if (str_contains($e->getMessage(), "\"{$constraint}\"")) {
                return ['message' => $message, 'errors' => [$field => [$message]]];
            }
        }

        return null;
    }

    private static function hasHint(string $message, string $hint): bool
    {
        return preg_match('/HINT:\s+'.preg_quote($hint, '/').'\b/', $message) === 1;
    }

    /**
     * @return Collection<int, Task>
     */
    private static function runningTasks(?int $ownerId): Collection
    {
        if ($ownerId === null) {
            return collect();
        }

        // Explicit owner filter: the owner scope is only active for a signed-in user.
        return Task::withoutGlobalScope('owner')
            ->where('owner_id', $ownerId)
            ->where('state', 'running')
            ->orderBy('state_changed_at')
            ->orderBy('id')
            ->get();
    }
}
