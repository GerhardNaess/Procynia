<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\User;
use App\Services\Risk\RiskAccessService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * RiskAccessService's public contract, pinned at the service level.
 *
 * RiskAccessTest proves the same rules through HTTP. These pin the answers of every public method
 * directly, so the area-scoped grant query can be moved out of the service (BusinessAreaGrants)
 * and shown to answer exactly as before — including for data the UI can never produce, such as a
 * stray cross-tenant pivot row.
 */
class RiskAccessCharacterizationTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private RiskAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();

        $this->access = app(RiskAccessService::class);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_permission_and_area_must_come_from_the_same_active_role(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $user = $this->member($customer);

        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]));
        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::RISK_EDIT], [$finance]));

        $this->assertSame([(int) $hr->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertSame([(int) $finance->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_EDIT));
        $this->assertSame([], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_DELETE));

        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::RISK_EDIT, (int) $customer->id, (int) $hr->id));
        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::RISK_VIEW, (int) $customer->id, (int) $finance->id));
        $this->assertTrue($this->access->canInArea($user, CustomerPermissionCatalog::RISK_EDIT, (int) $customer->id, (int) $finance->id));

        $this->assertSame(
            ['HR', 'Økonomi'],
            $this->access->areasFor($this->assignAndReturn($user, $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], [$finance])), CustomerPermissionCatalog::RISK_VIEW)
                ->pluck('name')->all(),
        );
    }

    public function test_alle_is_a_wildcard_over_the_customers_areas_now_and_later(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $this->area($other, 'Fremmed område');
        $user = $this->member($customer);

        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], all: true));

        $this->assertSame([(int) $hr->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertTrue($this->access->reachesAllAreas($user, CustomerPermissionCatalog::RISK_VIEW));

        $later = $this->area($customer, 'Beredskap');

        $this->assertSame(
            [(int) $hr->id, (int) $later->id],
            $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW),
        );
        $this->assertSame(0, DB::table('customer_role_business_areas')->where('business_area_id', $later->id)->count());

        // «Alle» on view does not lend itself to edit, and edit in one area is not «Alle».
        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::RISK_EDIT], [$hr]));
        $this->assertSame([(int) $hr->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_EDIT));
        $this->assertFalse($this->access->reachesAllAreas($user, CustomerPermissionCatalog::RISK_EDIT));
    }

    public function test_the_wrong_area_gives_no_access(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $visible = $this->risk($customer, $hr);
        $hidden = $this->risk($customer, $finance);
        $user = $this->member($customer);

        $this->assign($user, $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]));

        $this->assertSame([(int) $visible->id], $this->access->visibleRisks($user)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNull($this->access->findVisible($user, (int) $hidden->id));
        $this->assertNotNull($this->access->findVisible($user, (int) $visible->id));
        $this->assertFalse($this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $hidden));
        $this->assertTrue($this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $visible));
        $this->assertFalse($this->access->reachesAllAreas($user, CustomerPermissionCatalog::RISK_VIEW));
    }

    public function test_system_owner_gets_no_risk_data_without_a_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr);

        $this->assertTrue($this->access->canOpenModule($owner));

        foreach (CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_RISK] as $key) {
            $this->assertSame([], $this->access->areaIdsFor($owner, $key), $key);
            $this->assertFalse($this->access->reachesAllAreas($owner, $key), $key);
        }

        $this->assertSame(0, $this->access->visibleRisks($owner)->count());
        $this->assertFalse($this->access->can($owner, CustomerPermissionCatalog::RISK_VIEW, $risk));
        $this->assertArrayNotHasKey((int) $owner->id, $this->access->viewerAreaIdsByUser((int) $customer->id));

        // Through an explicit role, exactly like anyone else.
        $this->assign($owner, $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]));
        $this->assertSame([(int) $hr->id], $this->access->areaIdsFor($owner, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertSame([(int) $hr->id], $this->access->viewerAreaIdsByUser((int) $customer->id)[(int) $owner->id]);
    }

    public function test_the_customer_boundary_holds_even_against_stray_cross_tenant_rows(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $foreignArea = $this->area($other, 'Fremmed');
        $foreignRisk = $this->risk($other, $foreignArea);
        $user = $this->member($customer);
        $foreigner = $this->member($other);

        $role = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->assign($user, $role);

        // A link row from this customer's role to another tenant's area — never written by the UI.
        DB::table('customer_role_business_areas')->insert([
            'customer_id' => $customer->id,
            'customer_role_id' => $role->id,
            'business_area_id' => $foreignArea->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A user of another tenant assigned to this customer's role, and to a role with «Alle».
        DB::table('customer_user_roles')->insert([
            'customer_id' => $customer->id,
            'user_id' => $foreigner->id,
            'customer_role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $allRole = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], all: true);
        DB::table('customer_user_roles')->insert([
            'customer_id' => $other->id,
            'user_id' => $foreigner->id,
            'customer_role_id' => $allRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([(int) $hr->id], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertNull($this->access->findVisible($user, (int) $foreignRisk->id));
        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::RISK_VIEW, (int) $other->id, (int) $foreignArea->id));
        $this->assertFalse($this->access->can($user, CustomerPermissionCatalog::RISK_VIEW, $foreignRisk));

        $this->assertSame([], $this->access->areaIdsFor($foreigner, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertFalse($this->access->reachesAllAreas($foreigner, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertSame([(int) $user->id => [(int) $hr->id]], $this->access->viewerAreaIdsByUser((int) $customer->id));
        $this->assertSame([], $this->access->viewerAreaIdsByUser((int) $other->id));
    }

    public function test_an_inactive_role_gives_no_access(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $explicit = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $wildcard = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW], all: true);
        $this->assign($user, $explicit);
        $this->assign($user, $wildcard);

        $explicit->forceFill(['is_active' => false])->save();
        $wildcard->forceFill(['is_active' => false])->save();

        $this->assertFalse($this->access->canOpenModule($user));
        $this->assertSame([], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertFalse($this->access->reachesAllAreas($user, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertSame(0, $this->access->visibleRisks($user)->count());
        $this->assertSame([], $this->access->viewerAreaIdsByUser((int) $customer->id));
    }

    public function test_only_risk_keys_are_answered(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->assign($user, $this->role($customer, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::QUALITY_VIEW,
        ], [$hr]));

        $this->assertSame([], $this->access->areaIdsFor($user, CustomerPermissionCatalog::QUALITY_VIEW));
        $this->assertSame([], $this->access->areaIdsFor($user, 'risk.unknown'));
        $this->assertFalse($this->access->reachesAllAreas($user, CustomerPermissionCatalog::QUALITY_VIEW));
        $this->assertFalse($this->access->canInArea($user, CustomerPermissionCatalog::QUALITY_VIEW, (int) $customer->id, (int) $hr->id));
    }

    public function test_a_user_without_a_customer_gets_nothing(): void
    {
        $user = new User(['name' => 'Intern', 'role' => User::ROLE_SUPER_ADMIN]);
        $user->customer_id = null;

        $this->assertFalse($this->access->canOpenModule($user));
        $this->assertSame([], $this->access->areaIdsFor($user, CustomerPermissionCatalog::RISK_VIEW));
        $this->assertFalse($this->access->canOpenModule(null));
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

    private function assignAndReturn(User $user, CustomerRole $role): User
    {
        $this->assign($user, $role);

        return $user;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function risk(Customer $customer, BusinessArea $area): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => 'Risiko '.Str::random(6),
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risiko-kar-'.Str::lower(Str::random(10)).'@procynia.local',
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
            'name' => 'Risiko Karakterisering AS',
            'slug' => 'risiko-kar-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-kar-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
