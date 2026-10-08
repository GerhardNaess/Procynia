<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Behandlingsvalg on a risk.
 *
 * What these tests defend:
 *
 *  - One current direction from a fixed set (avoid, reduce, share, accept), or none — «Ikke
 *    besluttet ennå». Anything else is refused by the controller and by the database.
 *  - It is a direction only: «accept» creates no RiskAcceptance, «reduce» creates no tiltak, and
 *    neither touches status, assessment or score.
 *  - risk.view reads it; changing it takes risk.edit in the risk's area — risk.accept is not needed
 *    to choose «accept», and does not by itself allow changing it.
 *  - A hidden or foreign risk's strategy never leaks and cannot be changed.
 */
class RiskTreatmentStrategyTest extends TestCase
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

    public function test_each_strategy_can_be_saved_and_cleared_and_an_omitted_field_keeps_it(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Feil lønnsutbetaling');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        $props = $this->showProps($editor, $risk);
        $this->assertSame(['avoid', 'reduce', 'share', 'accept'], $props['treatment_strategies']);
        $this->assertNull($props['risk']['treatment_strategy']);

        foreach (Risk::TREATMENT_STRATEGIES as $strategy) {
            $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk->fresh(), ['treatment_strategy' => $strategy]))
                ->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSame($strategy, $risk->fresh()->treatment_strategy);
            $this->assertSame($strategy, $this->showProps($editor, $risk)['risk']['treatment_strategy']);
        }

        // An older client that does not send the field leaves the choice alone.
        $payload = $this->payload($risk->fresh(), ['title' => 'Feil lønnsutbetaling i desember']);
        unset($payload['treatment_strategy']);
        $this->actingAs($editor)->patch($this->url($risk), $payload)->assertSessionHasNoErrors();
        $this->assertSame(Risk::TREATMENT_ACCEPT, $risk->fresh()->treatment_strategy);

        // Empty is «Ikke besluttet ennå».
        $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk->fresh(), ['treatment_strategy' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($risk->fresh()->treatment_strategy);
    }

    public function test_a_new_risk_can_be_created_with_or_without_a_strategy(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $creator = $this->member($customer);
        $this->grant($customer, $creator, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE], [$hr]);

        $base = [
            'cause' => 'manglende rutiner',
            'event' => 'en hendelse inntreffer',
            'consequence' => 'virksomheten rammes',
            'business_area_id' => $hr->id,
            'status' => Risk::STATUS_IDENTIFIED,
        ];

        $this->actingAs($creator)->post('/app/risk/risks', $base + ['title' => 'Med valg', 'treatment_strategy' => 'share'])
            ->assertSessionHasNoErrors();
        $this->actingAs($creator)->post('/app/risk/risks', $base + ['title' => 'Uten valg'])
            ->assertSessionHasNoErrors();

        $this->assertSame('share', Risk::query()->where('customer_id', $customer->id)->where('title', 'Med valg')->value('treatment_strategy'));
        $this->assertNull(Risk::query()->where('customer_id', $customer->id)->where('title', 'Uten valg')->value('treatment_strategy'));
    }

    public function test_an_unknown_strategy_is_refused_by_the_controller_and_the_database(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', strategy: Risk::TREATMENT_REDUCE);
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        foreach (['transfer', 'Redusere', 'ignore', 'ACCEPT'] as $invalid) {
            $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['treatment_strategy' => $invalid]))
                ->assertSessionHasErrors('treatment_strategy');
        }
        $this->assertSame(Risk::TREATMENT_REDUCE, $risk->fresh()->treatment_strategy);

        DB::statement('SAVEPOINT strategy_check');
        try {
            DB::table('risks')->where('id', $risk->id)->update(['treatment_strategy' => 'transfer']);
            $this->fail('The database accepted a treatment strategy outside the fixed set.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('risks_treatment_strategy_check', $exception->getMessage());
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT strategy_check');
        }
    }

    public function test_the_strategy_creates_no_acceptance_or_actions_and_leaves_status_and_assessment_alone(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', Risk::STATUS_IN_TREATMENT);
        $assessment = RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_at' => '2026-10-01 08:00:00',
            'rationale' => 'Vurdert',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
            'residual_likelihood' => 2,
            'residual_consequence' => 3,
        ]);

        // risk.edit without risk.accept may still choose «accept» as the planned direction.
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        $before = $this->showProps($editor, $risk);
        $this->assertFalse($before['permissions']['can_accept']);

        foreach ([Risk::TREATMENT_REDUCE, Risk::TREATMENT_ACCEPT] as $strategy) {
            $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk->fresh(), ['treatment_strategy' => $strategy]))
                ->assertSessionHasNoErrors();

            $after = $this->showProps($editor, $risk);
            $this->assertSame($strategy, $after['risk']['treatment_strategy']);
            $this->assertSame(Risk::STATUS_IN_TREATMENT, $after['risk']['status']);
            $this->assertSame($before['assessments'], $after['assessments']);
            $this->assertNull($after['risk_acceptance']['current']);
            $this->assertSame([], $after['risk_acceptance']['history']);
            $this->assertSame([], $after['treatment_actions']);
        }

        $this->assertSame(0, RiskAcceptance::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(0, RiskTreatmentAction::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());
        $this->assertSame(
            [4, 4, 2, 3],
            array_values($assessment->fresh()->only(['inherent_likelihood', 'inherent_consequence', 'residual_likelihood', 'residual_consequence'])),
        );
    }

    public function test_risk_view_reads_the_strategy_but_only_risk_edit_in_the_area_changes_it(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', strategy: Risk::TREATMENT_REDUCE);

        $viewer = $this->member($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        // risk.accept is not risk.edit: it decides acceptance, not the direction.
        $acceptor = $this->member($customer);
        $this->grant($customer, $acceptor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT], [$hr]);

        // risk.edit, but in another area; risk.view in HR comes from a different role.
        $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$finance]);

        $props = $this->showProps($viewer, $risk);
        $this->assertSame(Risk::TREATMENT_REDUCE, $props['risk']['treatment_strategy']);
        $this->assertFalse($props['permissions']['can_edit']);

        foreach ([$viewer, $acceptor, $elsewhere] as $user) {
            $this->actingAs($user)->patch($this->url($risk), $this->payload($risk, ['treatment_strategy' => 'avoid']))->assertForbidden();
        }

        // System Owner without a role reaching HR does not see the risk at all.
        $this->actingAs($systemOwner)->get($this->url($risk))->assertNotFound();
        $this->actingAs($systemOwner)->patch($this->url($risk), $this->payload($risk, ['treatment_strategy' => 'avoid']))->assertNotFound();

        $this->assertSame(Risk::TREATMENT_REDUCE, $risk->fresh()->treatment_strategy);
    }

    public function test_a_hidden_or_foreign_risk_leaks_no_strategy_and_cannot_be_changed(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $hidden = $this->risk($customer, $finance, 'Skjult', strategy: Risk::TREATMENT_SHARE);

        ['customer' => $foreignCustomer] = $this->context();
        $foreign = $this->risk($foreignCustomer, $this->area($foreignCustomer, 'HR'), 'Fremmed', strategy: Risk::TREATMENT_SHARE);

        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);
        $visible = $this->risk($customer, $hr, 'Synlig');

        foreach ([$hidden, $foreign] as $risk) {
            $this->actingAs($editor)->get($this->url($risk))->assertNotFound();
            $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['treatment_strategy' => 'avoid']))->assertNotFound();
            $this->assertSame(Risk::TREATMENT_SHARE, $risk->fresh()->treatment_strategy);
        }

        $page = $this->actingAs($editor)->get('/app/risk')->assertOk()->viewData('page');
        $this->assertSame([$visible->id], array_column($page['props']['risks'], 'id'));
        $this->assertSame([null], array_column($page['props']['risks'], 'treatment_strategy'));
        $this->assertStringNotContainsString('Skjult', json_encode($page));
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
