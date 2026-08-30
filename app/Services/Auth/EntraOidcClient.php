<?php

namespace App\Services\Auth;

use App\Models\IdentityProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;

/**
 * OpenID Connect Authorization Code Flow against Microsoft Entra ID.
 *
 * Authorization Code with a confidential client. No implicit flow: the token never travels through
 * the browser, it is fetched server-to-server with the client secret.
 *
 * Provider configuration is per request rather than per application, because Procynia serves several
 * customers and each has its own Entra tenant. league/oauth2-client takes its configuration at
 * construction, which is exactly the shape that needs.
 *
 * The ID token is validated locally even though it arrives over TLS straight from Microsoft. Signature
 * against the tenant's published keys, then issuer, audience, expiry and nonce. Transport security
 * says the token came from Microsoft; it does not say the token was minted for us, for this tenant,
 * or for this login attempt.
 */
class EntraOidcClient
{
    public function __construct(private readonly EntraConfig $config) {}

    /**
     * Values the caller must keep in the session until the callback returns.
     *
     * @return array{url: string, state: string, nonce: string}
     */
    public function authorizationRequest(IdentityProvider $provider): array
    {
        $this->config->assertUsable();

        // Nonce binds the ID token to this specific authorization request, which is what stops a
        // token captured from another login being replayed into ours. State covers CSRF on the
        // callback itself.
        $nonce = Str::random(40);

        $oauth = $this->provider($provider);

        $url = $oauth->getAuthorizationUrl([
            'scope' => implode(' ', $this->config->scopes()),
            'nonce' => $nonce,
            // Ask Entra to pick the right tenant flow; the tenant is already in the endpoint URL.
            'response_mode' => 'query',
        ]);

        return [
            'url' => $url,
            'state' => $oauth->getState(),
            'nonce' => $nonce,
        ];
    }

    /**
     * Exchange the authorization code and return the validated ID-token claims.
     *
     * Only the claims are returned. Access and refresh tokens are deliberately not handed back:
     * Procynia authenticates and then uses its own session, and a token it never stores is a token
     * that cannot leak.
     *
     * @return array<string, mixed>
     */
    public function exchangeCodeForClaims(IdentityProvider $provider, string $code, string $expectedNonce): array
    {
        $this->config->assertUsable();

        $token = $this->provider($provider)->getAccessToken('authorization_code', [
            'code' => $code,
        ]);

        $idToken = $token->getValues()['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw new RuntimeException('The token response contained no id_token.');
        }

        return $this->validateIdToken($provider, $idToken, $expectedNonce);
    }

    /**
     * @return array<string, mixed>
     */
    public function validateIdToken(IdentityProvider $provider, string $idToken, string $expectedNonce): array
    {
        JWT::$leeway = $this->config->leewaySeconds();

        // Signature. Throws on an unknown key id, a wrong signature or an expired token.
        $claims = (array) json_decode(
            (string) json_encode(JWT::decode($idToken, JWK::parseKeySet($this->jwks($provider)))),
            true,
        );

        // Minted by the tenant we expect. Without this, a token from any Entra tenant would pass the
        // signature check against that tenant's own keys.
        $issuer = (string) ($claims['iss'] ?? '');

        if ($issuer !== $provider->issuer) {
            throw new RuntimeException('The id_token issuer does not match the configured provider.');
        }

        // Minted for us. A token issued for a different application must not authenticate here.
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (! in_array($provider->client_id, array_map('strval', $audiences), true)) {
            throw new RuntimeException('The id_token audience does not match the configured client id.');
        }

        // Minted for this login attempt.
        if (! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('The id_token nonce does not match the authorization request.');
        }

        // Belt and braces: JWT::decode already enforces exp, but an id_token without one would
        // otherwise be accepted forever.
        if (! isset($claims['exp'])) {
            throw new RuntimeException('The id_token has no expiry.');
        }

        if (($claims['tid'] ?? null) !== null && (string) $claims['tid'] !== $provider->tenant_id) {
            throw new RuntimeException('The id_token tenant does not match the configured provider.');
        }

        return $claims;
    }

    private function provider(IdentityProvider $provider): GenericProvider
    {
        $base = 'https://login.microsoftonline.com/'.$provider->tenant_id.'/oauth2/v2.0';

        return new GenericProvider([
            'clientId' => $provider->client_id,
            'clientSecret' => $this->config->clientSecret(),
            'redirectUri' => $this->config->redirectUri(),
            'urlAuthorize' => $base.'/authorize',
            'urlAccessToken' => $base.'/token',
            // Procynia reads identity from the id_token and never calls a resource endpoint, so
            // there is no user-info URL to configure.
            'urlResourceOwnerDetails' => '',
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * The tenant's signing keys.
     *
     * Cached for an hour: Microsoft rotates these, so pinning them would break logins, and fetching
     * them on every callback would put an external dependency in the login path.
     *
     * @return array<string, mixed>
     */
    private function jwks(IdentityProvider $provider): array
    {
        return Cache::remember(
            'entra:jwks:'.$provider->tenant_id,
            now()->addHour(),
            function () use ($provider): array {
                $url = 'https://login.microsoftonline.com/'.$provider->tenant_id.'/discovery/v2.0/keys';

                $response = Http::timeout(10)->get($url);

                if (! $response->successful()) {
                    throw new RuntimeException('Could not retrieve the signing keys for the identity provider.');
                }

                return (array) $response->json();
            },
        );
    }
}
