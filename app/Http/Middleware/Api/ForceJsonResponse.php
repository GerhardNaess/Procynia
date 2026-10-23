<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every /api request is answered as JSON, whatever Accept header the client sent.
 *
 * Shared middleware decides between a redirect and a refusal by asking expectsJson():
 * EnsureModuleIsEnabled, for one, redirects a browser to Hjem. An API client must get the error
 * format instead, so the question is settled here, first in the api group.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
