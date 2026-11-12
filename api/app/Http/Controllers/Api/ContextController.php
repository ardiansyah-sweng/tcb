<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContextRequest;
use App\Http\Resources\ContextSnapshotResource;
use App\Models\Task;
use App\Services\ContextService;
use Illuminate\Http\JsonResponse;

/**
 * FR-07, FR-08: a task's context history, notes without a state change, and
 * the pre-filled context form. {task} is a numeric ID or a task_key.
 */
class ContextController extends Controller
{
    /** Newest first. */
    public function index(Task $task): JsonResponse
    {
        $snapshots = $task->contextSnapshots()->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json(['data' => ContextSnapshotResource::collection($snapshots)]);
    }

    public function store(StoreContextRequest $request, Task $task, ContextService $contexts): JsonResponse
    {
        $snapshot = $contexts->note($task, $request->validated());

        return (new ContextSnapshotResource($snapshot))->response()->setStatusCode(201);
    }

    public function draft(Task $task, ContextService $contexts): JsonResponse
    {
        return response()->json($contexts->draft($task));
    }
}
