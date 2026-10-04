<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskAccessArea;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Risiko, gated twice: by the customer's own roles (what) and by tilgangsområder (which).
 *
 * What these tests defend:
 *
 *  - A risk outside the user's areas is undiscoverable — not listed, not searched, not counted,
 *    and a 404 by URL for read and for every write, exactly like an id that does not exist.
 *  - Access is the union of roles taken over (permission, area) pairs: a role that reads one area
 *    and a role that edits another never add up to editing the first.
 *  - System Owner reaches the module and administers areas, but reads no risk without a role that
 *    reaches its area.
 *  - Area administration lives in Kundemiljø → Tilganger, System Owner only, tenant-scoped.
 */
class RiskAccessTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
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

    public function test_without_risk_view_the_module_is_forbidden_before_any_id_is_looked_at(): void
    {
        ['customer' => $customer] = $this->context();
        $area = $this->area($customer, 'Beredskap');
        $risk = $this->risk($customer, $area, 'Strømbrudd');
        $user = $this->member($customer);

        $this->actingAs($user)->get('/app/risk')->assertForbidden();
        $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertForbidden();
        $this->actingAs($user)->get('/app/risk/risks/999999999')->assertForbidden();
        $this->actingAs($user)->post('/app/risk/risks', $this->payload($area))->assertForbidden();
    }

    public function test_the_module_needs_the_risk_entitlement(): void
    {
        ['customer' => $customer] = $this->context(withRisk: false);
        $area = $this->area($customer, 'Beredskap');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$area]);

        $this->actingAs($user)->get('/app/risk')->assertRedirect();
    }

    public function test_a_risk_outside_the_users_areas_cannot_be_discovered(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $visible = $this->risk($customer, $hr, 'Nøkkelperson slutter');
        $hidden = $this->risk($customer, $finance, 'Nøkkelperson i lønn mangler');

        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_EDIT,
            CustomerPermissionCatalog::RISK_DELETE,
        ], [$hr]);

        $props = $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];
        $this->assertSame([$visible->id], array_column($props['risks'], 'id'));
        $this->assertSame(1, $props['visible_count']);

        // The search term matches both titles; only the visible one comes back.
        $props = $this->actingAs($user)->get('/app/risk?search=n%C3%B8kkelperson')->assertOk()->viewData('page')['props'];
        $this->assertSame([$visible->id], array_column($props['risks'], 'id'));
        $this->assertSame(1, $props['visible_count']);

        // Status filter over the hidden risk's status still yields only visible ones.
        $props = $this->actingAs($user)->get('/app/risk?status='.Risk::STATUS_IDENTIFIED)->assertOk()->viewData('page')['props'];
        $this->assertSame([$visible->id], array_column($props['risks'], 'id'));

        // By URL, the hidden risk is indistinguishable from one that does not exist.
        $this->actingAs($user)->get("/app/risk/risks/{$hidden->id}")->assertNotFound();
        $this->actingAs($user)->get('/app/risk/risks/999999999')->assertNotFound();
        $this->actingAs($user)->patch("/app/risk/risks/{$hidden->id}", $this->payload($hr))->assertNotFound();
        $this->actingAs($user)->delete("/app/risk/risks/{$hidden->id}")->assertNotFound();

        $this->assertDatabaseHas('risks', ['id' => $hidden->id, 'title' => 'Nøkkelperson i lønn mangler']);

        // Nothing on the page names the hidden area. (No risk.create, so no area is offered.)
        $this->assertSame([], $props['area_options']);
        $this->assertStringNotContainsString(
            'Økonomi',
            json_encode(collect($props)->except('translations')->all(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_permissions_and_areas_pair_per_role_rather_than_combining_across_roles(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $hrRisk = $this->risk($customer, $hr, 'Sykefravær');
        $financeRisk = $this->risk($customer, $finance, 'Valutarisiko');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$finance]);

        // Union of what each role reads.
        $props = $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];
        $this->assertEqualsCanonicalizing([$hrRisk->id, $financeRisk->id], array_column($props['risks'], 'id'));

        // Edit only where the editing role reaches.
        $this->actingAs($user)->patch("/app/risk/risks/{$hrRisk->id}", $this->payload($hr, 'Sykefravær oppdatert'))->assertRedirect();
        $this->assertDatabaseHas('risks', ['id' => $hrRisk->id, 'title' => 'Sykefravær oppdatert']);

        $this->actingAs($user)->patch("/app/risk/risks/{$financeRisk->id}", $this->payload($finance, 'Endret'))->assertForbidden();
        $this->assertDatabaseHas('risks', ['id' => $financeRisk->id, 'title' => 'Valutarisiko']);

        $financeProps = $this->actingAs($user)->get("/app/risk/risks/{$financeRisk->id}")->assertOk()->viewData('page')['props'];
        $this->assertFalse($financeProps['permissions']['can_edit']);
        $this->assertFalse($financeProps['permissions']['can_delete']);

        // Moving the HR risk into Økonomi needs edit there too — which no role gives.
        $this->actingAs($user)
            ->patch("/app/risk/risks/{$hrRisk->id}", $this->payload($finance))
            ->assertSessionHasErrors('risk_access_area_id');
        $this->assertDatabaseHas('risks', ['id' => $hrRisk->id, 'risk_access_area_id' => $hr->id]);

        // Delete is its own permission.
        $this->actingAs($user)->delete("/app/risk/risks/{$hrRisk->id}")->assertForbidden();
    }

    public function test_creating_requires_create_in_the_chosen_area_and_an_owner_who_can_read_it(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$hr]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$finance]);

        $hrReader = $this->member($customer);
        $this->grant($customer, $hrReader, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $outsider = $this->member($customer);

        $props = $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_create']);
        $this->assertSame(['HR'], array_column($props['area_options'], 'name'));
        $ownerIds = array_column($props['owner_options'], 'id');
        $this->assertContains($hrReader->id, $ownerIds);
        $this->assertNotContains($outsider->id, $ownerIds);

        // Reading Økonomi is not creating there.
        $this->actingAs($user)->post('/app/risk/risks', $this->payload($finance))->assertSessionHasErrors('risk_access_area_id');

        // Another tenant's area, or a made-up one, gets the same answer.
        ['customer' => $other] = $this->context();
        $foreign = $this->area($other, 'HR');
        $this->actingAs($user)->post('/app/risk/risks', $this->payload($foreign))->assertSessionHasErrors('risk_access_area_id');

        // An owner who could not open the risk.
        $this->actingAs($user)
            ->post('/app/risk/risks', array_merge($this->payload($hr), ['owner_user_id' => $outsider->id]))
            ->assertSessionHasErrors('owner_user_id');

        $this->assertSame(0, Risk::query()->where('customer_id', $customer->id)->count());

        $this->actingAs($user)
            ->post('/app/risk/risks', array_merge($this->payload($hr, 'Rekruttering'), ['owner_user_id' => $hrReader->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('risks', [
            'customer_id' => $customer->id,
            'risk_access_area_id' => $hr->id,
            'title' => 'Rekruttering',
            'owner_user_id' => $hrReader->id,
            'created_by' => $user->id,
        ]);
    }

    public function test_delete_needs_risk_delete_in_the_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_DELETE], [$hr]);

        $this->actingAs($user)->delete("/app/risk/risks/{$risk->id}")->assertRedirect('/app/risk');
        $this->assertDatabaseMissing('risks', ['id' => $risk->id]);
    }

    public function test_an_inactive_role_reaches_nothing(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');

        $user = $this->member($customer);
        $role = $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $role->update(['is_active' => false]);

        $this->actingAs($user)->get('/app/risk')->assertForbidden();
        $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertForbidden();
    }

    public function test_system_owner_reaches_the_module_but_reads_no_risk_without_a_role_for_its_area(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');

        $props = $this->actingAs($owner)->get('/app/risk')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['risks']);
        $this->assertSame(0, $props['visible_count']);
        $this->assertFalse($props['has_areas']);
        $this->assertFalse($props['permissions']['can_create']);
        $this->actingAs($owner)->get("/app/risk/risks/{$risk->id}")->assertNotFound();

        // The way in is a role, which System Owner may give themselves.
        $role = $this->role($customer, [CustomerPermissionCatalog::RISK_VIEW]);
        $this->actingAs($owner)
            ->patch("/app/customer-environment/roles/{$role->id}", ['risk_access_area_ids' => [$hr->id]])
            ->assertRedirect();
        // Assigning it is Rediger bruker's job, which allows it on one's own account; attached
        // directly here because that flow is not what this test is about.
        $owner->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $this->actingAs($owner)->get("/app/risk/risks/{$risk->id}")->assertOk();
    }

    public function test_tilganger_administers_areas_and_their_roles_for_system_owner_only(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $member = $this->member($customer);

        $this->actingAs($member)
            ->post('/app/customer-environment/risk-access-areas', ['name' => 'Beredskap'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post('/app/customer-environment/risk-access-areas', ['name' => 'Beredskap', 'description' => 'Kriser'])
            ->assertRedirect();
        $area = RiskAccessArea::query()->where('customer_id', $customer->id)->where('name', 'Beredskap')->firstOrFail();

        $this->actingAs($owner)
            ->post('/app/customer-environment/risk-access-areas', ['name' => 'beredskap'])
            ->assertSessionHasErrors('name');

        // A role is created with risk permissions and the area in one save.
        $this->actingAs($owner)->post('/app/customer-environment/roles', [
            'name' => 'Beredskapsleder',
            'permissions' => [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT],
            'risk_access_area_ids' => [$area->id],
        ])->assertRedirect();
        $role = CustomerRole::query()->where('customer_id', $customer->id)->where('name', 'Beredskapsleder')->firstOrFail();
        $this->assertSame([$area->id], $role->riskAccessAreas()->pluck('risk_access_areas.id')->all());

        // Another tenant's area is dropped, never stored.
        ['customer' => $other] = $this->context();
        $foreign = $this->area($other, 'Beredskap');
        $this->actingAs($owner)
            ->patch("/app/customer-environment/roles/{$role->id}", ['risk_access_area_ids' => [$area->id, $foreign->id]])
            ->assertRedirect();
        $this->assertSame([$area->id], $role->riskAccessAreas()->pluck('risk_access_areas.id')->all());

        // Toggling a permission checkbox does not touch the role's areas.
        $this->actingAs($owner)
            ->patch("/app/customer-environment/roles/{$role->id}", ['permissions' => [CustomerPermissionCatalog::RISK_VIEW]])
            ->assertRedirect();
        $this->assertSame([$area->id], $role->riskAccessAreas()->pluck('risk_access_areas.id')->all());

        $props = $this->actingAs($owner)->get('/app/customer-environment?tab=permissions')->assertOk()->viewData('page')['props'];
        $customerRoles = $props['customerRoles'];
        $this->assertContains('risk', array_column($customerRoles['domains'], 'key'));
        $this->assertSame(['Beredskap'], array_column($customerRoles['risk_access_areas'], 'name'));
        $this->assertArrayNotHasKey('risk_count', $customerRoles['risk_access_areas'][0]);
        $roleRow = collect($customerRoles['roles'])->firstWhere('id', $role->id);
        $this->assertSame([$area->id], $roleRow['risk_access_area_ids']);

        // Another tenant's area is not found, not forbidden.
        $this->actingAs($owner)
            ->patch("/app/customer-environment/risk-access-areas/{$foreign->id}", ['name' => 'Kapret'])
            ->assertNotFound();

        // An area holding risks is not deleted; an empty one is, and takes its role links along.
        $this->risk($customer, $area, 'Brann');
        $this->actingAs($owner)->delete("/app/customer-environment/risk-access-areas/{$area->id}")->assertRedirect();
        $this->assertDatabaseHas('risk_access_areas', ['id' => $area->id]);

        $empty = $this->area($customer, 'HR');
        $role->syncRiskAccessAreas([$area->id, $empty->id]);
        $this->actingAs($owner)->delete("/app/customer-environment/risk-access-areas/{$empty->id}")->assertRedirect();
        $this->assertDatabaseMissing('risk_access_areas', ['id' => $empty->id]);
        $this->assertDatabaseMissing('customer_role_risk_access_areas', ['risk_access_area_id' => $empty->id]);
    }

    public function test_the_rail_learns_risk_view_from_the_users_roles(): void
    {
        ['customer' => $customer] = $this->context();
        $area = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$area]);

        $props = $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];

        $this->assertContains('risk.view', $props['access']['permissions']);
        $this->assertContains('risk', $props['entitlements']['modules']);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<RiskAccessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys, $areas);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<RiskAccessArea>  $areas
     */
    private function role(Customer $customer, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncRiskAccessAreas(array_map(fn (RiskAccessArea $area): int => (int) $area->id, $areas));

        return $role;
    }

    private function area(Customer $customer, string $name): RiskAccessArea
    {
        return RiskAccessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function risk(Customer $customer, RiskAccessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'risk_access_area_id' => $area->id,
            'title' => $title,
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(RiskAccessArea $area, string $title = 'Ny risiko'): array
    {
        return [
            'title' => $title,
            'description' => 'Kort beskrivelse',
            'risk_access_area_id' => $area->id,
            'owner_user_id' => null,
            'status' => Risk::STATUS_IDENTIFIED,
        ];
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risiko-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(bool $withRisk = true): array
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
            'name' => 'Risiko Tilgang AS',
            'slug' => 'risiko-tilgang-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($withRisk) {
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => 'grc'],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
