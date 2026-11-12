<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreObjectiveRequest;
use App\Http\Requests\UpdateObjectiveRequest;
use App\Http\Resources\ObjectiveResource;
use App\Models\Objective;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-01: manage Objectives. Rows are limited to the signed-in owner by the
 * BelongsToOwner global scope, so another owner's objective is a 404.
 */
class ObjectiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['status' => ['sometimes', 'in:active,closed,archived']],
            ['status.in' => StoreObjectiveRequest::MESSAGES['status.in']],
        );

        $objectives = Objective::withProgress()
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status),
                fn ($query) => $query->where('status', '!=', 'archived'),
            )
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => ObjectiveResource::collection($objectives)]);
    }

    public function store(StoreObjectiveRequest $request): JsonResponse
    {
        $objective = Objective::create($request->safe()->only(['title', 'description', 'period_start', 'period_end']));

        return (new ObjectiveResource($this->load($objective->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Objective $objective): ObjectiveResource
    {
        return new ObjectiveResource($this->load($objective->id, withKeyResults: true));
    }

    public function update(UpdateObjectiveRequest $request, Objective $objective): ObjectiveResource
    {
        $objective->update($request->validated());

        return new ObjectiveResource($this->load($objective->id));
    }

    private function load(int $id, bool $withKeyResults = false): Objective
    {
        return Objective::withProgress()
            ->when($withKeyResults, fn ($query) => $query->with([
                'keyResults' => fn (HasMany $keyResults) => $keyResults->withProgress()->orderBy('id'),
            ]))
            ->findOrFail($id);
    }
}
