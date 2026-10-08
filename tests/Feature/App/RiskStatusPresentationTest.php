<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskScoringPolicy;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * What the risk page needs to explain status, the acceptance warning and Behandling → Endre.
 *
 * What these tests defend:
 *
 *  - Status is lifecycle and set by hand only: assessing, choosing a direction and accepting the
 *    residual risk leave it «identified», and the page carries the wording that says so.
 *  - The warning in Ny vurdering follows the server's current acceptance: present while one exists,
 *    gone once a new assessment has made it historical, absent when there never was one.
 *  - «Endre» under Behandling is the risk's own edit: offered (can_edit) and allowed only with
 *    risk.edit in the area; risk.view, risk.assess and risk.accept do not give it.
 */
class RiskStatusPresentationTest extends TestCase
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

    public function test_assessing_choosing_a_direction_and_accepting_leave_the_status_alone_and_the_page_explains_it(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');

        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_EDIT,
            CustomerPermissionCatalog::RISK_ASSESS,
            CustomerPermissionCatalog::RISK_ACCEPT,
        ], [$hr]);

        $this->assess($user, $risk);
        $this->actingAs($user)->patch($this->url($risk), $this->payload($risk->fresh(), ['treatment_strategy' => Risk::TREATMENT_ACCEPT]))
            ->assertSessionHasNoErrors();
        $this->accept($user, $risk);

        $props = $this->showProps($user, $risk);
        $this->assertSame(Risk::STATUS_IDENTIFIED, $props['risk']['status']);
        $this->assertSame(Risk::STATUS_IDENTIFIED, $risk->fresh()->status);
        $this->assertNotNull($props['risk_acceptance']['current']);
        $this->assertSame(RiskScoringPolicy::LEVEL_MODERATE, $props['assessments'][0]['residual']['level'] ?? null);

        $tr = $props['translations']['risk'];
        $this->assertSame('Status sier hvor risikoen er i livsløpet. Risikovurderingen sier hvor alvorlig den er.', $tr['status_hint']);
        $this->assertStringContainsString('Settes manuelt', $tr['field_status_hint']);
        $this->assertSame('Restrisiko: :level', $tr['level_residual']);
        $this->assertSame('Endre', $tr['treatment_strategy']['edit']);
    }

    public function test_the_acceptance_warning_follows_the_current_acceptance(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $accepted = $this->risk($customer, $hr, 'Akseptert');
        $neverAccepted = $this->risk($customer, $hr, 'Aldri akseptert');

        $user = $this->member($customer);
        $this->grant($customer, $user, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_ASSESS,
            CustomerPermissionCatalog::RISK_ACCEPT,
        ], [$hr]);

        $this->assess($user, $accepted);
        $this->accept($user, $accepted);
        $this->assess($user, $neverAccepted);

        $props = $this->showProps($user, $accepted);
        $this->assertTrue($props['permissions']['can_assess']);
        $this->assertNotNull($props['risk_acceptance']['current']);
        $this->assertSame(
            'En ny vurdering gjør dagens aksept historisk. Ny restrisiko må eventuelt aksepteres på nytt.',
            $props['translations']['risk']['assessment']['supersedes_acceptance'],
        );

        $this->assertNull($this->showProps($user, $neverAccepted)['risk_acceptance']['current']);

        // The new assessment makes the acceptance historical, so there is nothing left to warn about.
        $this->assess($user, $accepted);
        $after = $this->showProps($user, $accepted)['risk_acceptance'];
        $this->assertNull($after['current']);
        $this->assertSame(['superseded'], array_column($after['history'], 'state'));
    }

    public function test_only_risk_edit_in_the_area_is_offered_and_allowed_to_change_the_treatment(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', strategy: Risk::TREATMENT_REDUCE);

        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        $notEditors = [];
        $notEditors[] = $viewer = $this->member($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $notEditors[] = $acceptor = $this->member($customer);
        $this->grant($customer, $acceptor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT], [$hr]);
        $notEditors[] = $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$finance]);

        $this->assertTrue($this->showProps($editor, $risk)['permissions']['can_edit']);

        foreach ($notEditors as $user) {
            $this->assertFalse($this->showProps($user, $risk)['permissions']['can_edit']);
            $this->actingAs($user)->patch($this->url($risk), $this->payload($risk, ['treatment_strategy' => Risk::TREATMENT_AVOID]))
                ->assertForbidden();
        }

        $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['treatment_strategy' => Risk::TREATMENT_AVOID]))
            ->assertSessionHasNoErrors();
        $this->assertSame(Risk::TREATMENT_AVOID, $risk->fresh()->treatment_strategy);
        $this->assertSame(Risk::STATUS_IDENTIFIED, $risk->fresh()->status);
    }

    private function assess(User $user, Risk $risk): void
    {
        $this->actingAs($user)->post("/app/risk/risks/{$risk->id}/assessments", [
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
            'residual_likelihood' => 2,
            'residual_consequence' => 3,
            'rationale' => 'Vurdert',
        ])->assertSessionHasNoErrors();
    }

    private function accept(User $user, Risk $risk): void
    {
        $assessmentId = (int) $risk->assessments()->first()->id;

        $this->actingAs($user)->post("/app/risk/risks/{$risk->id}/acceptances", [
            'assessment_id' => $assessmentId,
            'rationale' => 'Akseptert etter gjennomgang.',
        ])->assertSessionHasNoErrors();
    }

    private function url(Risk $risk): string
    {
        return "/app/risk/risks/{$risk->id}";
    }

    /**
     * The edit form as it is sent, with the given fields changed.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(Risk $risk, array $changes): array
    {
        return array_merge([
            'title' => $risk->title,
            'cause' => $risk->cause,
            'event' => $risk->event,
            'consequence' => $risk->consequence,
            'description' => $risk->description,
            'business_area_id' => $risk->business_area_id,
            'owner_user_id' => $risk->owner_user_id,
            'status' => $risk->status,
            'review_interval_months' => $risk->review_interval_months,
            'treatment_strategy' => $risk->treatment_strategy,
        ], $changes);
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

    private function risk(Customer $customer, BusinessArea $area, string $title, string $status = Risk::STATUS_IDENTIFIED, ?string $strategy = null): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'cause' => 'manglende rutiner',
            'event' => 'en hendelse inntreffer',
            'consequence' => 'virksomheten rammes',
            'status' => $status,
            'treatment_strategy' => $strategy,
        ]);
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
            app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');
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
