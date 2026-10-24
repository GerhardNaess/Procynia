<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\ApiClientResource;
use App\Filament\Resources\ApiClientResource\Pages\CreateApiClient;
use App\Filament\Resources\ApiClientResource\Pages\EditApiClient;
use App\Filament\Resources\ApiClientResource\Pages\ListApiClients;
use App\Models\ApiClient;
use App\Models\ApiClientChange;
use App\Models\User;
use App\Services\Api\ApiClientAdministration;
use App\Services\Security\ActorChannel;
use App\Services\Security\ApiActorChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesApiCustomerGrants;
use Tests\Concerns\IssuesEntraAccessTokens;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * D13, points 1, 4, 5 and 6: only an internal platform administrator registers, changes, enables
 * and disables client applications — in Filament and in the service behind it alike — every change
 * is recorded with its actor, registration gives no access to customer data, and a disabled client
 * is refused on its next call.
 */
class ApiClientAdministrationTest extends TestCase
{
    use CreatesApiCustomerGrants;
    use IssuesEntraAccessTokens;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    public function test_an_internal_administrator_registers_a_client_and_it_is_recorded(): void
    {
        $admin = $this->internalAdmin();

        $client = app(ApiClientAdministration::class)->register($admin, [
            'name' => 'Siggjo dashboards',
            'description' => 'Dashboards og agenter',
            'entra_app_id' => strtoupper(self::CLIENT_APP_ID),
            'home_tenant_id' => ' '.self::HOME_TENANT.' ',
        ]);

        $this->assertSame(self::CLIENT_APP_ID, $client->entra_app_id);
        $this->assertSame(self::HOME_TENANT, $client->home_tenant_id);
        $this->assertTrue($client->is_enabled);
        $this->assertSame($admin->id, (int) $client->created_by_user_id);

        $change = ApiClientChange::query()->where('api_client_id', $client->id)->sole();
        $this->assertSame(ApiClientChange::REGISTERED, $change->change_type);
        $this->assertSame($admin->id, (int) $change->actor_user_id);
        $this->assertNull($change->before);
        $this->assertSame('Siggjo dashboards', $change->after['name']);
        $this->assertNotNull($change->created_at);
    }

