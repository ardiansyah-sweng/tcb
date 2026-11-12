<?php

namespace App\Http\Resources;

use App\Models\ContextSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema ContextSnapshot in docs/tcb_openapi.yaml.
 *
 * @mixin ContextSnapshot
 */
class ContextSnapshotResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'last_note' => $this->last_note,
            'next_step' => $this->next_step,
            'blocker' => $this->blocker,
            'unit_done' => $this->unit_done,
            'links' => $this->links,
            'created_at' => $this->created_at,
        ];
    }
}
