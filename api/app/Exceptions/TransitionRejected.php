<?php

namespace App\Exceptions;

use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A state change refused by a TCB rule. The application check and the
 * database guard (CLAUDE.md rule 3) both end here, so a rule broken past the
 * application check still gets the same response (docs/tcb_openapi.yaml:
 * Error, WipFullError, ValidationErrorBody).
 */
class TransitionRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(public readonly int $status, public readonly array $body)
    {
        parent::__construct($body['message']);
    }

    public static function invalidTransition(TaskStateMachine $machine, string $from, string $to): self
    {
        return new self(409, [
            'code' => 'invalid_transition',
            'message' => $machine->rejectionMessage($from, $to),
            'details' => ['from' => $from, 'to' => $to, 'allowed' => $machine->targetsFrom($from)],
        ]);
    }

    /**
     * 422 that is both an Error (code) and a ValidationErrorBody (errors.reason).
     * $to is null when the database reports the violation without the state.
     */
    public static function reasonRequired(?string $to): self
    {
        $message = $to === null
            ? 'Alasan wajib diisi untuk status Waiting dan Cancelled.'
            : 'Alasan wajib diisi untuk status '.TaskStateMachine::label($to).'.';

        return new self(422, [
            'code' => 'reason_required',
            'message' => $message,
            'errors' => ['reason' => [$message]],
        ]);
    }

    /** 422 for leaving Running without a context: an Error (code) and a ValidationErrorBody (errors.context). */
    public static function contextRequired(): self
    {
        $message = 'Catat konteks dulu sebelum meninggalkan Running: sampai mana dan langkah berikutnya.';

        return new self(422, [
            'code' => 'context_required',
            'message' => $message,
            'errors' => ['context' => [$message]],
        ]);
    }

    /**
     * @param  list<string>  $taskKeys
     */
    public static function openSubtasks(array $taskKeys): self
    {
        return new self(409, [
            'code' => 'conflict',
            'message' => 'Task belum bisa Done karena masih ada subtask yang belum selesai: '
                .implode(', ', $taskKeys).'. Selesaikan atau batalkan dulu.',
            'details' => ['open_subtasks' => $taskKeys],
        ]);
    }

    /**
     * @param  Collection<int, Task>  $running
     */
    public static function wipFull(int $wipLimit, Collection $running): self
    {
        return new self(409, [
            'code' => 'wip_full',
            'message' => "WIP limit penuh: sudah ada {$wipLimit} task Running. "
                .'Simpan konteks task yang sedang berjalan lalu pindah, atau selesaikan dulu.',
            'wip_limit' => $wipLimit,
            'running' => TaskResource::collection($running)->resolve(),
        ]);
    }

    public function render(): JsonResponse
    {
        return response()->json($this->body, $this->status);
    }
}