    public function test_nobody_but_an_internal_administrator_may_administer_clients(): void
    {
        $customer = $this->apiCustomer();
        $client = $this->apiClient();
        $service = app(ApiClientAdministration::class);

        $refused = [
            'System Owner' => $this->user($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
            'customer user' => $this->user($customer, User::ROLE_USER),
            'inactive internal admin' => $this->internalAdmin(['is_active' => false]),
            'integration identity' => $this->integrationIdentity($customer),
            'nobody' => null,
        ];

        foreach ($refused as $who => $actor) {
            foreach ([
                fn () => $service->register($actor, ['name' => 'Ny', 'entra_app_id' => (string) Str::uuid(), 'home_tenant_id' => self::HOME_TENANT]),
                fn () => $service->update($actor, $client, ['name' => 'Kapret']),
                fn () => $service->setEnabled($actor, $client, false),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail("{$who} administered a client.");
                } catch (AuthorizationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $this->assertSame(1, ApiClient::query()->count());
        $this->assertSame('Siggjo dashboards', $client->fresh()->name);
        $this->assertTrue($client->fresh()->is_enabled);
        $this->assertSame(0, ApiClientChange::query()->count());
    }

    public function test_an_internal_administrator_cannot_administer_through_the_api_channel(): void
    {
        $admin = $this->internalAdmin();
        $this->app->instance(ActorChannel::class, new ApiActorChannel);

        $this->expectException(AuthorizationException::class);

        app(ApiClientAdministration::class)->register($admin, ['name' => 'Via API', 'entra_app_id' => (string) Str::uuid(), 'home_tenant_id' => self::HOME_TENANT]);
    }

    public function test_registration_is_validated(): void
    {
        $admin = $this->internalAdmin();
        $this->apiClient();
        $service = app(ApiClientAdministration::class);

        foreach ([
            ['name' => '', 'entra_app_id' => (string) Str::uuid(), 'home_tenant_id' => self::HOME_TENANT],
            ['name' => 'Ugyldig', 'entra_app_id' => 'not-a-guid', 'home_tenant_id' => self::HOME_TENANT],
            ['name' => 'Ugyldig', 'entra_app_id' => (string) Str::uuid(), 'home_tenant_id' => 'contoso.onmicrosoft.com'],
            ['name' => 'Duplikat', 'entra_app_id' => self::CLIENT_APP_ID, 'home_tenant_id' => self::OTHER_TENANT],
        ] as $input) {
            try {
                $service->register($admin, $input);
                $this->fail('Invalid registration accepted: '.json_encode($input));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, ApiClient::query()->count());
    }

    public function test_only_name_and_description_change_and_each_change_is_recorded(): void
    {
        $admin = $this->internalAdmin();
        $client = $this->apiClient();
        $service = app(ApiClientAdministration::class);

        $service->update($admin, $client, [
            'name' => 'Siggjo agenter',
            'description' => 'Foundry',
            'entra_app_id' => (string) Str::uuid(),
            'home_tenant_id' => self::OTHER_TENANT,
        ]);
        $service->update($admin, $client, ['name' => 'Siggjo agenter', 'description' => 'Foundry']);

        $client->refresh();
        $this->assertSame(['Siggjo agenter', self::CLIENT_APP_ID, self::HOME_TENANT], [$client->name, $client->entra_app_id, $client->home_tenant_id]);

        $changes = ApiClientChange::query()->where('api_client_id', $client->id)->get();
        $this->assertCount(1, $changes, 'a change that changed nothing is not recorded');
        $this->assertSame(ApiClientChange::CHANGED, $changes[0]->change_type);
        $this->assertSame('Siggjo dashboards', $changes[0]->before['name']);
        $this->assertSame('Siggjo agenter', $changes[0]->after['name']);
    }

    public function test_registration_alone_gives_no_access_to_customer_data(): void
    {
        $admin = $this->internalAdmin();
        $customer = $this->apiCustomer();
        app(ApiClientAdministration::class)->register($admin, [
            'name' => 'Siggjo', 'entra_app_id' => self::CLIENT_APP_ID, 'home_tenant_id' => self::HOME_TENANT,
        ]);

        $this->get('/api/v1/me', $this->asCustomer($this->accessToken(), $customer))->assertForbidden();
        $this->get('/api/v1/risks', $this->asCustomer($this->accessToken(), $customer))->assertForbidden();
    }

    public function test_a_disabled_client_is_refused_on_its_next_call_and_enabling_restores_it(): void
    {
        $admin = $this->internalAdmin();
        $client = $this->apiClient();
        $customer = $this->apiCustomer();
        $this->grantTo($client, $customer);
        $service = app(ApiClientAdministration::class);
        $headers = $this->asCustomer($this->accessToken(), $customer);

        $this->get('/api/v1/me', $headers)->assertOk();

        $service->setEnabled($admin, $client, false);
        $this->get('/api/v1/me', $headers)->assertUnauthorized();

        $service->setEnabled($admin, $client, false);
        $service->setEnabled($admin, $client, true);
        $this->get('/api/v1/me', $headers)->assertOk();

        $this->assertSame(
            [ApiClientChange::DISABLED, ApiClientChange::ENABLED],
            ApiClientChange::query()->where('api_client_id', $client->id)->orderBy('id')->pluck('change_type')->all(),
        );
    }

    public function test_the_change_history_cannot_be_rewritten(): void
    {
        $admin = $this->internalAdmin();
        $client = app(ApiClientAdministration::class)->register($admin, [
            'name' => 'Siggjo', 'entra_app_id' => self::CLIENT_APP_ID, 'home_tenant_id' => self::HOME_TENANT,
        ]);
        $change = ApiClientChange::query()->where('api_client_id', $client->id)->sole();

        foreach ([
            fn () => DB::table('api_client_changes')->where('id', $change->id)->update(['change_type' => 'disabled']),
            fn () => DB::table('api_client_changes')->where('id', $change->id)->delete(),
        ] as $attempt) {
            DB::statement('SAVEPOINT history_attempt');

            try {
                $attempt();
                $this->fail('The history was rewritten.');
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT history_attempt');
            }
        }

        $this->assertSame(ApiClientChange::REGISTERED, $change->fresh()->change_type);
    }

    public function test_the_filament_screens_are_for_internal_administrators_and_write_through_the_service(): void
    {
        $customer = $this->apiCustomer();
        $systemOwner = $this->user($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER);
        $admin = $this->internalAdmin();

        $this->actingAs($systemOwner)->get(ApiClientResource::getUrl('index'))->assertForbidden();
        $this->actingAs($admin)->get(ApiClientResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(ApiClientResource::getUrl('create'))->assertOk();

        Livewire::actingAs($admin)
            ->test(CreateApiClient::class)
            ->fillForm([
                'name' => 'Siggjo dashboards',
                'entra_app_id' => self::CLIENT_APP_ID,
                'home_tenant_id' => self::HOME_TENANT,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = ApiClient::query()->where('entra_app_id', self::CLIENT_APP_ID)->sole();
        $this->assertSame($admin->id, (int) $client->created_by_user_id);

        Livewire::actingAs($admin)
            ->test(EditApiClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['name' => 'Siggjo agenter'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(ListApiClients::class)
            ->callTableAction('disable', $client);

        $this->assertSame('Siggjo agenter', $client->fresh()->name);
        $this->assertFalse($client->fresh()->is_enabled);
        $this->assertSame(
            [ApiClientChange::REGISTERED, ApiClientChange::CHANGED, ApiClientChange::DISABLED],
            ApiClientChange::query()->where('api_client_id', $client->id)->orderBy('id')->pluck('change_type')->all(),
        );
        $this->assertFalse(ApiClientResource::canDelete($client));
    }

    private function internalAdmin(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Intern administrator',
            'email' => 'admin-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function user($customer, string $role, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Person '.Str::upper(Str::random(4)),
            'email' => 'person-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
