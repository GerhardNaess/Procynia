<?php

namespace Tests\Feature\Api;

use App\Models\ApiClientCustomerGrant;
use App\Models\Customer;
use App\Models\CustomerAccessChange;
use App\Models\CustomerRole;
use App\Models\Risk;
use App\Models\User;
use App\Services\Api\ApiClientGrantService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Risk\RiskAccessService;
use App\Services\Security\ActorChannel;
use App\Services\Security\ApiActorChannel;
use App\Services\Security\CustomerAccessChangeRecorder;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * D13, points 2–6: the customer's own System Owner gives an approved application read access to
 * their own customer, chooses its roles and takes the access back — in Kundemiljø and in the
 * service behind it alike. Every change is recorded with actor and time; the integration never
 * reads beyond the customer's modules and read keys; revocation stops the next call.
 */
class ApiClientGrantAdministrationTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
        $this->fakeEntraSigningKeys();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_system_owner_gives_an_application_read_access_and_it_is_recorded(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer('Nordlys AS');
        $owner = $this->systemOwner($customer);
        $role = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW]);
        $area = $this->businessArea($customer, 'Beredskap');
        $risk = Risk::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Strømbrudd', 'status' => Risk::STATUS_IDENTIFIED]);

        $this->actingAs($owner)
            ->post('/app/customer-environment/integrations', ['api_client_id' => $client->id, 'customer_role_ids' => [$role->id]])
            ->assertRedirect('/app/customer-environment?tab=integrations')
            ->assertSessionHas('success');

        $grant = ApiClientCustomerGrant::query()->sole();
        $identity = $grant->integrationUser;
        $this->assertSame([(int) $customer->id, $owner->id], [(int) $grant->customer_id, (int) $grant->granted_by_user_id]);
        $this->assertTrue($identity->isIntegration());
        $this->assertSame((int) $customer->id, (int) $identity->customer_id);
        $this->assertStringEndsWith('@integrations.procynia.invalid', $identity->email);
        $this->assertSame([$role->id], $identity->customerRoles()->pluck('customer_roles.id')->map(fn ($id): int => (int) $id)->all());

        $change = CustomerAccessChange::query()->where('subject_type', CustomerAccessChange::SUBJECT_API_CLIENT_GRANT)->sole();
        $this->assertSame([CustomerAccessChangeRecorder::API_GRANT_CREATED, $owner->id, $grant->id], [$change->change_type, (int) $change->actor_user_id, (int) $change->subject_id]);
        $this->assertSame([$role->id], $change->after['customer_role_ids']);

        $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $customer))
            ->assertOk()
            ->assertJsonPath('data.0.id', $risk->id);
    }

    public function test_only_the_customers_own_system_owner_administers_integrations(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $grant = app(ApiClientGrantService::class)->grant($this->systemOwner($customer), $client->id, []);
        $service = app(ApiClientGrantService::class);
        $before = CustomerAccessChange::query()->count();

        $refused = [
            'Bid Manager' => $this->person($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_BID_MANAGER),
            'contributor' => $this->person($customer, User::ROLE_USER, User::BID_ROLE_CONTRIBUTOR),
            'inactive System Owner' => $this->systemOwner($customer, ['is_active' => false]),
            'integration identity' => $grant->integrationUser,
            'internal administrator' => User::query()->create([
                'name' => 'Intern', 'email' => 'intern-'.Str::lower(Str::random(8)).'@procynia.local', 'password' => bcrypt('x-secret-1'),
                'role' => User::ROLE_SUPER_ADMIN, 'customer_id' => null, 'is_active' => true,
            ]),
            'nobody' => null,
        ];

        foreach ($refused as $who => $actor) {
            foreach ([
                fn () => $service->grant($actor, $client->id, []),
                fn () => $service->updateRoles($actor, $grant->id, []),
                fn () => $service->revoke($actor, $grant->id),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail("{$who} administered an integration.");
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }

            if ($actor instanceof User && $actor->is_active && ! $actor->isIntegration() && $actor->customer_id !== null) {
                $this->actingAs($actor)->post('/app/customer-environment/integrations', ['api_client_id' => $client->id, 'customer_role_ids' => []])->assertForbidden();
                $this->actingAs($actor)->delete("/app/customer-environment/integrations/{$grant->id}")->assertForbidden();
            }
        }

        $this->actingAs($grant->integrationUser)->post('/app/customer-environment/integrations', ['api_client_id' => $client->id, 'customer_role_ids' => []])->assertForbidden();

        $this->assertNull($grant->fresh()->revoked_at);
        $this->assertSame($before, CustomerAccessChange::query()->count());
    }

    public function test_a_system_owner_cannot_administer_through_the_api_channel(): void
    {
        $client = $this->apiClient();
        $owner = $this->systemOwner($this->apiCustomer());
        $this->app->instance(ActorChannel::class, new ApiActorChannel);

        $this->expectException(AuthorizationException::class);

        app(ApiClientGrantService::class)->grant($owner, $client->id, []);
    }

    public function test_a_system_owner_acts_only_on_their_own_customer(): void
    {
        $client = $this->apiClient();
        $first = $this->apiCustomer('Første AS');
        $second = $this->apiCustomer('Andre AS');
        $firstOwner = $this->systemOwner($first);
        $secondOwner = $this->systemOwner($second);
        $firstRole = $this->role($first, [CustomerPermissionCatalog::RISK_VIEW]);
        $firstGrant = app(ApiClientGrantService::class)->grant($firstOwner, $client->id, [$firstRole->id]);
        $service = app(ApiClientGrantService::class);

        // Another customer's grant is not found, whatever the id.
        foreach ([
            fn () => $service->updateRoles($secondOwner, $firstGrant->id, []),
            fn () => $service->revoke($secondOwner, $firstGrant->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A System Owner changed another customer\'s grant.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actingAs($secondOwner)->delete("/app/customer-environment/integrations/{$firstGrant->id}")->assertNotFound();
        $this->actingAs($secondOwner)->put("/app/customer-environment/integrations/{$firstGrant->id}/roles", ['customer_role_ids' => []])->assertNotFound();

        // Another customer's role cannot be given.
        $this->actingAs($secondOwner)
            ->post('/app/customer-environment/integrations', ['api_client_id' => $client->id, 'customer_role_ids' => [$firstRole->id]])
            ->assertSessionHasErrors('customer_role_ids');

        // A customer named in the request is ignored: the grant is the actor's own customer's.
        $this->actingAs($secondOwner)
            ->post('/app/customer-environment/integrations', ['api_client_id' => $client->id, 'customer_role_ids' => [], 'customer_id' => $first->id])
            ->assertRedirect();

        $this->assertSame(
            [(int) $first->id, (int) $second->id],
            ApiClientCustomerGrant::query()->active()->orderBy('id')->pluck('customer_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $this->assertNull($firstGrant->fresh()->revoked_at);
        $this->assertSame([$firstRole->id], $firstGrant->integrationUser->customerRoles()->pluck('customer_roles.id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_only_an_enabled_application_can_be_granted_once_with_active_roles(): void
    {
        $client = $this->apiClient();
        $disabled = $this->apiClient(['name' => 'Avviklet', 'entra_app_id' => (string) Str::uuid(), 'is_enabled' => false]);
        $customer = $this->apiCustomer();
        $owner = $this->systemOwner($customer);
        $inactiveRole = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], active: false);
        $service = app(ApiClientGrantService::class);

        foreach ([
            'disabled application' => fn () => $service->grant($owner, $disabled->id, []),
            'unknown application' => fn () => $service->grant($owner, 999999999, []),
            'inactive role' => fn () => $service->grant($owner, $client->id, [$inactiveRole->id]),
            'unknown role' => fn () => $service->grant($owner, $client->id, [999999999]),
        ] as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} was accepted.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, ApiClientCustomerGrant::query()->count());
        $this->assertSame(0, User::integrationIdentities()->where('customer_id', $customer->id)->count(), 'a refused grant leaves no identity behind');

        $service->grant($owner, $client->id, []);

        try {
            $service->grant($owner, $client->id, []);
            $this->fail('A second active grant was accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, ApiClientCustomerGrant::query()->active()->count());
        }
    }

    public function test_the_integration_never_reads_beyond_read_keys_or_the_customers_modules(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $owner = $this->systemOwner($customer);
        $area = $this->businessArea($customer, 'Beredskap');
        $risk = Risk::query()->create(['customer_id' => $customer->id, 'business_area_id' => $area->id, 'title' => 'Strømbrudd', 'status' => Risk::STATUS_IDENTIFIED]);
        $everything = $this->role($customer, CustomerPermissionCatalog::all());
        $grant = app(ApiClientGrantService::class)->grant($owner, $client->id, [$everything->id]);
        $identity = $grant->integrationUser;

        $this->assertSame(
            [],
            array_values(array_diff(app(CustomerPermissionService::class)->effectivePermissions($identity), CustomerPermissionCatalog::integrationPermissions())),
        );
        $this->assertTrue(app(RiskAccessService::class)->can($identity, CustomerPermissionCatalog::RISK_VIEW, $risk));
        $this->assertFalse(app(RiskAccessService::class)->can($identity, CustomerPermissionCatalog::RISK_EDIT, $risk));

        // A module the customer has not ordered stays closed, whatever the roles say.
        $withoutRisk = $this->apiCustomer('Uten Risiko', withRisk: false);
        $otherOwner = $this->systemOwner($withoutRisk);
        app(ApiClientGrantService::class)->grant($otherOwner, $client->id, [$this->role($withoutRisk, [CustomerPermissionCatalog::RISK_VIEW])->id]);

        $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $withoutRisk))
            ->assertOk()
            ->assertJsonPath('data.modules.risk', ['entitled' => false, 'readable' => false, 'business_areas' => []]);
        $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $withoutRisk))->assertForbidden();
    }

    public function test_changing_the_roles_changes_what_the_integration_reads_and_is_recorded(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $owner = $this->systemOwner($customer);
        $reader = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW]);
        $grant = app(ApiClientGrantService::class)->grant($owner, $client->id, []);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/risks', $headers)->assertForbidden();

        $this->actingAs($owner)
            ->put("/app/customer-environment/integrations/{$grant->id}/roles", ['customer_role_ids' => [$reader->id]])
            ->assertRedirect('/app/customer-environment?tab=integrations');

        $this->get('/api/v1/risks', $headers)->assertOk();

        $change = CustomerAccessChange::query()->where('change_type', CustomerAccessChangeRecorder::API_GRANT_ROLES_CHANGED)->sole();
        $this->assertSame([[], [$reader->id]], [$change->before['customer_role_ids'], $change->after['customer_role_ids']]);
        $this->assertSame($owner->id, (int) $change->actor_user_id);
    }

    public function test_revoking_stops_the_next_call_keeps_the_grant_and_allows_a_new_one(): void
    {
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $owner = $this->systemOwner($customer);
        $grant = app(ApiClientGrantService::class)->grant($owner, $client->id, [$this->role($customer, [CustomerPermissionCatalog::RISK_VIEW])->id]);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/me', $headers)->assertOk();

        $this->actingAs($owner)
            ->delete("/app/customer-environment/integrations/{$grant->id}")
            ->assertRedirect('/app/customer-environment?tab=integrations');

        $this->get('/api/v1/me', $headers)->assertForbidden();

        $grant->refresh();
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($owner->id, (int) $grant->revoked_by_user_id);
        $this->assertFalse((bool) $grant->integrationUser->is_active);
        $this->assertSame(1, CustomerAccessChange::query()->where('change_type', CustomerAccessChangeRecorder::API_GRANT_REVOKED)->where('actor_user_id', $owner->id)->count());

        // A revoked grant is history: it cannot be changed or revoked again.
        $this->actingAs($owner)->delete("/app/customer-environment/integrations/{$grant->id}")->assertNotFound();
        $this->actingAs($owner)->put("/app/customer-environment/integrations/{$grant->id}/roles", ['customer_role_ids' => []])->assertNotFound();

        // Access can be given again, through a new identity.
        $again = app(ApiClientGrantService::class)->grant($owner, $client->id, []);
        $this->assertNotSame($grant->integration_user_id, $again->integration_user_id);
        $this->get('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.actor.id', (int) $again->integration_user_id);
    }

    public function test_kundemiljo_shows_integrations_to_the_system_owner_only(): void
    {
        $client = $this->apiClient();
        $this->apiClient(['name' => 'Avviklet', 'entra_app_id' => (string) Str::uuid(), 'is_enabled' => false]);
        $customer = $this->apiCustomer();
        $owner = $this->systemOwner($customer);
        $role = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW]);
        app(ApiClientGrantService::class)->grant($owner, $client->id, [$role->id]);

        $this->actingAs($owner)
            ->get('/app/customer-environment?tab=integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeTab', 'integrations')
                ->where('integrations.clients', [['id' => $client->id, 'name' => 'Siggjo dashboards', 'description' => null, 'is_granted' => true]])
                ->where('integrations.grants.0.client.name', 'Siggjo dashboards')
                ->where('integrations.grants.0.role_ids', [$role->id])
                ->where('integrations.grants.0.granted_by_name', $owner->name)
                ->where('integrations.grants.0.access.risk', ['entitled' => true, 'readable' => true, 'business_areas' => 'all'])
                ->where('integrations.roles', [['id' => $role->id, 'name' => $role->name]]));

        // A user who may open Kundemiljø (Opprett brukere for everyone) but is not System Owner.
        $customer->forceFill(['permission_settings' => [Customer::PERMISSION_CREATE_USERS => ['all']] + $customer->resolvedPermissionSettings()])->save();
        $userAdmin = $this->person($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_BID_MANAGER, ['bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY]);

        $this->actingAs($userAdmin)
            ->get('/app/customer-environment?tab=integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('integrations', null)->where('activeTab', 'integrations'));
    }

    private function systemOwner(Customer $customer, array $overrides = []): User
    {
        return $this->person($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER, $overrides);
    }

    private function person(Customer $customer, string $role, string $bidRole, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Person '.Str::upper(Str::random(4)),
            'email' => 'person-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ], $overrides));
    }

    /** @param  list<string>  $permissionKeys */
    private function role(Customer $customer, array $permissionKeys, bool $active = true): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(6)),
            'is_active' => $active,
        ]);
        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(true, []);

        return $role;
    }
}
