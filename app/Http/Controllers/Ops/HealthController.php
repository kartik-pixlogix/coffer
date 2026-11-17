<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\Health;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /health (D97): the deep check for an uptime monitor (Authorization: Bearer
 * HEALTH_CHECK_TOKEN) or a signed-in platform admin. Anyone else gets a plain 404, so the
 * endpoint isn't advertised. 200 when nothing failed (warnings included), 503 otherwise.
 * `/up` stays the framework's cheap liveness probe for the load balancer.
 */
final class HealthController extends Controller
{
    public function __invoke(Request $request, Health $health): JsonResponse
    {
        $token = config('platform.health_token');
        $byToken = is_string($token) && $token !== '' && is_string($request->bearerToken()) && hash_equals($token, $request->bearerToken());

        abort_unless($byToken || Auth::guard('platform')->check(), 404);

        $report = $health->report();

        return response()->json($report, $report['status'] === 'fail' ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
