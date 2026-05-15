<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHealthToken
{
    /**
     * Purpose: Protect Procynia health endpoints with a shared header token.
     * Inputs: The current request and the next middleware callback.
     * Returns: The downstream response or a controlled JSON error response.
     * Side effects: None.
     */
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        $configuredToken = trim((string) config('procynia.health_token', ''));

        if ($configuredToken === '') {
            return response()->json([
                'status' => 'fail',
                'message' => 'Health token is not configured',
            ], 503);
        }

        $providedToken = trim((string) $request->header('X-Procynia-Health-Token', ''));

        if ($providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}
