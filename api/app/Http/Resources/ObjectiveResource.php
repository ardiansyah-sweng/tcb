<?php

namespace App\Http\Resources;

use App\Models\Objective;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema Objective in docs/tcb_openapi.yaml; with key results loaded it is
 * ObjectiveDetail. Load the model with the withProgress() scope.
 *
 * @mixin Objective
 */
class ObjectiveResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'progress' => (float) $this->progress,
            'key_results' => KeyResultResource::collection($this->whenLoaded('keyResults')),
        ];
    }
}
