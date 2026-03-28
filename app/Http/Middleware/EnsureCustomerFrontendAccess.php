<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerFrontendAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'canAccessCustomerFrontend') || ! $user->canAccessCustomerFrontend()) {
            abort(403);
        }

        return $next($request);
    }
}
