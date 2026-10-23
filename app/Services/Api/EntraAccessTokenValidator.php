<?php

namespace App\Services\Api;

use App\Exceptions\Api\ApiAccessRefused;
use App\Exceptions\Api\ApiAuthenticationFailed;
use App\Models\ApiClient;
use App\Services\Auth\EntraJwksProvider;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;
use UnexpectedValueException;

/**
 * Validates an Entra v2 access token for the Procynia API in application mode (D2, D3, D6).
 *
 * Order matters, and every step fails closed:
 *
 *  1. The API is configured (at least one accepted audience).
 *  2. The header names RS256 and a key id. Nothing else is ever accepted: not `none`, not HMAC.
 *  3. The token's tenant is the home tenant of an enabled client. Read from the unverified payload
 *     only to choose whose keys to fetch — a tenant no client lives in is refused before any call
 *     to Microsoft, so a forged token cannot make Procynia fetch arbitrary tenants' keys.
 *  4. Signature against that tenant's keys, then expiry and not-before.
 *  5. From the verified claims: version 2.0, issuer of exactly that tenant, an accepted audience.
 *  6. The pair (azp, tid) is an enabled client. Both must match: in a multi-tenant API another
 *     tenant's application may carry the same app role, and only its home tenant tells them apart.
 *  7. Application mode: no `scp` (a delegated token), and the read app role.
 *
 * Steps 1–6 are authentication (401). Step 7 is a known client asking for something it may not
 * have (403). The token decides only *that* the client may call the API; what it may read is
 * decided by Procynia's roles (D4).
 */
class EntraAccessTokenValidator
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly EntraJwksProvider $jwks) {}

    public function validate(string $token): ValidatedApplicationToken
    {
        $audiences = (array) config('procynia.api.entra.audiences', []);

        if ($audiences === []) {
            throw new ApiAuthenticationFailed('api_not_configured');
        }

        $unverified = self::unverifiedClaims($token);

        if ($unverified === null) {
            throw new ApiAuthenticationFailed('malformed_token');
        }

        [$header, $payload] = $unverified;

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new ApiAuthenticationFailed('unsupported_algorithm');
        }

        if (! is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new ApiAuthenticationFailed('malformed_token');
        }

        $tenantId = $payload['tid'] ?? null;

        if (! is_string($tenantId) || preg_match(self::GUID, $tenantId) !== 1) {
            throw new ApiAuthenticationFailed('malformed_token');
        }

        $tenantId = strtolower($tenantId);

        if (! ApiClient::query()->where('home_tenant_id', $tenantId)->where('is_enabled', true)->exists()) {
            throw new ApiAuthenticationFailed('unknown_client');
        }

        $claims = $this->verifiedClaims($token, $tenantId);

        if (($claims['ver'] ?? null) !== '2.0') {
            throw new ApiAuthenticationFailed('wrong_token_version');
        }

        if (strtolower((string) ($claims['tid'] ?? '')) !== $tenantId
            || ($claims['iss'] ?? null) !== 'https://login.microsoftonline.com/'.$tenantId.'/v2.0') {
            throw new ApiAuthenticationFailed('wrong_issuer');
        }

        $tokenAudiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];

        if (array_intersect(array_map('strval', $tokenAudiences), array_map('strval', $audiences)) === []) {
            throw new ApiAuthenticationFailed('wrong_audience');
        }

        if (! isset($claims['exp'])) {
            throw new ApiAuthenticationFailed('expired');
        }

        $appId = $claims['azp'] ?? null;

        $client = is_string($appId) && preg_match(self::GUID, $appId) === 1
            ? ApiClient::query()
                ->where('entra_app_id', strtolower($appId))
                ->where('home_tenant_id', $tenantId)
                ->first()
            : null;

        if ($client === null) {
            throw new ApiAuthenticationFailed('unknown_client');
        }

        if (! $client->is_enabled) {
            throw new ApiAuthenticationFailed('client_disabled');
        }

        // Delegated mode is not offered (D2). A token with scopes acts for a person.
        if (array_key_exists('scp', $claims) || (isset($claims['idtyp']) && $claims['idtyp'] !== 'app')) {
            throw new ApiAccessRefused('mode_not_allowed');
        }

        $roles = is_array($claims['roles'] ?? null) ? $claims['roles'] : [];

        if (! in_array((string) config('procynia.api.entra.read_role'), $roles, true)) {
            throw new ApiAccessRefused('missing_app_role');
        }

        return new ValidatedApplicationToken(
            $client,
            $tenantId,
            is_string($claims['oid'] ?? null) ? $claims['oid'] : null,
        );
    }

    /**
     * Header and payload, without verifying anything. For choosing keys and for the log only.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public static function unverifiedClaims(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 8, JSON_THROW_ON_ERROR);
            $payload = json_decode(JWT::urlsafeB64Decode($parts[1]), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($header) && is_array($payload) ? [$header, $payload] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedClaims(string $token, string $tenantId): array
    {
        try {
            $keys = JWK::parseKeySet($this->jwks->forTenant($tenantId), 'RS256');
        } catch (Throwable) {
            // Microsoft's keys could not be had. Not the client's fault, and not a reason to let it in.
            throw new ServiceUnavailableHttpException;
        }

        JWT::$leeway = (int) config('procynia.api.entra.leeway_seconds', 60);

        try {
            $decoded = JWT::decode($token, $keys);
        } catch (ExpiredException) {
            throw new ApiAuthenticationFailed('expired');
        } catch (BeforeValidException) {
            throw new ApiAuthenticationFailed('not_yet_valid');
        } catch (SignatureInvalidException|UnexpectedValueException|DomainException) {
            throw new ApiAuthenticationFailed('invalid_signature');
        }

        return (array) json_decode((string) json_encode($decoded), true);
    }
}
