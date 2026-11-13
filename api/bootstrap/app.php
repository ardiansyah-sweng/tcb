<?php

use App\Models\Dependency;
use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\Recommendation;
use App\Models\Task;
use App\Support\DatabaseErrors;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API only: never redirect guests to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($wantsJson);

        // Unauthorized response from docs/tcb_openapi.yaml.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json([
                    'code' => 'unauthenticated',
                    'message' => 'Silakan masuk terlebih dahulu.',
                ], 401);
            }
        });

        // Database rule violations (CLAUDE.md rule 3) get the same 409/422 as
        // the application checks (invalid_transition, wip_full, chk_*).
        $exceptions->render(function (QueryException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return DatabaseErrors::response($e, $request->user()?->id);
            }
        });

        // NotFound response from docs/tcb_openapi.yaml (schema Error, code not_found).
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                $previous = $e->getPrevious();
                $model = $previous instanceof ModelNotFoundException ? $previous->getModel() : null;
                $label = match ($model) {
                    null => 'Alamat', // no route matched
                    Objective::class => 'Objective',
                    KeyResult::class => 'Key Result',
                    Task::class => 'Task',
                    Dependency::class => 'Dependensi',
                    Recommendation::class => 'Rekomendasi',
                    default => 'Data',
                };

                return response()->json([
                    'code' => 'not_found',
                    'message' => "{$label} tidak ditemukan.",
                ], 404);
            }
        });
    })->create();
