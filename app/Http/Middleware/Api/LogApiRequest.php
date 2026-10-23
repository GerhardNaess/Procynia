<?php

namespace App\Http\Middleware\Api;

use App\Services\Api\ApiRequestContext;
use App\Services\Api\ApiRequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One structured log line per API request (D10), refused requests included.
 *
 * Outermost in the API stack, so it sees the final status of every request, and it binds the
 * request's ApiRequestContext. It writes identifiers, the route name, the status and the duration —
 * never the token, a header, the body or a query value (docs/operations/security.md §9).
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $context = new ApiRequestContext(ApiRequestId::for($request));
        app()->instance(ApiRequestContext::class, $context);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $context->requestId);

        Log::info('[PROCYNIA][API] API request.', [
            'event' => 'api_request',
            'request_id' => $context->requestId,
            'api_client_id' => $context->client?->id,
            'mode' => $context->mode,
            'customer_id' => $context->customer?->id,
            'actor_user_id' => $context->actor?->id,
            'route' => $request->route()?->getName(),
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'rows' => $context->rows,
        ]);

        return $response;
    }
}
