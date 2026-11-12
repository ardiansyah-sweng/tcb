<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema Task in docs/tcb_openapi.yaml.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_key' => $this->task_key,
            'title' => $this->title,
            'description' => $this->description,
            'key_result_id' => $this->key_result_id,
            'is_orphan' => $this->key_result_id === null,
            'parent_task_id' => $this->parent_task_id,
            'label' => $this->label,
            'state' => $this->state,
            'state_reason' => $this->state_reason,
            'state_changed_at' => $this->state_changed_at,
            'ready_since' => $this->ready_since,
            'priority' => $this->priority,
            'deadline_at' => $this->deadline_at,
            'deadline_source' => $this->deadline_source,
            'estimate_minutes' => $this->estimate_minutes,
            'energy' => $this->energy,
            'next_step' => $this->next_step,
            'progress_unit' => $this->unit_total === null ? null : [
                'done' => $this->unit_done ?? 0,
                'total' => $this->unit_total,
                'name' => $this->unit_name ?? 'unit',
            ],
            'time_spent_minutes' => $this->time_spent_minutes,
            'switch_count' => $this->switch_count,
            'last_activity_at' => $this->last_activity_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
