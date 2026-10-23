<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\Api\ApiAccessRefused;
use App\Exceptions\Api\ApiAuthenticationFailed;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\EntraAccessTokenValidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client application is who the bearer token says it is (D2–D6).
 *
 * No interactive sign-in and no session: the token is validated on every request. A refusal is
 * logged with its reason, and with the token's `azp` and `tid` when they are well-formed — never
 * the token itself.
 */
class AuthenticateApiClient
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly EntraAccessTokenValidator $validator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        try {
            if ($token === '') {
                throw new ApiAuthenticationFailed('missing_token');
            }

            $validated = $this->validator->validate($token);
        } catch (ApiAuthenticationFailed|ApiAccessRefused $refusal) {
            $this->logRefusal($request, $token, $refusal->reason);

            throw $refusal;
        }

        app(ApiRequestContext::class)->authenticated($validated->client);

        return $next($request);
    }

    private function logRefusal(Request $request, string $token, string $reason): void
    {
        $payload = $token !== '' ? (EntraAccessTokenValidator::unverifiedClaims($token)[1] ?? []) : [];

        Log::warning('[PROCYNIA][AUTH] API token rejected.', [
            'event' => 'api_token_rejected',
            'reason' => $reason,
            'request_id' => app(ApiRequestContext::class)->requestId,
            'azp' => $this->guidOrNull($payload['azp'] ?? null),
            'tid' => $this->guidOrNull($payload['tid'] ?? null),
            'ip' => $request->ip(),
        ]);
    }

    private function guidOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::GUID, $value) === 1 ? strtolower($value) : null;
    }
}
