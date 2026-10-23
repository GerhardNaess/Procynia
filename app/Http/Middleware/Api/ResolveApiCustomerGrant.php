<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\Api\ApiAccessRefused;
use App\Models\ApiClientCustomerGrant;
use App\Services\Api\ApiRequestContext;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which customer this request is for, and as whom it reads (D1, D6).
 *
 * The token says which application is calling; it cannot say which customer — the application's
 * identity lives in its own tenant, not the customer's. The Procynia-Customer header only chooses
 * among the customer grants this application holds. It never grants anything: without an active
 * grant the answer is a 403, the same answer as for a customer that does not exist.
 *
 * From here on the actor is the grant's integration identity, and every rule downstream —
 * module, permission, fagområde — is the customer's own, applied to that identity.
 */
class ResolveApiCustomerGrant
{
    public const HEADER = 'Procynia-Customer';

    /** A grant's last-used time is refreshed at most this often. */
    private const LAST_USED_RESOLUTION_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ApiRequestContext::class);
        $client = $context->client;
        $requested = (string) $request->header(self::HEADER, '');
        $customerId = preg_match('/^[1-9][0-9]{0,17}$/', $requested) === 1 ? (int) $requested : null;

        $grant = $client !== null && $customerId !== null
            ? ApiClientCustomerGrant::query()
                ->active()
                ->where('api_client_id', $client->id)
                ->where('customer_id', $customerId)
                ->with(['customer', 'integrationUser'])
                ->first()
            : null;

        $customer = $grant?->customer;
        $actor = $grant?->integrationUser;

        $refusal = match (true) {
            $grant === null => 'no_active_grant',
            $customer === null || ! $customer->is_active => 'customer_inactive',
            // The grant must name an integration identity of the same customer. Anything else — a
            // person, another customer's identity — would hand the application someone else's
            // rights, so it is refused however it came about.
            $actor === null || ! $actor->isIntegration() || (int) $actor->customer_id !== (int) $grant->customer_id => 'grant_inconsistent',
            ! $actor->is_active => 'actor_inactive',
            default => null,
        };

        if ($refusal !== null) {
            $this->logRefusal($request, $context, $customerId, $refusal);

            throw new ApiAccessRefused($refusal);
        }

        $this->throttle($context, (int) $grant->customer_id);

        $actor->setRelation('customer', $customer);
        Auth::setUser($actor);
        $context->customer = $customer;
        $context->actor = $actor;

        $this->recordUse($grant);

        return $next($request);
    }

    private function throttle(ApiRequestContext $context, int $customerId): void
    {
        $key = 'api:'.$context->client?->id.':'.$customerId;
        $limit = max(1, (int) config('procynia.api.rate_limit_per_minute', 240));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            Log::warning('[PROCYNIA][API] API request throttled.', [
                'event' => 'api_throttled',
                'request_id' => $context->requestId,
                'api_client_id' => $context->client?->id,
                'customer_id' => $customerId,
                'retry_after' => $retryAfter,
            ]);

            throw new ThrottleRequestsException('Too many requests.', null, ['Retry-After' => $retryAfter]);
        }

        RateLimiter::hit($key, 60);
    }

    private function recordUse(ApiClientCustomerGrant $grant): void
    {
        $threshold = now()->subSeconds(self::LAST_USED_RESOLUTION_SECONDS);

        ApiClientCustomerGrant::query()
            ->whereKey($grant->id)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $threshold))
            ->toBase()
            ->update(['last_used_at' => now()]);
    }

    private function logRefusal(Request $request, ApiRequestContext $context, ?int $customerId, string $reason): void
    {
        Log::warning('[PROCYNIA][AUTH] API access refused.', [
            'event' => 'api_access_refused',
            'reason' => $reason,
            'request_id' => $context->requestId,
            'api_client_id' => $context->client?->id,
            'requested_customer_id' => $customerId,
            'ip' => $request->ip(),
        ]);
    }
}
