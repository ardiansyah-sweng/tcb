<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SwitchRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskResource;
use App\Services\SwitchService;
use Illuminate\Http\JsonResponse;

/** FR-06: POST /switch, the atomic context switch. */
class SwitchController extends Controller
{
    public function __invoke(SwitchRequest $request, SwitchService $switches): JsonResponse
    {
        $result = $switches->switch(
            $request->fromTask(),
            $request->fromTo(),
            $request->context(),
            $request->toTask(),
            reason: $request->validated('reason'),
            recommendation: $request->recommendation(),
        );

        return response()->json([
            'left' => (new TaskResource($result['left']))->resolve($request),
            'started' => TaskDetailResource::load($result['started'])->resolve($request),
        ]);
    }
}
