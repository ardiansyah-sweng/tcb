<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendationDecisionRequest;
use App\Http\Requests\RecommendationIndexRequest;
use App\Http\Resources\RecommendationResource;
use App\Models\Recommendation;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** FR-12, FR-13: recommendation batches and the user's decisions on them. */
class RecommendationController extends Controller
{
    /** Ranks the Ready tasks, stores the top ones as one batch, and returns it. */
    public function index(RecommendationIndexRequest $request, RecommendationService $recommendations): JsonResponse
    {
        $batch = $recommendations->generate($request->user(), $request->limit());

        return response()->json([
            'batch_id' => $batch['batch_id'],
            'generated_at' => $batch['generated_at'],
            'running_full' => $batch['running_full'],
            'items' => array_map(
                fn (array $item) => (new RecommendationResource($item))->resolve($request),
                $batch['items'],
            ),
        ]);
    }

    /**
     * Records a decision; a later one replaces it. picked only records:
     * starting the task is a transition or /switch (contract).
     */
    public function decide(RecommendationDecisionRequest $request, Recommendation $recommendation): Response
    {
        $recommendation->forceFill([
            'decision' => $request->validated('decision'),
            'decided_at' => Carbon::now(),
        ])->save();

        return response()->noContent();
    }
}
