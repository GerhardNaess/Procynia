<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Permissions\CustomerPermissionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * V1 of customer-defined roles: data model, permission catalogue, resolver and administration.
 *
 * Nothing in anbud reads any of this yet, so these tests cover the three properties the feature
 * actually promises — the union, the tenant boundary, and that System Owner cannot be locked out.
 */
class CustomerRolePermissionTest extends TestCase
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

    public function test_system_owner_creates_roles_assigns_them_and_the_resolver_returns_the_union(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $owner = $this->systemOwner($customer);

        $this->actingAs($owner)
            ->post('/app/customer-environment/roles', [
                'name' => 'Kvalitetsdirektør',
                'description' => 'Eier kvalitetssystemet',
                'permissions' => [
                    CustomerPermissionCatalog::QUALITY_VIEW,
                    CustomerPermissionCatalog::QUALITY_APPROVE,
                ],
            ])
            ->assertRedirect('/app/customer-environment?tab=permissions');

        $this->actingAs($owner)
            ->post('/app/customer-environment/roles', [
                'name' => 'Wiki-ansvarlig',
                'permissions' => [
                    CustomerPermissionCatalog::WIKI_APPROVE,
                    CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
                ],
            ])
            ->assertRedirect('/app/customer-environment?tab=permissions');

        $qualityRole = CustomerRole::query()->where('customer_id', $customer->id)->where('name', 'Kvalitetsdirektør')->firstOrFail();
        $wikiRole = CustomerRole::query()->where('customer_id', $customer->id)->where('name', 'Wiki-ansvarlig')->firstOrFail();

        $contributor = $this->contributor($customer, 'union@example.test');

        // Assignment happens on the user's own edit screen, on the same save as the rest of the
        // user's identity — Tilganger only defines the roles.
        $this->assignRoles($owner, $contributor, [$qualityRole->id, $wikiRole->id])
            ->assertRedirect('/app/users');

        $permissions = app(CustomerPermissionService::class)->effectivePermissions($contributor->fresh());

        sort($permissions);
        $expected = [
            CustomerPermissionCatalog::QUALITY_APPROVE,
            CustomerPermissionCatalog::QUALITY_VIEW,
            CustomerPermissionCatalog::WIKI_APPROVE,
            CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
        ];
        sort($expected);

        $this->assertSame($expected, $permissions);
        $this->assertTrue(app(CustomerPermissionService::class)->has($contributor->fresh(), CustomerPermissionCatalog::WIKI_APPROVE));
        $this->assertFalse(app(CustomerPermissionService::class)->has($contributor->fresh(), CustomerPermissionCatalog::WIKI_DELETE));

        // The bid side is untouched: the contributor is still a contributor, with no new anbud
        // authority of any kind.
        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $contributor->fresh()->resolvedBidRole());
    }

    public function test_an_inactive_role_keeps_its_assignment_but_grants_nothing(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $owner = $this->systemOwner($customer);
        $contributor = $this->contributor($customer, 'inactive@example.test');

        $role = $this->createRole($customer, 'Kvalitetsleder', [CustomerPermissionCatalog::QUALITY_EDIT]);
        $contributor->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $this->assertTrue(app(CustomerPermissionService::class)->has($contributor->fresh(), CustomerPermissionCatalog::QUALITY_EDIT));

        $this->actingAs($owner)
            ->patch("/app/customer-environment/roles/{$role->id}", ['is_active' => false])
            ->assertRedirect('/app/customer-environment?tab=permissions');

        $this->assertFalse(app(CustomerPermissionService::class)->has($contributor->fresh(), CustomerPermissionCatalog::QUALITY_EDIT));
        $this->assertDatabaseHas('customer_user_roles', [
            'user_id' => $contributor->id,
            'customer_role_id' => $role->id,
        ]);
    }

    public function test_roles_and_assignments_do_not_cross_the_tenant_boundary(): void
    {
        $own = $this->createCustomer('Procynia AS');
        $foreign = $this->createCustomer('Annen Kunde AS');

        $owner = $this->systemOwner($own);
        $foreignRole = $this->createRole($foreign, 'Fremmed rolle', [CustomerPermissionCatalog::QUALITY_DELETE]);
        $ownContributor = $this->contributor($own, 'own@example.test');
        $foreignContributor = $this->contributor($foreign, 'foreign@example.test');

        // Another tenant's role cannot be edited, deleted or handed out.
        $this->actingAs($owner)->patch("/app/customer-environment/roles/{$foreignRole->id}", ['name' => 'Kapret'])->assertNotFound();
        $this->actingAs($owner)->delete("/app/customer-environment/roles/{$foreignRole->id}")->assertNotFound();
        $this->assignRoles($owner, $ownContributor, [$foreignRole->id])->assertNotFound();
        $this->assignRoles($owner, $foreignContributor, [])->assertNotFound();

        $this->assertSame('Fremmed rolle', $foreignRole->fresh()->name);
        $this->assertSame([], $ownContributor->fresh()->customerRoles()->pluck('customer_roles.id')->all());

        // Even if an assignment row somehow pointed across tenants, the resolver would not honour it.
        DB::table('customer_user_roles')->insert([
            'customer_id' => $own->id,
            'user_id' => $ownContributor->id,
            'customer_role_id' => $foreignRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], app(CustomerPermissionService::class)->effectivePermissions($ownContributor->fresh()));

        // And the gallery only ever shows the acting customer's own roles.
        $this->createRole($own, 'Egen rolle', [CustomerPermissionCatalog::WIKI_VIEW]);

        $this->actingAs($owner)
            ->get('/app/customer-environment?tab=permissions')
            ->assertOk()
            ->assertViewHas('page', function ($page): bool {
                $names = collect(data_get($page, 'props.customerRoles.roles', []))->pluck('name');

                return $names->contains('Egen rolle') && ! $names->contains('Fremmed rolle');
            });
    }

    public function test_only_system_owner_administers_customer_roles_and_always_holds_every_permission(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $owner = $this->systemOwner($customer);
        $contributor = $this->contributor($customer, 'nonadmin@example.test');

        $this->actingAs($contributor)
            ->post('/app/customer-environment/roles', ['name' => 'Selvbetjent', 'permissions' => []])
            ->assertForbidden();

        // System Owner holds the catalogue unconditionally, so no combination of ticks below can
        // take away the authority that edits them.
        $role = $this->createRole($customer, 'Tom rolle', []);
        $owner->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $service = app(CustomerPermissionService::class);
        $this->assertSame(CustomerPermissionCatalog::all(), $service->effectivePermissions($owner->fresh()));
        $this->assertTrue($service->has($owner->fresh(), CustomerPermissionCatalog::QUALITY_DELETE));
    }

    public function test_unknown_permission_keys_are_rejected_on_write_and_ignored_on_read(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $owner = $this->systemOwner($customer);

        $this->actingAs($owner)
            ->post('/app/customer-environment/roles', [
                'name' => 'Ukjent',
                'permissions' => ['billing.refund'],
            ])
            ->assertSessionHasErrors('permissions.0');

        $role = $this->createRole($customer, 'Blandet', [CustomerPermissionCatalog::WIKI_VIEW]);
        DB::table('customer_role_permissions')->insert([
            'customer_role_id' => $role->id,
            'permission_key' => 'quality.retired',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([CustomerPermissionCatalog::WIKI_VIEW], $role->fresh()->permissionKeys());
    }

    public function test_a_role_name_is_unique_within_the_customer_but_not_across_customers(): void
    {
        $own = $this->createCustomer('Procynia AS');
        $foreign = $this->createCustomer('Annen Kunde AS');

        $this->createRole($own, 'Kvalitetsleder', []);
        $this->createRole($foreign, 'Kvalitetsleder', []);

        $this->actingAs($this->systemOwner($own))
            ->post('/app/customer-environment/roles', ['name' => 'kvalitetsleder', 'permissions' => []])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, CustomerRole::query()->where('customer_id', $own->id)->count());
    }

    public function test_the_edit_screen_offers_the_customers_roles_and_remembers_which_are_held(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $owner = $this->systemOwner($customer);
        $contributor = $this->contributor($customer, 'edit-screen@example.test');

        $quality = $this->createRole($customer, 'Kvalitetsleder', [CustomerPermissionCatalog::QUALITY_EDIT]);
        $wiki = $this->createRole($customer, 'Wiki-redaktør', [CustomerPermissionCatalog::WIKI_EDIT]);
        $retired = $this->createRole($customer, 'Avviklet rolle', []);
        $retired->update(['is_active' => false]);

        $contributor->customerRoles()->attach($quality->id, ['customer_id' => $customer->id]);

        $this->actingAs($owner)
            ->get("/app/users/{$contributor->id}/edit")
            ->assertOk()
            ->assertViewHas('page', function ($page) use ($quality, $wiki, $retired): bool {
                $options = collect(data_get($page, 'props.customerRoleOptions', []));

                return data_get($page, 'props.canEditCustomerRoles') === true
                    && data_get($page, 'props.user.customer_role_ids') === [$quality->id]
                    // The retired role is not held, so it is not on offer at all.
                    && $options->pluck('id')->sort()->values()->all() === collect([$quality->id, $wiki->id])->sort()->values()->all()
                    && ! $options->pluck('id')->contains($retired->id);
            });
    }

    public function test_saving_the_user_assigns_several_roles_without_touching_the_bid_side(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $owner = $this->systemOwner($customer);
        $contributor = $this->contributor($customer, 'multi@example.test');
        $contributor->forceFill(['is_qa' => true])->save();

        $quality = $this->createRole($customer, 'Kvalitetsleder', [CustomerPermissionCatalog::QUALITY_EDIT]);
        $wiki = $this->createRole($customer, 'Wiki-redaktør', [CustomerPermissionCatalog::WIKI_EDIT]);

        $this->assignRoles($owner, $contributor, [$quality->id, $wiki->id])
            ->assertRedirect('/app/users');

        $fresh = $contributor->fresh();

        $this->assertSame(
            [$quality->id, $wiki->id],
            $fresh->customerRoles()->pluck('customer_roles.id')->sort()->values()->all(),
        );

        // Anbud is untouched: role, QA and the hardcoded Wiki approver flag all survive the save.
        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $fresh->resolvedBidRole());
        $this->assertTrue((bool) $fresh->is_qa);

        // And the same screen can take every role away again.
        $this->assignRoles($owner, $contributor, [])->assertRedirect('/app/users');
        $this->assertSame([], $contributor->fresh()->customerRoles()->pluck('customer_roles.id')->all());
    }

    public function test_an_inactive_role_cannot_be_newly_assigned_but_an_existing_one_survives_a_save(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $owner = $this->systemOwner($customer);
        $contributor = $this->contributor($customer, 'retired@example.test');

        $held = $this->createRole($customer, 'Kvalitetsleder', [CustomerPermissionCatalog::QUALITY_EDIT]);
        $contributor->customerRoles()->attach($held->id, ['customer_id' => $customer->id]);
        $held->update(['is_active' => false]);

        $fresh = $this->createRole($customer, 'Ny rolle', []);
        $fresh->update(['is_active' => false]);

        $this->assignRoles($owner, $contributor, [$held->id, $fresh->id])
            ->assertSessionHasErrors('customer_role_ids');

        // The held-but-inactive role is still offered, so an unrelated save does not drop it.
        $this->assignRoles($owner, $contributor, [$held->id])->assertRedirect('/app/users');

        $this->assertSame(
            [$held->id],
            $contributor->fresh()->customerRoles()->pluck('customer_roles.id')->all(),
        );
        $this->assertSame([], app(CustomerPermissionService::class)->effectivePermissions($contributor->fresh()));
    }

    public function test_a_bid_manager_cannot_hand_out_customer_roles(): void
    {
        $customer = $this->createCustomer('Procynia AS');
        $bidManager = $this->bidManager($customer, 'bm@example.test');
        $contributor = $this->contributor($customer, 'bm-target@example.test');

        $role = $this->createRole($customer, 'Kvalitetsleder', [CustomerPermissionCatalog::QUALITY_EDIT]);

        $this->actingAs($bidManager)
            ->put("/app/users/{$contributor->id}", [
                'name' => $contributor->name,
                'customer_role_ids' => [$role->id],
            ])
            ->assertForbidden();

        $this->assertSame([], $contributor->fresh()->customerRoles()->pluck('customer_roles.id')->all());

        // The edit screen does not offer the section to them either.
        $this->actingAs($bidManager)
            ->get("/app/users/{$contributor->id}/edit")
            ->assertOk()
            ->assertViewHas('page', fn ($page): bool => data_get($page, 'props.canEditCustomerRoles') === false);
    }

    /**
     * Hand a user a set of customer roles the way the product does: a save on Rediger bruker.
     *
     * @param  list<int>  $roleIds
     */
    private function assignRoles(User $actor, User $target, array $roleIds): TestResponse
    {
        return $this->actingAs($actor)->put("/app/users/{$target->id}", [
            'name' => $target->name,
            'bid_role' => $target->resolvedBidRole(),
            // The real form posts the whole identity on every save, so the helper does too —
            // otherwise this would be testing role assignment against a payload no screen sends.
            'is_qa' => $target->is_qa ? '1' : '0',
            'is_wiki_approver' => $target->is_wiki_approver ? '1' : '0',
            'customer_role_ids' => $roleIds,
        ]);
    }

    private function createRole(Customer $customer, string $name, array $permissionKeys): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);

        return $role;
    }

    private function systemOwner(Customer $customer): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'bid_manager_scope' => null,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function bidManager(Customer $customer, string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_BID_MANAGER,
            'bid_manager_scope' => User::BID_MANAGER_SCOPE_COMPANY,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function contributor(Customer $customer, string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
