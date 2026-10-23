<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Api\ApiErrorResponder;
use App\Services\Api\ApiRequestContext;
use App\Services\Auth\EntraJwksProvider;
use App\Services\Security\ActorChannel;
use App\Services\Security\HumanDecisionGuard;
use Firebase\JWT\JWT;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Application authentication for the external API: an Entra v2 access token, validated for real
 * against a test key, identifies an enabled client application — and nothing else gets in.
 *
 * Runs against a probe route behind the authentication middleware only; customer grants and
 * endpoints have their own tests.
 */
class ApiClientAuthenticationTest extends TestCase
{
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();

        $this->fakeEntraSigningKeys();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });

        Route::middleware(['api', 'api.log', 'api.client'])->get('api/_test/probe', function () {
            $context = app(ApiRequestContext::class);

            return [
                'client_id' => $context->client?->id,
                'mode' => $context->mode,
                'channel_is_api' => app(ActorChannel::class)->isApi(),
            ];
        });
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_valid_application_token_identifies_the_client(): void
    {
        $client = $this->apiClient();

        $response = $this->get('/api/_test/probe', $this->bearer($this->accessToken()));

        $response->assertOk();
        $response->assertJson(['client_id' => $client->id, 'mode' => 'application', 'channel_is_api' => true]);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_an_audience_listed_in_an_array_is_accepted(): void
    {
        $this->apiClient();

        $this->get('/api/_test/probe', $this->bearer($this->accessToken(['aud' => ['other', self::API_AUDIENCE]])))
            ->assertOk();
    }

    public function test_no_token_or_a_non_bearer_header_is_unauthenticated(): void
    {
        $this->apiClient();

        $this->assertUnauthenticated($this->get('/api/_test/probe'), 'missing_token');
        $this->assertUnauthenticated($this->get('/api/_test/probe', ['Authorization' => 'Basic dXNlcjpwYXNz']), 'missing_token');
    }

    public function test_a_malformed_token_is_unauthenticated(): void
    {
        $this->apiClient();

        $this->assertUnauthenticated($this->get('/api/_test/probe', $this->bearer('not-a-jwt')), 'malformed_token');
        $this->assertUnauthenticated($this->get('/api/_test/probe', $this->bearer('a.b.c')), 'malformed_token');
    }

    public function test_only_rs256_is_accepted(): void
    {
        $this->apiClient();
        $claims = $this->claimsOf($this->accessToken());

        $unsigned = JWT::urlsafeB64Encode((string) json_encode(['alg' => 'none', 'kid' => self::KEY_ID]))
            .'.'.JWT::urlsafeB64Encode((string) json_encode($claims)).'.';
        $hmac = JWT::encode($claims, str_repeat('shared-secret-guess-', 4), 'HS256', self::KEY_ID);

        $this->assertUnauthenticated($this->get('/api/_test/probe', $this->bearer($unsigned)), 'unsupported_algorithm');
        $this->assertUnauthenticated($this->get('/api/_test/probe', $this->bearer($hmac)), 'unsupported_algorithm');
    }

    public function test_a_token_signed_with_another_key_is_unauthenticated(): void
    {
        $this->apiClient();

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(signedByStranger: true))),
            'invalid_signature',
        );
        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(kid: 'unknown-key'))),
            'invalid_signature',
        );
    }

    public function test_an_expired_or_not_yet_valid_token_is_unauthenticated(): void
    {
        $this->apiClient();

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['exp' => time() - 3600]))),
            'expired',
        );
        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['nbf' => time() + 3600]))),
            'not_yet_valid',
        );
        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['exp' => null]))),
            'expired',
        );
    }

    public function test_the_issuer_must_be_the_token_tenant_on_v2(): void
    {
        $this->apiClient();

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken([
                'iss' => 'https://login.microsoftonline.com/'.self::OTHER_TENANT.'/v2.0',
            ]))),
            'wrong_issuer',
        );
        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken([
                'iss' => 'https://sts.windows.net/'.self::HOME_TENANT.'/',
            ]))),
            'wrong_issuer',
        );
        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['ver' => '1.0']))),
            'wrong_token_version',
        );
    }

    public function test_the_audience_must_be_the_procynia_api(): void
    {
        $this->apiClient();

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['aud' => '00000003-0000-0000-c000-000000000000']))),
            'wrong_audience',
        );
        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['aud' => null]))),
            'wrong_audience',
        );
    }

    public function test_an_unconfigured_api_refuses_every_token(): void
    {
        $this->apiClient();
        config(['procynia.api.entra.audiences' => []]);

        $this->assertUnauthenticated($this->get('/api/_test/probe', $this->bearer($this->accessToken())), 'api_not_configured');
    }

    public function test_a_tenant_no_client_lives_in_is_refused_before_any_key_is_fetched(): void
    {
        $this->apiClient();

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken([
                'tid' => self::OTHER_TENANT,
                'iss' => 'https://login.microsoftonline.com/'.self::OTHER_TENANT.'/v2.0',
            ]))),
            'unknown_client',
        );

        Http::assertNothingSent();
    }

    public function test_an_unknown_or_disabled_application_is_unauthenticated(): void
    {
        $client = $this->apiClient();

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['azp' => 'ffffffff-6666-4f6f-8f6f-0000000000f6']))),
            'unknown_client',
        );
        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['azp' => null]))),
            'unknown_client',
        );

        $this->apiClient(['entra_app_id' => 'aaaaaaaa-7777-4a7a-8a7a-0000000000a7', 'is_enabled' => false]);

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['azp' => 'aaaaaaaa-7777-4a7a-8a7a-0000000000a7']))),
            'client_disabled',
        );

        $client->update(['is_enabled' => false]);

        $this->assertUnauthenticated($this->get('/api/_test/probe', $this->bearer($this->accessToken())), 'unknown_client');
    }

    public function test_the_same_application_id_from_another_tenant_is_not_the_client(): void
    {
        // The client lives in HOME_TENANT. Another tenant's application presenting the same app id
        // and the read role must not pass — only (azp, tid) together identify the client.
        $this->apiClient();
        $this->apiClient([
            'name' => 'Annen integrasjon',
            'entra_app_id' => 'bbbbbbbb-8888-4b8b-8b8b-0000000000b8',
            'home_tenant_id' => self::OTHER_TENANT,
        ]);

        $this->assertUnauthenticated(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken([
                'tid' => self::OTHER_TENANT,
                'iss' => 'https://login.microsoftonline.com/'.self::OTHER_TENANT.'/v2.0',
            ]))),
            'unknown_client',
        );
    }

    public function test_a_delegated_token_is_refused(): void
    {
        $this->apiClient();

        $this->assertForbidden(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['scp' => 'Procynia.Read', 'idtyp' => null]))),
            'mode_not_allowed',
        );
        $this->assertForbidden(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['idtyp' => 'user']))),
            'mode_not_allowed',
        );
    }

    public function test_the_read_app_role_is_required(): void
    {
        $this->apiClient();

        $this->assertForbidden(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['roles' => null]))),
            'missing_app_role',
        );
        $this->assertForbidden(
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['roles' => ['Procynia.Propose.All', 'Procynia.Read']]))),
            'missing_app_role',
        );
    }

    public function test_every_refusal_reads_the_same_to_the_client(): void
    {
        $this->apiClient();

        $bodies = [];

        foreach ([
            $this->get('/api/_test/probe'),
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(signedByStranger: true))),
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['exp' => time() - 3600]))),
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['aud' => 'other']))),
            $this->get('/api/_test/probe', $this->bearer($this->accessToken(['azp' => 'ffffffff-6666-4f6f-8f6f-0000000000f6']))),
        ] as $response) {
            $response->assertUnauthorized();
            $bodies[] = collect($response->json('error'))->except('request_id')->all();
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $bodies)));
        $this->assertSame(['code' => 'unauthenticated', 'message' => ApiErrorResponder::MESSAGES['unauthenticated']], $bodies[0]);
    }

    public function test_signing_keys_are_fetched_once_and_their_absence_is_a_503(): void
    {
        $this->apiClient();

        $this->get('/api/_test/probe', $this->bearer($this->accessToken()))->assertOk();
        $this->get('/api/_test/probe', $this->bearer($this->accessToken()))->assertOk();

        Http::assertSentCount(1);

        cache()->flush();
        $this->instance(EntraJwksProvider::class, new class extends EntraJwksProvider
        {
            public function forTenant(string $tenantId): array
            {
                throw new RuntimeException('Could not retrieve the signing keys for the identity provider.');
            }
        });

        $response = $this->get('/api/_test/probe', $this->bearer($this->accessToken()));
        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'service_unavailable');
    }

    public function test_refusals_and_requests_are_logged_without_the_token(): void
    {
        $client = $this->apiClient();
        $token = $this->accessToken(signedByStranger: true);
        $valid = $this->accessToken();

        $this->get('/api/_test/probe', $this->bearer($token));
        $this->get('/api/_test/probe?status=closed', $this->bearer($valid));

        $rejected = collect($this->logged)->firstWhere('context.event', 'api_token_rejected');
        $this->assertNotNull($rejected);
        $this->assertSame('warning', $rejected['level']);
        $this->assertSame('invalid_signature', $rejected['context']['reason']);
        $this->assertSame(self::CLIENT_APP_ID, $rejected['context']['azp']);
        $this->assertSame(self::HOME_TENANT, $rejected['context']['tid']);

        $requests = collect($this->logged)->where('context.event', 'api_request')->values();
        $this->assertCount(2, $requests);
        $this->assertSame(401, $requests[0]['context']['status']);
        $this->assertSame(200, $requests[1]['context']['status']);
        $this->assertSame($client->id, $requests[1]['context']['api_client_id']);
        $this->assertSame('application', $requests[1]['context']['mode']);

        $everything = json_encode($this->logged);
        foreach ([$token, $valid, explode('.', $valid)[2], 'Bearer', 'closed'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $everything);
        }
    }

    public function test_no_human_decision_passes_inside_an_api_request(): void
    {
        $this->apiClient();
        $person = User::query()->create([
            'name' => 'Kari Nordmann',
            'email' => 'kari-'.uniqid().'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        Route::middleware(['api', 'api.log', 'api.client'])->get('api/_test/decide', fn () => [
            'allowed' => app(HumanDecisionGuard::class)->allows($person),
        ]);

        $this->assertTrue(app(HumanDecisionGuard::class)->allows($person), 'Outside /api the same person decides.');
        $this->get('/api/_test/decide', $this->bearer($this->accessToken()))->assertOk()->assertJson(['allowed' => false]);
    }

    public function test_the_api_answers_no_cross_origin_request(): void
    {
        $this->apiClient();

        $response = $this->call('OPTIONS', '/api/_test/probe', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @return array<string, mixed> */
    private function claimsOf(string $token): array
    {
        return (array) json_decode(JWT::urlsafeB64Decode(explode('.', $token)[1]), true);
    }

    private function assertUnauthenticated(TestResponse $response, string $reason): void
    {
        $response->assertUnauthorized();
        $response->assertJsonPath('error.code', 'unauthenticated');
        $response->assertHeader('WWW-Authenticate', 'Bearer');
        $this->assertLastRefusal($reason);
    }

    private function assertForbidden(TestResponse $response, string $reason): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'forbidden');
        $this->assertLastRefusal($reason);
    }

    private function assertLastRefusal(string $reason): void
    {
        $refusal = collect($this->logged)->where('context.event', 'api_token_rejected')->last();

        $this->assertNotNull($refusal, 'The refusal was not logged.');
        $this->assertSame($reason, $refusal['context']['reason']);
    }
}
