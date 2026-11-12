<?php

namespace App\Http\Resources;

use App\Models\KeyResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema KeyResult in docs/tcb_openapi.yaml. Load the model with the
 * withProgress() and withTaskCounts() scopes.
 *
 * @mixin KeyResult
 */
class KeyResultResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'objective_id' => $this->objective_id,
            'title' => $this->title,
            'weight' => $this->weight,
            'progress_mode' => $this->progress_mode,
            'start_value' => $this->start_value,
            'target_value' => $this->target_value,
            'current_value' => $this->current_value,
            'unit' => $this->unit,
            'progress' => (float) $this->progress,
            'task_counts' => $this->taskCounts(),
        ];
    }
}
