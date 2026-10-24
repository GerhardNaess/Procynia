<?php

namespace Tests\Feature\Security;

use App\Models\IdentityProvider;
use App\Services\Auth\EntraOidcClient;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * Sign-in validates Entra ID tokens against the tenant's published keys — real signatures, a faked
 * key endpoint, no Entra.
 *
 * The key list has the shape Microsoft actually publishes (checked 2026-10-24 against
 * login.microsoftonline.com/common/discovery/v2.0/keys): kty, use, kid, x5t, n, e, x5c, issuer and
 * cloud_instance_name — and no `alg`. Before the fix, parsing that list threw and every real sign-in
 * failed. RS256 is now named explicitly, and every other control stays: signature, issuer (which
 * also pins the v2 endpoint), audience, tenant, nonce and expiry.
 */
class EntraIdTokenValidationTest extends TestCase
{
    use IssuesEntraAccessTokens;

    private const LOGIN_CLIENT_ID = 'c0ffee00-9999-4999-8999-000000000099';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('procynia.auth.entra.leeway_seconds', 60);
        cache()->flush();

        Http::fake(['login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response(['keys' => [
            $this->microsoftShapedKey('another-key', 'bm90LWEtcmVhbC1tb2R1bHVz'),
            $this->microsoftShapedKey(self::KEY_ID, $this->testJwk()['n'], $this->testJwk()['e']),
        ]])]);
    }

    public function test_a_key_list_without_alg_validates_a_real_rs256_token(): void
    {
        $claims = app(EntraOidcClient::class)->validateIdToken($this->provider(), $this->idToken(), 'nonce-1');

        $this->assertSame('person@kunde.no', $claims['email']);
        $this->assertSame(self::HOME_TENANT, $claims['tid']);
    }

    public function test_a_token_not_signed_with_rs256_is_refused_before_any_key_is_fetched(): void
    {
        $claims = $this->claims();
        $unsigned = JWT::urlsafeB64Encode((string) json_encode(['alg' => 'none', 'kid' => self::KEY_ID, 'typ' => 'JWT']))
            .'.'.JWT::urlsafeB64Encode((string) json_encode($claims)).'.';
        $hmac = JWT::encode($claims, str_repeat('guessed-shared-secret-', 4), 'HS256', self::KEY_ID);
        $rs512 = JWT::encode($claims, $this->privateKey(), 'RS512', self::KEY_ID);

        foreach (['none' => $unsigned, 'HS256' => $hmac, 'RS512' => $rs512, 'garbage' => 'not.a.token'] as $what => $token) {
            $this->assertRefused(fn () => app(EntraOidcClient::class)->validateIdToken($this->provider(), $token, 'nonce-1'), $what);
        }

        Http::assertNothingSent();
    }

    public function test_every_other_control_still_holds(): void
    {
        $client = app(EntraOidcClient::class);

        $refused = [
            'another key' => [$this->accessToken(signedByStranger: true), 'nonce-1'],
            'unknown key id' => [$this->signedWithTestKey($this->claims(), 'unknown-kid'), 'nonce-1'],
            'wrong nonce' => [$this->idToken(), 'another-nonce'],
            'wrong audience' => [$this->idToken(['aud' => 'someone-else']), 'nonce-1'],
            'wrong issuer' => [$this->idToken(['iss' => 'https://login.microsoftonline.com/'.self::OTHER_TENANT.'/v2.0']), 'nonce-1'],
            'v1 issuer' => [$this->idToken(['iss' => 'https://sts.windows.net/'.self::HOME_TENANT.'/']), 'nonce-1'],
            'wrong tenant' => [$this->idToken(['tid' => self::OTHER_TENANT]), 'nonce-1'],
            'expired' => [$this->idToken(['exp' => time() - 3600]), 'nonce-1'],
            'not yet valid' => [$this->idToken(['nbf' => time() + 3600]), 'nonce-1'],
            'no expiry' => [$this->idToken(['exp' => null]), 'nonce-1'],
        ];

        foreach ($refused as $what => [$token, $nonce]) {
            $this->assertRefused(fn () => $client->validateIdToken($this->provider(), $token, $nonce), $what);
        }
    }

    public function test_a_key_that_names_another_algorithm_does_not_verify_an_rs256_token(): void
    {
        cache()->flush();
        Http::fake(['login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response(['keys' => [
            ['alg' => 'RS512'] + $this->microsoftShapedKey(self::KEY_ID, $this->testJwk()['n'], $this->testJwk()['e']),
        ]])]);

        $this->assertRefused(fn () => app(EntraOidcClient::class)->validateIdToken($this->provider(), $this->idToken(), 'nonce-1'), 'RS512 key');
    }

    private function assertRefused(callable $attempt, string $what): void
    {
        try {
            $attempt();
            $this->fail("The ID token was accepted: {$what}.");
        } catch (RuntimeException|UnexpectedValueException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, string|list<string>> */
    private function microsoftShapedKey(string $kid, string $n, string $e = 'AQAB'): array
    {
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => $kid,
            'x5t' => $kid,
            'n' => $n,
            'e' => $e,
            'x5c' => ['MIIC/TCCAeWgAwIBAgIIZm9yLXNoYXBlLW9ubHk='],
            'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0',
            'cloud_instance_name' => 'microsoftonline.com',
        ];
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

    /** @return array<string, mixed> */
    private function claims(array $overrides = []): array
    {
        return array_filter(array_merge([
            'iss' => 'https://login.microsoftonline.com/'.self::HOME_TENANT.'/v2.0',
            'aud' => self::LOGIN_CLIENT_ID,
            'tid' => self::HOME_TENANT,
            'nonce' => 'nonce-1',
            'email' => 'person@kunde.no',
            'ver' => '2.0',
            'iat' => time() - 10,
            'exp' => time() + 600,
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /** @param  array<string, mixed>  $overrides */
    private function idToken(array $overrides = []): string
    {
        return $this->signedWithTestKey($this->claims($overrides));
    }
}
