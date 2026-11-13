<?php

namespace App\Http\Resources;

use App\Models\Recommendation;
use App\Models\Task;
use App\Services\Recommendation\ScoredTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema Recommendation in docs/tcb_openapi.yaml, built from a freshly
 * stored row and its scorer result. stale is computed, never stored.
 *
 * @property array{recommendation: Recommendation, scored: ScoredTask, task: Task} $resource
 */
class RecommendationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Recommendation $row */
        $row = $this->resource['recommendation'];
        /** @var ScoredTask $scored */
        $scored = $this->resource['scored'];

        return [
            'id' => $row->id,
            'rank' => $row->rank,
            'score' => $scored->score,
            'breakdown' => $scored->breakdown,
            'reasons' => $scored->reasons,
            'stale' => $scored->stale,
            // Free calendar slots come with Google Calendar (Rilis 2).
            'suggested_slot' => null,
            'task' => (new TaskResource($this->resource['task']))->resolve($request),
        ];
    }
}
