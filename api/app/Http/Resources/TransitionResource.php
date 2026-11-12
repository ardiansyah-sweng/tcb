<?php

namespace App\Http\Resources;

use App\Models\StateTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema Transition in docs/tcb_openapi.yaml.
 *
 * @mixin StateTransition
 */
class TransitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_state' => $this->from_state,
            'to_state' => $this->to_state,
            'reason' => $this->reason,
            'source' => $this->source,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
