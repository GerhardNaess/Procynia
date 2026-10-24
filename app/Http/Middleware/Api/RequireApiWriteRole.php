<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\Api\ApiAccessRefused;
use App\Services\Api\ApiRequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write route needs a token carrying the write app role (Procynia.Write.All), on top of the read
 * role every route needs. The role is the ceiling Entra sets; it grants no operation. Which
 * operations the integration may perform is the customer's choice, checked in the domain
 * (IntegrationOperationAuthority), which also requires the role — this only refuses early.
 */
class RequireApiWriteRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ApiRequestContext::class);

        if (! $context->writeRole) {
            Log::warning('[PROCYNIA][AUTH] API access refused.', [
                'event' => 'api_access_refused',
                'reason' => 'missing_write_role',
                'request_id' => $context->requestId,
                'api_client_id' => $context->client?->id,
                'requested_customer_id' => $context->customer?->id,
                'ip' => $request->ip(),
            ]);

            throw new ApiAccessRefused('missing_write_role');
        }

        return $next($request);
    }
}
