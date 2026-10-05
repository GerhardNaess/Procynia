<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Permissions\BusinessAreaGrants;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Mål og KPI's access foundation, before any objective exists: the objective.* keys resolve
 * through the same (permission, area) pairs as Risiko, and the two domains never lend to each other.
 */
class ObjectiveAccessServiceTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private ObjectiveAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();

        $this->access = app(ObjectiveAccessService::class);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_the_catalogue_carries_the_objective_keys_as_an_area_scoped_domain(): void
    {
        $this->assertSame(
            ['objective.view', 'objective.edit', 'objective.measure', 'objective.delete'],
            CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_OBJECTIVE],
        );
        $this->assertSame(['risk', 'objective'], CustomerPermissionCatalog::areaScopedDomains());

        foreach (['objective.view', 'objective.measure', 'risk.view', 'risk.accept'] as $key) {
            $this->assertTrue(CustomerPermissionCatalog::isAreaScoped($key), $key);
        }

        foreach (['quality.view', 'wiki.view', 'objective.unknown', ''] as $key) {
            $this->assertFalse(CustomerPermissionCatalog::isAreaScoped($key), $key);
        }
    }

    public function test_permission_and_area_must_come_from_the_same_active_role(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $user = $this->member($customer);

        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]));
        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$finance]));

        $this->assertTrue($this->access->canOpenModule($user));
        $this->assertSame([(int) $hr->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertSame([(int) $finance->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::OBJECTIVE_MEASURE));
        $this->assertSame([], $this->access->areaIdsFor($user, CustomerPermissionCatalog::OBJECTIVE_EDIT));
        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::OBJECTIVE_MEASURE, (int) $customer->id, (int) $hr->id));
        $this->assertTrue($this->access->canInArea($user, CustomerPermissionCatalog::OBJECTIVE_MEASURE, (int) $customer->id, (int) $finance->id));
        $this->assertSame(['HR'], $this->access->areasFor($user, CustomerPermissionCatalog::OBJECTIVE_VIEW)->pluck('name')->all());
    }

    public function test_alle_is_a_wildcard_over_the_customers_areas_now_and_later(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $this->area($other, 'Fremmed');
        $user = $this->member($customer);
        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], all: true));

        $later = $this->area($customer, 'Beredskap');

        $this->assertSame([(int) $hr->id, (int) $later->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertTrue($this->access->reachesAllAreas($user, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertFalse($this->access->reachesAllAreas($user, CustomerPermissionCatalog::OBJECTIVE_EDIT));
    }

    public function test_the_wrong_area_gives_no_access(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $user = $this->member($customer);
        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]));

        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $customer->id, (int) $finance->id));
        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::OBJECTIVE_EDIT, (int) $customer->id, (int) $finance->id));
        $this->assertFalse($this->access->reachesAllAreas($user, CustomerPermissionCatalog::OBJECTIVE_VIEW));
    }

    public function test_system_owner_opens_the_module_but_gets_no_objective_data_without_a_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');

        $this->assertTrue($this->access->canOpenModule($owner));

        foreach (CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_OBJECTIVE] as $key) {
            $this->assertSame([], $this->access->areaIdsFor($owner, $key), $key);
            $this->assertFalse($this->access->reachesAllAreas($owner, $key), $key);
            $this->assertFalse($this->access->canInArea($owner, $key, (int) $customer->id, (int) $hr->id), $key);
        }

        $this->assertSame([], $this->access->viewerAreaIdsByUser((int) $customer->id));

        $this->assign($owner, $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]));
        $this->assertSame([(int) $owner->id => [(int) $hr->id]], $this->access->viewerAreaIdsByUser((int) $customer->id));
    }

    public function test_the_customer_boundary_holds_even_against_stray_cross_tenant_rows(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $foreignArea = $this->area($other, 'Fremmed');
        $user = $this->member($customer);
        $foreigner = $this->member($other);
        $role = $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $this->assign($user, $role);

        DB::table('customer_role_business_areas')->insert([
            'customer_id' => $customer->id,
            'customer_role_id' => $role->id,
            'business_area_id' => $foreignArea->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customer_user_roles')->insert([
            'customer_id' => $customer->id,
            'user_id' => $foreigner->id,
            'customer_role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([(int) $hr->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $other->id, (int) $foreignArea->id));
        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::OBJECTIVE_VIEW, (int) $customer->id, (int) $foreignArea->id));
        $this->assertSame([], $this->access->areaIdsFor($foreigner, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertSame([(int) $user->id => [(int) $hr->id]], $this->access->viewerAreaIdsByUser((int) $customer->id));
    }

    public function test_an_inactive_role_gives_no_access(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $explicit = $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $wildcard = $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], all: true);
        $this->assign($user, $explicit);
        $this->assign($user, $wildcard);
        $explicit->forceFill(['is_active' => false])->save();
        $wildcard->forceFill(['is_active' => false])->save();

        $this->assertFalse($this->access->canOpenModule($user));
        $this->assertSame([], $this->access->areaIdsFor($user, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertFalse($this->access->reachesAllAreas($user, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertSame([], $this->access->viewerAreaIdsByUser((int) $customer->id));
    }

    public function test_risk_and_objective_rights_never_lend_to_each_other(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $riskOnly = $this->member($customer);
        $objectiveOnly = $this->member($customer);
        $this->assign($riskOnly, $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], all: true));
        $this->assign($objectiveOnly, $this->role($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]));

        $risk = app(RiskAccessService::class);

        $this->assertFalse($this->access->canOpenModule($riskOnly));
        $this->assertSame([], $this->access->areaIdsFor($riskOnly, CustomerPermissionCatalog::OBJECTIVE_VIEW));
        $this->assertFalse($risk->canOpenModule($objectiveOnly));
        $this->assertSame([], $risk->areaIdsFor($objectiveOnly, CustomerPermissionCatalog::RISK_VIEW));

        // Each service answers only its own keys, even for a user who holds the other's.
        $this->assertSame([], $this->access->areaIdsFor($riskOnly, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertSame([], $risk->areaIdsFor($objectiveOnly, CustomerPermissionCatalog::OBJECTIVE_VIEW));
    }

    public function test_the_shared_grant_query_answers_nothing_for_keys_that_are_not_area_scoped(): void
    {
        ['customer' => $customer] = $this->context();
        // An area exists, so a role with «Alle» would reach it — if the key counted.
        $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::WIKI_VIEW], all: true));

        $grants = app(BusinessAreaGrants::class);

        foreach ([CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::WIKI_VIEW, 'objective.unknown'] as $key) {
            $this->assertSame([], $grants->areaIdsFor((int) $customer->id, (int) $user->id, $key), $key);
            $this->assertFalse($grants->reachesAllAreas((int) $customer->id, (int) $user->id, $key), $key);
            $this->assertSame([], $grants->areaIdsByUser((int) $customer->id, $key), $key);
        }
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function role(Customer $customer, array $permissionKeys, array $areas = [], bool $all = false): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas($all, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));

        return $role;
    }

    private function assign(User $user, CustomerRole $role): void
    {
        $user->customerRoles()->attach($role->id, ['customer_id' => $role->customer_id]);
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'maal-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(): array
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Mål Tilgang AS',
            'slug' => 'maal-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'maal-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
