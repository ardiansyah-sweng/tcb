<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Services\TaskStateMachine;
use Illuminate\Http\Request;

/**
 * Schema TaskDetail in docs/tcb_openapi.yaml. Load the relations with
 * TaskDetailResource::load() first.
 *
 * calendar_links comes with Release 2.
 *
 * @mixin Task
 */
class TaskDetailResource extends TaskResource
{
    public static function load(Task $task): self
    {
        return new self($task->load([
            'latestContext',
            'subtasks' => fn ($q) => $q->orderBy('id'),
            'openDependencies.dependsOn',
            'openSession',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'latest_context' => $this->latestContext === null ? null : new ContextSnapshotResource($this->latestContext),
            'subtasks' => TaskResource::collection($this->subtasks),
            'open_dependencies' => DependencyResource::collection($this->openDependencies),
            'current_session_started_at' => $this->openSession?->started_at,
            // Read from the allowed_transitions table (CLAUDE.md rule 2), so
            // clients show valid actions without a copy of the rules.
            'allowed_transitions' => app(TaskStateMachine::class)->targetsFrom($this->state),
        ];
    }
}
