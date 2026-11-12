<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->select('select 1');
            $database = 'ok';
        } catch (Throwable $e) {
            report($e);
            $database = 'error';
        }

        return response()->json(
            ['status' => 'ok', 'database' => $database],
            $database === 'ok' ? 200 : 503,
        );
    }
}
