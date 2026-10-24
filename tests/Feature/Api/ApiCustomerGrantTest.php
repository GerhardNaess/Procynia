<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The customer side of every API call (D1, D6): the Procynia-Customer header chooses among the
 * application's active grants, and the request then reads as that grant's integration identity.
 * Without an active, consistent grant the answer is one and the same 403.
 */
class ApiCustomerGrantTest extends TestCase
{
    use CreatesApiCustomerGrants;
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
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_me_describes_the_client_the_customer_and_the_integration_identity(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer('Nordlys AS');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW]);
        $this->grantTo($client, $customer, $identity);

        $response = $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'client' => ['id' => $client->id, 'name' => 'Siggjo dashboards'],
                'customer' => ['id' => $customer->id, 'name' => 'Nordlys AS'],
                'mode' => 'application',
                'actor' => ['id' => $identity->id, 'name' => $identity->name, 'type' => 'integration'],
                'modules' => [
                    'risk' => ['entitled' => true, 'readable' => true, 'business_areas' => 'all'],
                    'deviations' => ['entitled' => true, 'readable' => false, 'business_areas' => []],
                    'objectives' => ['entitled' => true, 'readable' => false, 'business_areas' => []],
                    // Customer-wide: no fagområder at all, so null rather than a list.
                    'management_review' => ['entitled' => true, 'readable' => false, 'business_areas' => null],
                    // The customer has not ordered Etterlevelse og revisjon.
                    'compliance' => ['entitled' => false, 'readable' => false, 'business_areas' => null],
                ],
            ],
            'meta' => [
                'as_of' => $response->json('meta.as_of'),
                'request_id' => $response->json('meta.request_id'),
            ],
        ]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $response->json('meta.as_of'));
        $response->assertHeader('X-Request-Id', $response->json('meta.request_id'));
    }

    public function test_me_names_only_the_fagomrader_the_identity_reaches(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $beredskap = $this->businessArea($customer, 'Beredskap');
        $this->businessArea($customer, 'Økonomi');
        $identity = $this->integrationIdentity($customer);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW], [$beredskap]);
        $this->grantTo($client, $customer, $identity);

        $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer))
            ->assertOk()
            ->assertJsonPath('data.modules.risk.business_areas', [['id' => $beredskap->id, 'name' => 'Beredskap']]);
    }

    public function test_me_reports_risk_as_not_readable_without_the_module_or_the_permission(): void
    {
        $client = $this->apiClient();

        $withoutModule = $this->apiCustomer('Uten Risiko', withRisk: false);
        $identity = $this->integrationIdentity($withoutModule);
        $this->giveRole($identity, [CustomerPermissionCatalog::RISK_VIEW]);
        $this->grantTo($client, $withoutModule, $identity);

        $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $withoutModule))
            ->assertOk()
            ->assertJsonPath('data.modules.risk', ['entitled' => false, 'readable' => false, 'business_areas' => []]);

        $withoutRole = $this->apiCustomer('Uten rolle');
        $this->grantTo($client, $withoutRole);

        $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $withoutRole))
            ->assertOk()
            ->assertJsonPath('data.modules.risk', ['entitled' => true, 'readable' => false, 'business_areas' => []]);
    }

    public function test_one_application_reads_each_granted_customer_separately(): void
    {
        $client = $this->apiClient();
        $first = $this->apiCustomer('Første AS');
        $second = $this->apiCustomer('Andre AS');
        $firstGrant = $this->grantTo($client, $first);
        $secondGrant = $this->grantTo($client, $second);
        $token = $this->accessToken();

        $this->get('/api/v1/me', $this->asCustomer($token, $first))
            ->assertOk()
            ->assertJsonPath('data.customer.id', $first->id)
            ->assertJsonPath('data.actor.id', $firstGrant->integration_user_id);

        $this->get('/api/v1/me', $this->asCustomer($token, $second))
            ->assertOk()
            ->assertJsonPath('data.customer.id', $second->id)
            ->assertJsonPath('data.actor.id', $secondGrant->integration_user_id);
    }

    public function test_without_an_active_grant_for_the_customer_the_request_is_forbidden(): void
    {
        $client = $this->apiClient();
        $granted = $this->apiCustomer('Tildelt');
        $notGranted = $this->apiCustomer('Ikke tildelt');
        $this->grantTo($client, $granted);
        $token = $this->accessToken();

        $this->assertRefused($this->get('/api/v1/me', ['Authorization' => 'Bearer '.$token]), 'no_active_grant');
        $this->assertRefused($this->get('/api/v1/me', $this->asCustomer($token, $notGranted)), 'no_active_grant');
        $this->assertRefused($this->get('/api/v1/me', $this->asCustomer($token, 999999999)), 'no_active_grant');

        foreach (['abc', '0', '-1', $granted->id.' ', '1e3', str_repeat('9', 30)] as $header) {
            $this->assertRefused(
                $this->get('/api/v1/me', ['Authorization' => 'Bearer '.$token, 'Procynia-Customer' => $header]),
                'no_active_grant',
            );
        }
    }

    public function test_a_revoked_grant_stops_working_on_the_next_request(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $grant = $this->grantTo($client, $customer);
        $token = $this->accessToken();

        $this->get('/api/v1/me', $this->asCustomer($token, $customer))->assertOk();

        $grant->update(['revoked_at' => now()]);

        $this->assertRefused($this->get('/api/v1/me', $this->asCustomer($token, $customer)), 'no_active_grant');
    }

    public function test_another_applications_grant_is_not_usable(): void
    {
        $this->apiClient();
        $other = $this->apiClient(['name' => 'Annen klient', 'entra_app_id' => 'cccccccc-1212-4c1c-8c1c-0000000000c1']);
        $customer = $this->apiCustomer();
        $this->grantTo($other, $customer);

        $this->assertRefused($this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer)), 'no_active_grant');
    }

    public function test_an_inactive_customer_or_identity_is_forbidden(): void
    {
        $client = $this->apiClient();

        $inactiveCustomer = $this->apiCustomer('Deaktivert', active: false);
        $this->grantTo($client, $inactiveCustomer);
        $this->assertRefused($this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $inactiveCustomer)), 'customer_inactive');

        $customer = $this->apiCustomer();
        $this->grantTo($client, $customer, $this->integrationIdentity($customer, ['is_active' => false]));
        $this->assertRefused($this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer)), 'actor_inactive');
    }

    public function test_a_grant_that_names_a_person_or_another_customers_identity_is_refused(): void
    {
        $client = $this->apiClient();

        $customer = $this->apiCustomer();
        $person = User::query()->create([
            'name' => 'Kari Nordmann',
            'email' => 'kari-'.Str::lower(Str::random(8)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $this->grantTo($client, $customer, $person);

        $response = $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer));
        $this->assertRefused($response, 'grant_inconsistent');
        $this->assertStringNotContainsString('Kari', (string) $response->getContent());

        $elsewhere = $this->apiCustomer('Annen kunde');
        $target = $this->apiCustomer('Mål');
        $this->grantTo($client, $target, $this->integrationIdentity($elsewhere));

        $this->assertRefused($this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $target)), 'grant_inconsistent');
    }

    public function test_every_grant_refusal_reads_the_same(): void
    {
        $client = $this->apiClient();
        $revoked = $this->apiCustomer('Tilbakekalt');
        $this->grantTo($client, $revoked, overrides: ['revoked_at' => now()]);
        $inactive = $this->apiCustomer('Inaktiv', active: false);
        $this->grantTo($client, $inactive);
        $token = $this->accessToken();

        $bodies = collect([
            $this->get('/api/v1/me', ['Authorization' => 'Bearer '.$token]),
            $this->get('/api/v1/me', $this->asCustomer($token, 999999999)),
            $this->get('/api/v1/me', $this->asCustomer($token, $revoked)),
            $this->get('/api/v1/me', $this->asCustomer($token, $inactive)),
        ])->map(function (TestResponse $response): string {
            $response->assertForbidden();

            return (string) json_encode(collect($response->json('error'))->except('request_id')->all());
        });

        $this->assertCount(1, $bodies->unique());
    }

    public function test_a_grant_records_when_it_was_last_used_at_most_once_a_minute(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $grant = $this->grantTo($client, $customer);
        $token = $this->accessToken();

        $this->travelTo(now()->startOfMinute());
        $this->get('/api/v1/me', $this->asCustomer($token, $customer))->assertOk();
        $first = $grant->fresh()->last_used_at;
        $this->assertNotNull($first);

        $this->travel(30)->seconds();
        $this->get('/api/v1/me', $this->asCustomer($token, $customer))->assertOk();
        $this->assertTrue($grant->fresh()->last_used_at->equalTo($first));

        $this->travel(45)->seconds();
        $this->get('/api/v1/me', $this->asCustomer($token, $customer))->assertOk();
        $this->assertTrue($grant->fresh()->last_used_at->greaterThan($first));
    }

    public function test_requests_are_limited_per_customer(): void
    {
        config(['procynia.api.rate_limit_per_minute' => 2]);
        $client = $this->apiClient();
        $first = $this->apiCustomer('Første');
        $second = $this->apiCustomer('Andre');
        $this->grantTo($client, $first);
        $this->grantTo($client, $second);
        $token = $this->accessToken();

        $this->get('/api/v1/me', $this->asCustomer($token, $first))->assertOk();
        $this->get('/api/v1/me', $this->asCustomer($token, $first))->assertOk();

        $limited = $this->get('/api/v1/me', $this->asCustomer($token, $first));
        $limited->assertStatus(429);
        $limited->assertJsonPath('error.code', 'rate_limited');
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertNotNull(collect($this->logged)->firstWhere('context.event', 'api_throttled'));

        $this->get('/api/v1/me', $this->asCustomer($token, $second))->assertOk();
    }

    public function test_the_request_log_names_customer_and_identity_and_no_session_is_started(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $grant = $this->grantTo($client, $customer);

        $response = $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer));

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());

        $line = collect($this->logged)->firstWhere('context.event', 'api_request');
        $this->assertSame('api.v1.me', $line['context']['route']);
        $this->assertSame($client->id, $line['context']['api_client_id']);
        $this->assertSame($customer->id, $line['context']['customer_id']);
        $this->assertSame($grant->integration_user_id, $line['context']['actor_user_id']);
        $this->assertSame(200, $line['context']['status']);
    }

    public function test_an_integration_identity_cannot_sign_in_or_use_the_web_application(): void
    {
        $customer = $this->apiCustomer();
        $identity = $this->integrationIdentity($customer, ['password' => bcrypt('known-password-1!')]);

        $this->post('/login', ['email' => $identity->email, 'password' => 'known-password-1!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($identity)->get('/app')->assertForbidden();
        $this->actingAs($identity)->get('/admin')->assertForbidden();
    }

    private function assertRefused(TestResponse $response, string $reason): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'forbidden');

        $refusal = collect($this->logged)->where('context.event', 'api_access_refused')->last();
        $this->assertNotNull($refusal, 'The refusal was not logged.');
        $this->assertSame($reason, $refusal['context']['reason']);
    }
}
