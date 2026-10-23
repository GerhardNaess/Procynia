<?php

namespace Tests\Feature\Security;

use App\Models\IdentityProvider;
use App\Services\Auth\EntraOidcClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Sign-in still validates ID tokens against the tenant's keys now that fetching them is shared
 * with the external API (EntraJwksProvider). Real signatures, a faked JWKS endpoint.
 */
class EntraIdTokenValidationTest extends TestCase
{
    use IssuesEntraAccessTokens;

    private const LOGIN_CLIENT_ID = 'c0ffee00-9999-4999-8999-000000000099';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('procynia.auth.entra.leeway_seconds', 60);

        // Sign-in's JWKS parsing reads each key's own `alg`; the API names RS256 itself.
        Http::fake(['login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response(['keys' => [$this->testJwk(withAlg: true)]])]);
    }

    public function test_a_valid_id_token_returns_its_claims(): void
    {
        $claims = app(EntraOidcClient::class)->validateIdToken($this->provider(), $this->idToken(), 'nonce-1');

        $this->assertSame('person@kunde.no', $claims['email']);
    }

    public function test_a_wrong_nonce_audience_or_signature_is_refused(): void
    {
        $client = app(EntraOidcClient::class);

        foreach ([
            fn () => $client->validateIdToken($this->provider(), $this->idToken(), 'another-nonce'),
            fn () => $client->validateIdToken($this->provider(), $this->idToken(['aud' => 'someone-else']), 'nonce-1'),
            fn () => $client->validateIdToken($this->provider(), $this->accessToken(signedByStranger: true), 'nonce-1'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The ID token was accepted.');
            } catch (RuntimeException|UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function provider(): IdentityProvider
    {
        return new IdentityProvider([
            'provider' => IdentityProvider::PROVIDER_ENTRA,
            'tenant_id' => self::HOME_TENANT,
            'client_id' => self::LOGIN_CLIENT_ID,
            'issuer' => 'https://login.microsoftonline.com/'.self::HOME_TENANT.'/v2.0',
            'is_enabled' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function idToken(array $overrides = []): string
    {
        return $this->signedWithTestKey(array_merge([
            'iss' => 'https://login.microsoftonline.com/'.self::HOME_TENANT.'/v2.0',
            'aud' => self::LOGIN_CLIENT_ID,
            'tid' => self::HOME_TENANT,
            'nonce' => 'nonce-1',
            'email' => 'person@kunde.no',
            'iat' => time() - 10,
            'exp' => time() + 600,
        ], $overrides));
    }
}
