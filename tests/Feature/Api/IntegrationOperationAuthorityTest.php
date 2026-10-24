<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\ApiClientCustomerGrant;
use App\Models\Customer;
use App\Models\User;
use App\Services\Api\ApiRequestContext;
use App\Services\Api\IntegrationOperationAuthority;
use App\Services\Security\ActorChannel;
use App\Services\Security\ApiActorChannel;
use App\Services\Security\InteractiveActorChannel;
use App\Support\ApiOperationCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The one check behind every API write operation: an active integration, a catalogued operation,
 * an API request as that identity with the write app role, an active grant of an enabled client
 * that allows the operation, and the operation's module. Each condition alone refuses.
 */
class IntegrationOperationAuthorityTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    private const OPERATION = ApiOperationCatalog::RISK_TREATMENT_ACTION_CREATE;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_the_catalog_is_closed_and_names_its_three_operations_only(): void
    {
        $this->assertSame([
            self::OPERATION,
            ApiOperationCatalog::IMPROVEMENT_CASE_CREATE,
            ApiOperationCatalog::IMPROVEMENT_ACTION_CREATE,
        ], ApiOperationCatalog::all());
        $this->assertSame('risk', ApiOperationCatalog::module(self::OPERATION));
        $this->assertSame('improvements', ApiOperationCatalog::module(ApiOperationCatalog::IMPROVEMENT_CASE_CREATE));
        $this->assertSame('improvements', ApiOperationCatalog::module(ApiOperationCatalog::IMPROVEMENT_ACTION_CREATE));
        $this->assertFalse(ApiOperationCatalog::exists('improvement.edit'));
        $this->assertFalse(ApiOperationCatalog::exists('risk.edit'));
        $this->assertNull(ApiOperationCatalog::module('risk.edit'));

        foreach (ApiOperationCatalog::all() as $operation) {
            $this->assertNotSame(ApiOperationCatalog::labelKey($operation), __((string) ApiOperationCatalog::labelKey($operation)));
        }
    }

    public function test_an_allowed_operation_passes_when_every_condition_holds(): void
    {
        [$client, , $identity] = $this->allowedSetup();
        $this->inApiRequest($client, $identity, writeRole: true);

        $this->assertNull(app(IntegrationOperationAuthority::class)->refusal($identity, self::OPERATION));
    }

    public function test_each_missing_condition_refuses(): void
    {
        $authority = fn (): IntegrationOperationAuthority => app(IntegrationOperationAuthority::class);

        // No write role in the token.
        [$client, , $identity] = $this->allowedSetup();
        $this->inApiRequest($client, $identity, writeRole: false);
        $this->assertSame('missing_write_role', $authority()->refusal($identity, self::OPERATION));

        // Operation not switched on for the grant.
        [$client, , $identity, $grant] = $this->allowedSetup();
        $grant->forceFill(['allowed_operations' => []])->save();
        $this->inApiRequest($client, $identity, writeRole: true);
        $this->assertSame('operation_not_allowed', $authority()->refusal($identity, self::OPERATION));

        // An operation outside the catalog, even if written into the grant.
        [$client, , $identity, $grant] = $this->allowedSetup();
        $grant->forceFill(['allowed_operations' => [self::OPERATION, 'risk.edit']])->save();
        $this->inApiRequest($client, $identity, writeRole: true);
        $this->assertSame('unknown_operation', $authority()->refusal($identity, 'risk.edit'));

        // Revoked grant.
        [$client, , $identity, $grant] = $this->allowedSetup();
        $grant->forceFill(['revoked_at' => now()])->save();
        $this->inApiRequest($client, $identity, writeRole: true);
        $this->assertSame('no_active_grant', $authority()->refusal($identity, self::OPERATION));

        // Disabled client.
        [$client, , $identity] = $this->allowedSetup();
        $client->forceFill(['is_enabled' => false])->save();
        $this->inApiRequest($client, $identity, writeRole: true);
        $this->assertSame('no_active_grant', $authority()->refusal($identity, self::OPERATION));

        // Another client calling with this identity in its context.
        [, , $identity] = $this->allowedSetup();
        $stranger = $this->apiClient(['name' => 'Annen', 'entra_app_id' => (string) Str::uuid()]);
        $this->inApiRequest($stranger, $identity, writeRole: true);
        $this->assertSame('no_active_grant', $authority()->refusal($identity, self::OPERATION));

        // Module not ordered.
        [$client, $customer, $identity] = $this->allowedSetup(withRisk: false);
        $this->inApiRequest($client, $identity, writeRole: true);
        $this->assertSame('module_unavailable', $authority()->refusal($identity, self::OPERATION));

        // Inactive identity.
        [$client, , $identity] = $this->allowedSetup();
        $identity->forceFill(['is_active' => false])->save();
        $this->inApiRequest($client, $identity, writeRole: true);
        $this->assertSame('not_an_active_integration', $authority()->refusal($identity, self::OPERATION));
    }

    public function test_outside_an_api_request_an_integration_is_refused(): void
    {
        [$client, , $identity] = $this->allowedSetup();
        $this->inApiRequest($client, $identity, writeRole: true);
        $this->app->instance(ActorChannel::class, new InteractiveActorChannel);

        $this->assertSame('not_an_api_request', app(IntegrationOperationAuthority::class)->refusal($identity, self::OPERATION));

        // The API context of another identity does not count for this one.
        [$otherClient, , $other] = $this->allowedSetup();
        $this->app->instance(ActorChannel::class, new ApiActorChannel);
        $this->inApiRequest($otherClient, $other, writeRole: true);

        $this->assertSame('not_an_api_request', app(IntegrationOperationAuthority::class)->refusal($identity, self::OPERATION));
    }

    public function test_a_person_is_never_allowed_an_operation(): void
    {
        [$client, $customer] = $this->allowedSetup();
        $person = User::query()->create([
            'name' => 'Person', 'email' => 'p-'.Str::lower(Str::random(8)).'@procynia.local', 'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN, 'bid_role' => User::BID_ROLE_SYSTEM_OWNER, 'customer_id' => $customer->id, 'is_active' => true,
        ]);
        $this->inApiRequest($client, $person, writeRole: true);

        $this->assertSame('not_an_active_integration', app(IntegrationOperationAuthority::class)->refusal($person, self::OPERATION));
        $this->assertFalse(app(IntegrationOperationAuthority::class)->allows(null, self::OPERATION));
    }

    /** @return array{0: ApiClient, 1: Customer, 2: User, 3: ApiClientCustomerGrant} */
    private function allowedSetup(bool $withRisk = true): array
    {
        $client = $this->apiClient(['entra_app_id' => (string) Str::uuid()]);
        $customer = $this->apiCustomer('Kunde '.Str::random(4), $withRisk);
        $identity = $this->integrationIdentity($customer);
        $grant = $this->grantTo($client, $customer, $identity, ['allowed_operations' => [self::OPERATION]]);

        return [$client, $customer, $identity, $grant];
    }

    private function inApiRequest(ApiClient $client, User $actor, bool $writeRole): void
    {
        $this->app->instance(ActorChannel::class, new ApiActorChannel);
        $context = new ApiRequestContext('test-'.Str::random(8));
        $context->authenticated($client, $writeRole);
        $context->actor = $actor;
        $this->app->instance(ApiRequestContext::class, $context);
    }
}
