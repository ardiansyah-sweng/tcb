<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransitionRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TransitionResource;
use App\Models\Task;
use App\Services\TransitionService;
use Illuminate\Http\JsonResponse;

/**
 * FR-05, FR-09: change a task's state and read its transition log.
 * {task} is a numeric ID or a task_key (Task::resolveRouteBinding).
 */
class TransitionController extends Controller
{
    /** The append-only log, newest first (rows from one transaction share occurred_at). */
    public function index(Task $task): JsonResponse
    {
        $transitions = $task->transitions()->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return response()->json(['data' => TransitionResource::collection($transitions)]);
    }

    public function store(TransitionRequest $request, Task $task, TransitionService $transitions): TaskDetailResource
    {
        $task = $transitions->transition(
            $task,
            $request->validated('to'),
            reason: $request->validated('reason'),
            reviewOn: $request->validated('review_on'),
            context: $request->context(),
        );

        return TaskDetailResource::load($task);
    }
}
