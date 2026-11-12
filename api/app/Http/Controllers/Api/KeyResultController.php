<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKeyResultRequest;
use App\Http\Requests\UpdateKeyResultRequest;
use App\Http\Resources\KeyResultResource;
use App\Models\KeyResult;
use App\Models\Objective;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * FR-02: manage Key Results. Rows are limited to the signed-in owner by the
 * BelongsToOwner global scope, so another owner's objective or key result is
 * a 404.
 */
class KeyResultController extends Controller
{
    public function store(StoreKeyResultRequest $request, Objective $objective): JsonResponse
    {
        $keyResult = $objective->keyResults()->create($request->safe()->only([
            'title', 'weight', 'progress_mode', 'start_value', 'target_value', 'current_value', 'unit',
        ]));

        return (new KeyResultResource($this->load($keyResult->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateKeyResultRequest $request, KeyResult $keyResult): KeyResultResource
    {
        $keyResult->update($request->validated());

        return new KeyResultResource($this->load($keyResult->id));
    }

    /**
     * Tasks of the key result become orphans: the foreign key
     * tasks.key_result_id is ON DELETE SET NULL.
     */
    public function destroy(KeyResult $keyResult): Response
    {
        $keyResult->delete();

        return response()->noContent();
    }

    private function load(int $id): KeyResult
    {
        return KeyResult::withProgress()->withTaskCounts()->findOrFail($id);
    }
}
