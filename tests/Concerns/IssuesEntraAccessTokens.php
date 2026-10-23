<?php

namespace Tests\Concerns;

use App\Models\ApiClient;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Entra v2 access tokens for the external API, signed with a key pair made for the test.
 *
 * Nothing talks to Microsoft and no Entra secret is involved: the tenant's JWKS endpoint is faked
 * with the test key's public half, so signature, issuer, audience and expiry are validated for
 * real by EntraAccessTokenValidator.
 */
trait IssuesEntraAccessTokens
{
    protected const API_AUDIENCE = '0a5e1c3d-1111-4a1a-9a1a-0000000000a1';

    protected const HOME_TENANT = '7d1c2b3a-2222-4b2b-8b2b-0000000000b2';

    protected const OTHER_TENANT = '9e8f7a6b-3333-4c3c-9c3c-0000000000c3';

    protected const CLIENT_APP_ID = '4f3e2d1c-4444-4d4d-8d4d-0000000000d4';

    protected const KEY_ID = 'test-key-1';

    /** @var array{private: string, n: string, e: string}|null */
    private static ?array $testKey = null;

    /** @var array{private: string, n: string, e: string}|null */
    private static ?array $strangerKey = null;

    protected function fakeEntraSigningKeys(): void
    {
        Config::set('procynia.api.entra.audiences', [self::API_AUDIENCE]);
        Config::set('procynia.api.entra.read_role', 'Procynia.Read.All');

        Http::fake(['login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response(['keys' => [$this->testJwk()]])]);
    }

    /**
     * The test key's public half as a JWK, without `alg` unless asked: the API names RS256 itself.
     *
     * @return array<string, string>
     */
    protected function testJwk(bool $withAlg = false): array
    {
        return array_merge([
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => self::KEY_ID,
            'n' => self::testKey()['n'],
            'e' => self::testKey()['e'],
        ], $withAlg ? ['alg' => 'RS256'] : []);
    }

    /**
     * A JWT signed with the test key, with exactly the given claims. For sign-in ID tokens.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function signedWithTestKey(array $claims): string
    {
        return JWT::encode($claims, self::testKey()['private'], 'RS256', self::KEY_ID);
    }

    protected function apiClient(array $overrides = []): ApiClient
    {
        return ApiClient::query()->create(array_merge([
            'name' => 'Siggjo dashboards',
            'entra_app_id' => self::CLIENT_APP_ID,
            'home_tenant_id' => self::HOME_TENANT,
            'is_enabled' => true,
        ], $overrides));
    }

    /**
     * A valid application-mode token; any claim can be overridden, and null removes it.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function accessToken(array $claims = [], bool $signedByStranger = false, string $kid = self::KEY_ID): string
    {
        $now = time();

        $payload = array_merge([
            'aud' => self::API_AUDIENCE,
            'iss' => 'https://login.microsoftonline.com/'.self::HOME_TENANT.'/v2.0',
            'tid' => self::HOME_TENANT,
            'azp' => self::CLIENT_APP_ID,
            'azpacr' => '2',
            'oid' => '5a4b3c2d-5555-4e5e-9e5e-0000000000e5',
            'sub' => '5a4b3c2d-5555-4e5e-9e5e-0000000000e5',
            'idtyp' => 'app',
            'roles' => ['Procynia.Read.All'],
            'ver' => '2.0',
            'iat' => $now - 60,
            'nbf' => $now - 60,
            'exp' => $now + 3600,
        ], $claims);

        $payload = array_filter($payload, fn (mixed $value): bool => $value !== null);
        $key = $signedByStranger ? self::strangerKey() : self::testKey();

        return JWT::encode($payload, $key['private'], 'RS256', $kid);
    }

    /** @return array<string, string> */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** @return array{private: string, n: string, e: string} */
    private static function testKey(): array
    {
        return self::$testKey ??= self::makeKey();
    }

    /** @return array{private: string, n: string, e: string} */
    private static function strangerKey(): array
    {
        return self::$strangerKey ??= self::makeKey();
    }

    /** @return array{private: string, n: string, e: string} */
    private static function makeKey(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);

        return [
            'private' => $private,
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ];
    }
}
