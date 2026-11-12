<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Schema TaskDetail in docs/tcb_openapi.yaml. Load the relations with
 * TaskDetailResource::load() first.
 *
 * latest_context and current_session_started_at come with R1-10;
 * calendar_links with Release 2.
 *
 * @mixin Task
 */
class TaskDetailResource extends TaskResource
{
    public static function load(Task $task): self
    {
        return new self($task->load([
            'subtasks' => fn ($q) => $q->orderBy('id'),
            'openDependencies.dependsOn',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'subtasks' => TaskResource::collection($this->subtasks),
            'open_dependencies' => DependencyResource::collection($this->openDependencies),
        ];
    }
}
