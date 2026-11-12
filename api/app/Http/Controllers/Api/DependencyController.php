<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveDependencyRequest;
use App\Http\Requests\StoreDependencyRequest;
use App\Http\Resources\DependencyResource;
use App\Models\Dependency;
use App\Models\Task;
use App\Services\DependencyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FR-11: a task waits for another task or an external blocker.
 * Rows are limited to the signed-in owner by the BelongsToOwner global scope.
 */
class DependencyController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        $dependencies = $task->dependencies()->with('dependsOn')->orderBy('id')->get();

        return response()->json(['data' => DependencyResource::collection($dependencies)]);
    }

    public function store(StoreDependencyRequest $request, Task $task): JsonResponse
    {
        $target = $request->dependsOn;

        try {
            // Savepoint: a rejected insert must not abort an outer transaction.
            $dependency = DB::transaction(fn () => $task->dependencies()->create([
                'depends_on_task_id' => $target?->id,
                'external_description' => $target === null ? $request->validated('external_description') : null,
            ]));
        } catch (QueryException $e) {
            // A concurrent request added the same pair after validation (UNIQUE task_id, depends_on_task_id).
            if ($target !== null && $e->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'depends_on_task_id' => sprintf(StoreDependencyRequest::DUPLICATE_MESSAGE, $target->task_key),
                ]);
            }
            throw $e;
        }

        // fresh(): status and created_at come from database defaults.
        return (new DependencyResource($dependency->fresh('dependsOn')))->response()->setStatusCode(201);
    }

    /**
     * Idempotent: resolving an already resolved blocker keeps its resolved_at.
     * DependencyService then moves the task to Ready if it is Waiting without
     * open dependencies.
     */
    public function update(ResolveDependencyRequest $request, Dependency $dependency, DependencyService $dependencies): DependencyResource
    {
        return new DependencyResource($dependencies->resolveExternal($dependency)->load('dependsOn'));
    }

    public function destroy(Dependency $dependency): Response
    {
        $dependency->delete();

        return response()->noContent();
    }
}
