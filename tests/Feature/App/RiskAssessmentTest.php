<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Risk assessments: history, not fields — and reached only through the risk.
 *
 * What these tests defend:
 *
 *  - Assessments are appended, never changed: a correction is a new row, and the old one stays.
 *  - risk.assess is its own permission: it works without risk.edit, and risk.edit does not imply it.
 *  - It follows the same (permission, area) pair rule as every other risk permission, and System
 *    Owner's implicit grant still carries no area.
 *  - A risk the user cannot see is a 404 for assessing, exactly as for reading — within a tenant
 *    and across tenants.
 *  - Score and level are computed, never stored, and residual risk is never half-given.
 *  - The register's Restrisiko is the latest assessment's residual — never an older one carried
 *    forward — read in one query for the rows the user may already see.
 */
class RiskAssessmentTest extends TestCase
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

    public function test_assessments_are_appended_and_earlier_ones_are_kept_unchanged(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Nøkkelperson slutter');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);

        $this->actingAs($user)->post($this->url($risk), $this->assessment(4, 4))->assertRedirect()->assertSessionHas('success');
        $first = RiskAssessment::query()->where('risk_id', $risk->id)->sole();

        $this->travel(1)->minutes();

        // A correction is the next assessment.
        $this->actingAs($user)->post($this->url($risk), $this->assessment(2, 3, 1, 3))->assertRedirect();

        $this->assertSame(2, RiskAssessment::query()->where('risk_id', $risk->id)->count());
        $this->assertDatabaseHas('risk_assessments', [
            'id' => $first->id,
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
            'residual_likelihood' => null,
            'assessed_by' => $user->id,
            'customer_id' => $customer->id,
            'criteria_key' => 'standard_5x5_v1',
        ]);

        $props = $this->showProps($user, $risk);
        $this->assertCount(2, $props['assessments']);
        // Newest first: the first row is the current assessment.
        $this->assertSame(['likelihood' => 2, 'consequence' => 3, 'score' => 6, 'level' => 'moderate'], $props['assessments'][0]['inherent']);
        $this->assertSame(['likelihood' => 1, 'consequence' => 3, 'score' => 3, 'level' => 'low'], $props['assessments'][0]['residual']);
        $this->assertSame($first->id, $props['assessments'][1]['id']);
        $this->assertSame('high', $props['assessments'][1]['inherent']['level']);
        $this->assertNull($props['assessments'][1]['residual']);
        $this->assertSame($user->name, $props['assessments'][1]['assessed_by_name']);
        $this->assertSame('Erfaring fra tidligere hendelser.', $props['assessments'][1]['rationale']);

        // No route changes or removes an assessment, and the model refuses too.
        $this->actingAs($user)->patch("{$this->url($risk)}/{$first->id}", $this->assessment(1, 1))->assertNotFound();
        $this->actingAs($user)->delete("{$this->url($risk)}/{$first->id}")->assertNotFound();
        $assessmentRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'app/risk/') && str_contains($route->uri(), 'assessments'))
            ->flatMap(fn ($route): array => array_diff($route->methods(), ['HEAD']))
            ->values()
            ->all();
        $this->assertSame(['POST'], $assessmentRoutes);

        try {
            $first->update(['inherent_likelihood' => 1]);
            $this->fail('An assessment must not be updatable.');
        } catch (LogicException) {
        }

        try {
            $first->delete();
            $this->fail('An assessment must not be deletable on its own.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('risk_assessments', ['id' => $first->id, 'inherent_likelihood' => 4]);
    }

    public function test_the_register_shows_the_residual_level_of_the_latest_assessment(): void
    {
        ['customer' => $customer] = $this->context();
        $drift = $this->area($customer, 'Drift');
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$drift]);

        $lowered = $this->risk($customer, $drift, 'A Redusert');
        $this->record($lowered, now()->subDays(2), [4, 4]);
        $this->record($lowered, now()->subDay(), [1, 3]);

        // The latest assessment gave no residual: an older one is not carried forward.
        $reassessed = $this->risk($customer, $drift, 'B Vurdert uten restrisiko');
        $this->record($reassessed, now()->subDays(2), [4, 4]);
        $this->record($reassessed, now()->subDay());

        $unassessed = $this->risk($customer, $drift, 'C Ikke vurdert');

        $hidden = $this->risk($customer, $hr, 'D Skjult');
        $this->record($hidden, now()->subDay(), [4, 4]);

        $rows = collect($this->indexProps($user)['risks'])->keyBy('id');

        $this->assertSame([$lowered->id, $reassessed->id, $unassessed->id], $rows->keys()->all());
        $this->assertSame('low', $rows[$lowered->id]['residual_level']);
        $this->assertNull($rows[$reassessed->id]['residual_level']);
        $this->assertNull($rows[$unassessed->id]['residual_level']);

        // One read of assessments for the register, however many rows it has.
        $before = $this->assessmentQueriesOnIndex($user);
        foreach (['E', 'F', 'G'] as $letter) {
            $this->record($this->risk($customer, $drift, "{$letter} Flere"), now()->subDay(), [2, 3]);
        }
        $this->assertSame($before, $this->assessmentQueriesOnIndex($user));
        $this->assertSame('moderate', collect($this->indexProps($user)['risks'])->firstWhere('title', 'E Flere')['residual_level']);
    }

    public function test_risk_assess_works_without_risk_edit_and_edit_does_not_imply_assess(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Sykefravær');

        $assessor = $this->member($customer);
        $this->grant($customer, $assessor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);

        $props = $this->showProps($assessor, $risk);
        $this->assertTrue($props['permissions']['can_assess']);
        $this->assertFalse($props['permissions']['can_edit']);

        $this->actingAs($assessor)->post($this->url($risk), $this->assessment())->assertRedirect();
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());

        // Assessing is not rewriting the risk.
        $this->actingAs($assessor)->patch("/app/risk/risks/{$risk->id}", [
            'title' => 'Omskrevet',
            'business_area_id' => $hr->id,
            'status' => Risk::STATUS_IDENTIFIED,
        ])->assertForbidden();
        $this->assertDatabaseHas('risks', ['id' => $risk->id, 'title' => 'Sykefravær']);

        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        $props = $this->showProps($editor, $risk);
        $this->assertFalse($props['permissions']['can_assess']);
        $this->actingAs($editor)->post($this->url($risk), $this->assessment())->assertForbidden();

        // A reader sees the whole history, but gets no way to add to it.
        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $props = $this->showProps($reader, $risk);
        $this->assertCount(1, $props['assessments']);
        $this->assertFalse($props['permissions']['can_assess']);
        $this->actingAs($reader)->post($this->url($risk), $this->assessment())->assertForbidden();

        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());
    }

    public function test_a_risk_outside_the_users_areas_cannot_be_assessed_or_probed(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $hidden = $this->risk($customer, $finance, 'Valutarisiko');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);

        // Same answer as an id that does not exist.
        $this->actingAs($user)->post($this->url($hidden), $this->assessment())->assertNotFound();
        $this->actingAs($user)->post('/app/risk/risks/999999999/assessments', $this->assessment())->assertNotFound();
        $this->actingAs($user)->get("/app/risk/risks/{$hidden->id}")->assertNotFound();

        $this->assertSame(0, RiskAssessment::query()->where('risk_id', $hidden->id)->count());
    }

    public function test_assess_and_area_must_come_from_the_same_role(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $hrRisk = $this->risk($customer, $hr, 'Sykefravær');
        $financeRisk = $this->risk($customer, $finance, 'Valutarisiko');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$finance]);

        $this->actingAs($user)->post($this->url($hrRisk), $this->assessment())->assertRedirect();

        // Økonomi is readable through the second role, but assess comes only from the first.
        $props = $this->showProps($user, $financeRisk);
        $this->assertFalse($props['permissions']['can_assess']);
        $this->actingAs($user)->post($this->url($financeRisk), $this->assessment())->assertForbidden();
        $this->assertSame(0, RiskAssessment::query()->where('risk_id', $financeRisk->id)->count());
    }

    public function test_without_risk_view_assessing_is_forbidden_and_an_inactive_role_grants_nothing(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');

        $assessOnly = $this->member($customer);
        $this->grant($customer, $assessOnly, [CustomerPermissionCatalog::RISK_ASSESS], [$hr]);
        $this->actingAs($assessOnly)->post($this->url($risk), $this->assessment())->assertForbidden();

        $inactive = $this->member($customer);
        $role = $this->grant($customer, $inactive, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);
        $role->update(['is_active' => false]);
        $this->actingAs($inactive)->post($this->url($risk), $this->assessment())->assertForbidden();

        $this->assertSame(0, RiskAssessment::query()->where('risk_id', $risk->id)->count());
    }

    public function test_system_owner_cannot_assess_without_a_role_that_grants_assess_in_the_area(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');

        // Holding every key implicitly reaches no risk.
        $this->actingAs($owner)->post($this->url($risk), $this->assessment())->assertNotFound();

        // A role that only reads HR lets System Owner see the risk — not assess it. The implicit
        // risk.assess carries no area, so it does not pair with the role's.
        $this->grant($customer, $owner, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $props = $this->showProps($owner, $risk);
        $this->assertFalse($props['permissions']['can_assess']);
        $this->actingAs($owner)->post($this->url($risk), $this->assessment())->assertForbidden();

        $this->grant($customer, $owner, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);
        $this->actingAs($owner)->post($this->url($risk), $this->assessment())->assertRedirect();
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());
    }

    public function test_another_tenants_risk_cannot_be_assessed_and_its_history_is_not_shown(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');
        $insider = $this->member($customer);
        $this->grant($customer, $insider, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);
        $this->actingAs($insider)->post($this->url($risk), $this->assessment())->assertRedirect();

        // Same area name, same permissions — in another customer.
        ['customer' => $other] = $this->context();
        $otherHr = $this->area($other, 'HR');
        $outsider = $this->member($other);
        $this->grant($other, $outsider, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$otherHr]);

        $this->actingAs($outsider)->post($this->url($risk), $this->assessment())->assertNotFound();
        $this->actingAs($outsider)->get("/app/risk/risks/{$risk->id}")->assertNotFound();
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());
    }

    public function test_values_are_validated_and_residual_risk_is_both_values_or_neither(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);

        $this->actingAs($user)->post($this->url($risk), $this->assessment(6, 3))->assertSessionHasErrors('inherent_likelihood');
        $this->actingAs($user)->post($this->url($risk), $this->assessment(3, 0))->assertSessionHasErrors('inherent_consequence');
        $this->actingAs($user)->post($this->url($risk), $this->assessment(3, 3, 2, null))->assertSessionHasErrors('residual_consequence');
        $this->actingAs($user)->post($this->url($risk), $this->assessment(3, 3, null, 2))->assertSessionHasErrors('residual_likelihood');
        $this->actingAs($user)
            ->post($this->url($risk), array_merge($this->assessment(), ['rationale' => '   ']))
            ->assertSessionHasErrors('rationale');

        $this->assertSame(0, RiskAssessment::query()->where('risk_id', $risk->id)->count());

        // The level is computed from the values; the page gets the criteria to preview with.
        $this->actingAs($user)->post($this->url($risk), $this->assessment(5, 4, 2, 2))->assertRedirect();
        $props = $this->showProps($user, $risk);
        $this->assertSame(['likelihood' => 5, 'consequence' => 4, 'score' => 20, 'level' => 'very_high'], $props['assessments'][0]['inherent']);
        $this->assertSame(['likelihood' => 2, 'consequence' => 2, 'score' => 4, 'level' => 'low'], $props['assessments'][0]['residual']);
        $this->assertSame([1, 2, 3, 4, 5], $props['risk_criteria']['likelihood']);

        // Nothing level-like is stored beside the values.
        $columns = DB::getSchemaBuilder()->getColumnListing('risk_assessments');
        $this->assertEmpty(array_filter($columns, fn (string $column): bool => str_contains($column, 'level') || str_contains($column, 'score')));
    }

    public function test_deleting_the_risk_takes_its_history_along(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Arbeidsmiljø');
        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_ASSESS,
            CustomerPermissionCatalog::RISK_DELETE,
        ], [$hr]);

        $this->actingAs($user)->post($this->url($risk), $this->assessment())->assertRedirect();
        $this->actingAs($user)->delete("/app/risk/risks/{$risk->id}")->assertRedirect('/app/risk');

        $this->assertDatabaseMissing('risk_assessments', ['risk_id' => $risk->id]);
    }

    public function test_risk_assess_is_offered_in_tilganger(): void
    {
        ['owner' => $owner] = $this->context();

        $props = $this->actingAs($owner)->get('/app/customer-environment?tab=permissions')->assertOk()->viewData('page')['props'];
        $riskDomain = collect($props['customerRoles']['domains'])->firstWhere('key', 'risk');
        $keys = array_column($riskDomain['permissions'], 'key');

        $this->assertContains(CustomerPermissionCatalog::RISK_ASSESS, $keys);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys, $areas);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function role(Customer $customer, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));

        return $role;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function risk(Customer $customer, BusinessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    /** @return array<string, mixed> */
    private function assessment(int $likelihood = 3, int $consequence = 4, ?int $residualLikelihood = null, ?int $residualConsequence = null): array
    {
        return [
            'inherent_likelihood' => $likelihood,
            'inherent_consequence' => $consequence,
            'residual_likelihood' => $residualLikelihood,
            'residual_consequence' => $residualConsequence,
            'rationale' => 'Erfaring fra tidligere hendelser.',
        ];
    }

    /** @param  array{0: int, 1: int}|null  $residual */
    private function record(Risk $risk, \DateTimeInterface $at, ?array $residual = null): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_at' => $at,
            'rationale' => 'Registrert i test.',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
            'residual_likelihood' => $residual[0] ?? null,
            'residual_consequence' => $residual[1] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    private function indexProps(User $user): array
    {
        return $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];
    }

    private function assessmentQueriesOnIndex(User $user): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->indexProps($user);
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'risk_assessments'))
            ->count();
    }

    private function url(Risk $risk): string
    {
        return "/app/risk/risks/{$risk->id}/assessments";
    }

    /** @return array<string, mixed> */
    private function showProps(User $user, Risk $risk): array
    {
        return $this->actingAs($user)->get("/app/risk/risks/{$risk->id}")->assertOk()->viewData('page')['props'];
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
                ['customer_id' => $customer->id, 'package_key' => 'governance'],
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
