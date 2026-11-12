<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema User in docs/tcb_openapi.yaml.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'wip_limit' => $this->wip_limit,
            'score_weights' => (object) $this->score_weights,
            'work_hours' => (object) $this->work_hours,
            'default_deadline_offset_minutes' => $this->default_deadline_offset_minutes,
        ];
    }
}
