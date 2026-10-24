<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * An integration identity never holds a decision reserved for a person, whatever it is given —
 * docs/external-api-phase-0-plan.md §4.1. A person with exactly the same grants keeps everything.
 */
class HumanOnlyPermissionTest extends TestCase
{
    use UsesProjectPostgresConnection;

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

    public function test_the_human_only_keys_are_all_in_the_catalogue(): void
    {
        foreach (CustomerPermissionCatalog::humanOnlyPermissions() as $key) {
            $this->assertTrue(CustomerPermissionCatalog::exists($key), $key);
        }

        // Reading is never reserved.
        foreach (CustomerPermissionCatalog::domains() as $keys) {
            $this->assertFalse(CustomerPermissionCatalog::isHumanOnly($keys[0]), $keys[0]);
        }
    }

    public function test_an_integration_with_every_role_and_system_owner_holds_no_human_only_key(): void
    {
        $customer = $this->createCustomer();
        $integration = $this->userWithEveryPermission($customer, User::ROLE_INTEGRATION);
        $permissions = app(CustomerPermissionService::class);

        // An integration holds the read keys and nothing else (D13) — every human-only key falls
        // outside them.
        foreach (CustomerPermissionCatalog::all() as $key) {
            $this->assertSame(
                CustomerPermissionCatalog::mayBeHeldByIntegration($key),
                $permissions->has($integration, $key),
                $key,
            );
        }

        $effective = $permissions->effectivePermissions($integration);
        $this->assertSame([], array_values(array_intersect($effective, CustomerPermissionCatalog::humanOnlyPermissions())));
        $this->assertContains(CustomerPermissionCatalog::WIKI_VIEW, $effective);
        $this->assertNotContains(CustomerPermissionCatalog::QUALITY_EDIT, $effective);
        $this->assertSame([], array_values(array_intersect(CustomerPermissionCatalog::integrationPermissions(), CustomerPermissionCatalog::humanOnlyPermissions())));
    }

    public function test_a_person_with_the_same_grants_keeps_every_key(): void
    {
        $customer = $this->createCustomer();
        $person = $this->userWithEveryPermission($customer, User::ROLE_CUSTOMER_ADMIN);
        $permissions = app(CustomerPermissionService::class);

        foreach (CustomerPermissionCatalog::all() as $key) {
            $this->assertTrue($permissions->has($person, $key), $key);
        }

        $this->assertSame(CustomerPermissionCatalog::all(), $permissions->effectivePermissions($person));
    }

    private function userWithEveryPermission(Customer $customer, string $role): User
    {
        $user = User::factory()->create([
            'customer_id' => $customer->id,
            'role' => $role,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);

        $grant = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Alt '.Str::upper(Str::random(6)),
            'is_active' => true,
        ]);
        $grant->syncPermissions(CustomerPermissionCatalog::all());
        $user->customerRoles()->attach($grant->id, ['customer_id' => $customer->id]);

        return $user;
    }

    private function createCustomer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => 'Menneskeforbehold AS',
            'slug' => 'menneskeforbehold-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
