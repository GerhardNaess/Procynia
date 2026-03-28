<?php

namespace App\Http\Middleware;

use App\Support\CustomerContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCustomerLocale
{
    public function __construct(
        private readonly CustomerContext $customerContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $languageCode = $this->customerContext->resolveLanguageCode();

        app()->setLocale($languageCode);

        return $next($request);
    }
}
