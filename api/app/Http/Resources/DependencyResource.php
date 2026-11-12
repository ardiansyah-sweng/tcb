<?php

namespace App\Http\Resources;

use App\Models\Dependency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema Dependency in docs/tcb_openapi.yaml. Eager-load `dependsOn` to
 * fill depends_on_task_key without a query per row.
 *
 * @mixin Dependency
 */
class DependencyResource extends JsonResource
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
            'depends_on_task_id' => $this->depends_on_task_id,
            'depends_on_task_key' => $this->dependsOn?->task_key,
            'external_description' => $this->external_description,
            'status' => $this->status,
            'resolved_at' => $this->resolved_at,
        ];
    }
}
