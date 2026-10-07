<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Explicit acceptance of residual risk.
 *
 * What these tests defend:
 *
 *  - Accepting and revoking take risk.accept in the risk's area, from the same role. risk.edit and
 *    risk.assess do not grant it; risk.view alone reads the decision and its history.
 *  - Only the latest assessment, and only one with an explicit residual risk, can be accepted —
 *    one active acceptance per assessment. Accepting never changes status or assessment.
 *  - An acceptance is never edited; revoking keeps it as history, and a new one can follow.
 *  - The current acceptance is computed: the non-revoked one of the latest assessment. A new
 *    assessment makes it historical. «Utløpt» is computed and valid_until is inclusive.
 *  - A hidden or foreign risk, and another risk's assessment or acceptance, never leak.
 */
class RiskAcceptanceTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const DECIDER = [
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::RISK_ACCEPT,
    ];

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
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_a_decider_accepts_the_latest_assessment_without_touching_status_or_assessment(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Feil lønnsutbetaling', Risk::STATUS_IN_TREATMENT);
        $assessment = $this->assessment($risk, residual: true);
        $decider = $this->member($customer);
        $this->grant($customer, $decider, self::DECIDER, [$hr]);

        $props = $this->showProps($decider, $risk);
        $this->assertTrue($props['permissions']['can_accept']);
        $this->assertSame($assessment->id, $props['risk_acceptance']['latest_assessment_id']);
        $this->assertTrue($props['risk_acceptance']['has_residual']);
        $this->assertNull($props['risk_acceptance']['current']);

        $this->actingAs($decider)->post($this->url($risk), [
            'assessment_id' => $assessment->id,
            'rationale' => 'Restrisikoen er innenfor det vi kan bære.',
            'valid_until' => '2027-03-31',
        ])->assertRedirect()->assertSessionHas('success');

        $acceptance = RiskAcceptance::query()->where('risk_id', $risk->id)->sole();
        $this->assertSame($assessment->id, (int) $acceptance->assessment_id);
        $this->assertSame($decider->id, (int) $acceptance->accepted_by_user_id);
        $this->assertSame($customer->id, (int) $acceptance->customer_id);
        $this->assertSame('2027-03-31', $acceptance->valid_until->toDateString());
        $this->assertNull($acceptance->revoked_at);

        // Not a status, and the assessment is untouched.
        $this->assertSame(Risk::STATUS_IN_TREATMENT, $risk->fresh()->status);
        $this->assertSame(3, (int) $assessment->fresh()->residual_likelihood);
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $risk->id)->count());

        $current = $this->showProps($decider, $risk)['risk_acceptance']['current'];
        $this->assertSame($acceptance->id, $current['id']);
        $this->assertSame($decider->name, $current['accepted_by_name']);
        $this->assertSame('Restrisikoen er innenfor det vi kan bære.', $current['rationale']);
        $this->assertSame('2027-03-31', $current['valid_until']);
        $this->assertFalse($current['is_expired']);

        // One active acceptance per assessment.
        $this->actingAs($decider)->post($this->url($risk), [
            'assessment_id' => $assessment->id,
            'rationale' => 'Igjen',
        ])->assertSessionHasErrors('assessment_id');
        $this->assertSame(1, RiskAcceptance::query()->where('risk_id', $risk->id)->count());
    }

    public function test_rationale_is_required_and_valid_until_is_optional(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $assessment = $this->assessment($risk, residual: true);
        $decider = $this->member($customer);
        $this->grant($customer, $decider, self::DECIDER, [$hr]);

        $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => ' ', 'valid_until' => '31.03.2027'])
            ->assertSessionHasErrors(['rationale', 'valid_until']);

        $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'Akseptabelt'])
            ->assertSessionHasNoErrors();
        $this->assertNull(RiskAcceptance::query()->where('risk_id', $risk->id)->sole()->valid_until);
    }

    public function test_risk_edit_or_assess_without_risk_accept_cannot_accept_but_risk_view_reads(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $assessment = $this->assessment($risk, residual: true);
        $acceptance = $this->acceptance($risk, $assessment);

        $editor = $this->member($customer);
        $this->grant($customer, $editor, [
            CustomerPermissionCatalog::RISK_VIEW,
            CustomerPermissionCatalog::RISK_EDIT,
            CustomerPermissionCatalog::RISK_ASSESS,
            CustomerPermissionCatalog::RISK_DELETE,
        ], [$hr]);

        $props = $this->showProps($editor, $risk);
        $this->assertFalse($props['permissions']['can_accept']);
        // risk.view is enough to read the decision.
        $this->assertSame($acceptance->id, $props['risk_acceptance']['current']['id']);

        $this->actingAs($editor)->post("{$this->url($risk)}/{$acceptance->id}/revoke")->assertForbidden();
        $this->assertNull($acceptance->fresh()->revoked_at);

        $other = $this->risk($customer, $hr, 'Annen');
        $otherAssessment = $this->assessment($other, residual: true);
        $this->actingAs($editor)->post($this->url($other), ['assessment_id' => $otherAssessment->id, 'rationale' => 'x'])->assertForbidden();
        $this->assertSame(0, RiskAcceptance::query()->where('risk_id', $other->id)->count());
    }

    public function test_risk_accept_must_reach_the_risks_area_through_the_same_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $assessment = $this->assessment($risk, residual: true);

        // risk.accept in another area, plus read in HR from another role: no pair, no accept.
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->grant($customer, $user, self::DECIDER, [$finance]);
        $this->assertFalse($this->showProps($user, $risk)['permissions']['can_accept']);
        $this->actingAs($user)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'x'])->assertForbidden();

        // risk.accept in a role that reaches no area.
        $other = $this->member($customer);
        $this->grant($customer, $other, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->grant($customer, $other, [CustomerPermissionCatalog::RISK_ACCEPT]);
        $this->actingAs($other)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'x'])->assertForbidden();

        // System Owner holds risk.accept implicitly, but that grant carries no area.
        $this->grant($customer, $owner, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->actingAs($owner)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'x'])->assertForbidden();

        $this->assertSame(0, RiskAcceptance::query()->where('risk_id', $risk->id)->count());
    }

    public function test_an_assessment_without_residual_risk_cannot_be_accepted(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $assessment = $this->assessment($risk, residual: false);
        $decider = $this->member($customer);
        $this->grant($customer, $decider, self::DECIDER, [$hr]);

        $this->assertFalse($this->showProps($decider, $risk)['risk_acceptance']['has_residual']);

        $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'x'])
            ->assertSessionHasErrors('assessment_id');
        $this->assertSame(0, RiskAcceptance::query()->where('risk_id', $risk->id)->count());
    }

    public function test_an_older_assessment_cannot_be_accepted(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $older = $this->assessment($risk, residual: true);
        Carbon::setTestNow('2026-10-05 10:00:00');
        $latest = $this->assessment($risk, residual: true);
        $decider = $this->member($customer);
        $this->grant($customer, $decider, self::DECIDER, [$hr]);

        $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $older->id, 'rationale' => 'x'])
            ->assertSessionHasErrors('assessment_id');
        $this->assertSame(0, RiskAcceptance::query()->where('risk_id', $risk->id)->count());

        $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $latest->id, 'rationale' => 'x'])
            ->assertSessionHasNoErrors();
    }

    public function test_revoke_then_accept_again_keeps_the_history(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $assessment = $this->assessment($risk, residual: true);
        $decider = $this->member($customer);
        $this->grant($customer, $decider, self::DECIDER, [$hr]);

        $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'Første'])->assertSessionHasNoErrors();
        $first = RiskAcceptance::query()->where('risk_id', $risk->id)->sole();

        Carbon::setTestNow('2026-10-04 11:00:00');
        $this->actingAs($decider)->post("{$this->url($risk)}/{$first->id}/revoke")->assertRedirect()->assertSessionHas('success');
        $first->refresh();
        $this->assertSame('2026-10-04 11:00:00', $first->revoked_at->format('Y-m-d H:i:s'));
        $this->assertSame($decider->id, (int) $first->revoked_by_user_id);
        $this->assertSame('Første', $first->rationale);

        $decision = $this->showProps($decider, $risk)['risk_acceptance'];
        $this->assertNull($decision['current']);
        $this->assertSame([[$first->id, 'revoked']], array_map(fn (array $row): array => [$row['id'], $row['state']], $decision['history']));

        // Revoking twice is refused.
        $this->actingAs($decider)->post("{$this->url($risk)}/{$first->id}/revoke")->assertSessionHasErrors('acceptance');

        Carbon::setTestNow('2026-10-04 12:00:00');
        $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'Andre'])->assertSessionHasNoErrors();

        $decision = $this->showProps($decider, $risk)['risk_acceptance'];
        $this->assertSame('Andre', $decision['current']['rationale']);
        $this->assertSame([$first->id], array_column($decision['history'], 'id'));
        $this->assertSame(2, RiskAcceptance::query()->where('risk_id', $risk->id)->count());
    }

    public function test_an_acceptance_cannot_be_edited_or_deleted(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $acceptance = $this->acceptance($risk, $this->assessment($risk, residual: true));

        try {
            $acceptance->update(['rationale' => 'Omskrevet']);
            $this->fail('An acceptance must not be editable.');
        } catch (LogicException) {
        }

        try {
            $acceptance->delete();
            $this->fail('An acceptance must not be deletable on its own.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('risk_acceptances', ['id' => $acceptance->id, 'rationale' => 'Akseptert']);
    }

    public function test_a_new_assessment_makes_the_old_acceptance_historical(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $old = $this->acceptance($risk, $this->assessment($risk, residual: true));

        $assessor = $this->member($customer);
        $this->grant($customer, $assessor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);

        Carbon::setTestNow('2026-10-06 09:00:00');
        $this->actingAs($assessor)->post("/app/risk/risks/{$risk->id}/assessments", [
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
            'residual_likelihood' => 2,
            'residual_consequence' => 3,
            'rationale' => 'Ny vurdering',
        ])->assertSessionHasNoErrors();

        $decision = $this->showProps($assessor, $risk)['risk_acceptance'];
        $this->assertNull($decision['current']);
        $this->assertNotSame((int) $old->assessment_id, $decision['latest_assessment_id']);
        $this->assertSame([[$old->id, 'superseded']], array_map(fn (array $row): array => [$row['id'], $row['state']], $decision['history']));
        // Nothing was written to the old acceptance.
        $this->assertNull($old->fresh()->revoked_at);
    }

    public function test_expiry_is_computed_and_valid_until_is_inclusive(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $acceptance = $this->acceptance($risk, $this->assessment($risk, residual: true), validUntil: '2026-12-31');
        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        Carbon::setTestNow('2026-12-31 23:59:00');
        $this->assertFalse($this->showProps($reader, $risk)['risk_acceptance']['current']['is_expired']);
        $this->assertFalse($acceptance->isExpired());

        Carbon::setTestNow('2027-01-01 00:00:01');
        $current = $this->showProps($reader, $risk)['risk_acceptance']['current'];
        // Expired is still the current acceptance, shown as expired.
        $this->assertSame($acceptance->id, $current['id']);
        $this->assertTrue($current['is_expired']);

        $open = $this->acceptance($this->risk($customer, $hr, 'Uten sluttdato'), null, validUntil: null);
        $this->assertFalse($open->isExpired(Carbon::parse('2099-01-01')));

        // A valid-until in the past is refused.
        $decider = $this->member($customer);
        $this->grant($customer, $decider, self::DECIDER, [$hr]);
        $fresh = $this->risk($customer, $hr, 'Ny');
        $assessment = $this->assessment($fresh, residual: true);
        $this->actingAs($decider)->post($this->url($fresh), ['assessment_id' => $assessment->id, 'rationale' => 'x', 'valid_until' => '2026-12-31'])
            ->assertSessionHasErrors('valid_until');
    }

    public function test_hidden_and_foreign_risks_assessments_and_acceptances_do_not_leak(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $visible = $this->risk($customer, $hr, 'Synlig');
        $visibleAssessment = $this->assessment($visible, residual: true);
        $hidden = $this->risk($customer, $finance, 'Skjult');
        $hiddenAssessment = $this->assessment($hidden, residual: true);
        $hiddenAcceptance = $this->acceptance($hidden, $hiddenAssessment);

        ['customer' => $foreignCustomer] = $this->context();
        $foreignArea = $this->area($foreignCustomer, 'HR');
        $foreign = $this->risk($foreignCustomer, $foreignArea, 'Fremmed');
        $foreignAssessment = $this->assessment($foreign, residual: true);
        $foreignAcceptance = $this->acceptance($foreign, $foreignAssessment);

        $decider = $this->member($customer);
        $this->grant($customer, $decider, self::DECIDER, [$hr]);

        // A hidden or foreign risk is a 404, exactly like a missing one.
        foreach ([[$hidden, $hiddenAssessment, $hiddenAcceptance], [$foreign, $foreignAssessment, $foreignAcceptance]] as [$risk, $assessment, $acceptance]) {
            $this->actingAs($decider)->post($this->url($risk), ['assessment_id' => $assessment->id, 'rationale' => 'x'])->assertNotFound();
            $this->actingAs($decider)->post("{$this->url($risk)}/{$acceptance->id}/revoke")->assertNotFound();
        }
        $this->actingAs($decider)->post('/app/risk/risks/999999999/acceptances', ['assessment_id' => 1, 'rationale' => 'x'])->assertNotFound();

        // Another risk's assessment, under a visible risk: the same 422 as an unknown id.
        foreach ([$hiddenAssessment->id, $foreignAssessment->id, 999999999] as $assessmentId) {
            $this->actingAs($decider)->post($this->url($visible), ['assessment_id' => $assessmentId, 'rationale' => 'x'])
                ->assertSessionHasErrors('assessment_id');
        }

        // Another risk's acceptance, under a visible risk: 404, and it stays as it was.
        $this->actingAs($decider)->post("{$this->url($visible)}/{$hiddenAcceptance->id}/revoke")->assertNotFound();
        $this->actingAs($decider)->post("{$this->url($visible)}/{$foreignAcceptance->id}/revoke")->assertNotFound();
        $this->assertNull($hiddenAcceptance->fresh()->revoked_at);
        $this->assertNull($foreignAcceptance->fresh()->revoked_at);

        $this->assertSame(0, RiskAcceptance::query()->where('risk_id', $visible->id)->count());
        $this->assertSame([], $this->showProps($decider, $visible)['risk_acceptance']['history']);
        $this->assertNotNull($visibleAssessment->id);
    }

    public function test_deleting_the_risk_takes_its_acceptances_along(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil');
        $this->acceptance($risk, $this->assessment($risk, residual: true));
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_DELETE], [$hr]);

        $this->actingAs($user)->delete("/app/risk/risks/{$risk->id}")->assertRedirect();

        $this->assertDatabaseMissing('risk_acceptances', ['risk_id' => $risk->id]);
    }

    private function assessment(Risk $risk, bool $residual): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_at' => now(),
            'rationale' => 'Vurdert',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
            'residual_likelihood' => $residual ? 3 : null,
            'residual_consequence' => $residual ? 2 : null,
        ]);
    }

    private function acceptance(Risk $risk, ?RiskAssessment $assessment, ?string $validUntil = null): RiskAcceptance
    {
        $assessment ??= $this->assessment($risk, residual: true);

        return RiskAcceptance::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessment_id' => $assessment->id,
            'rationale' => 'Akseptert',
            'accepted_at' => now(),
            'valid_until' => $validUntil,
        ]);
    }

    private function url(Risk $risk): string
    {
        return "/app/risk/risks/{$risk->id}/acceptances";
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

    private function risk(Customer $customer, BusinessArea $area, string $title, string $status = Risk::STATUS_IDENTIFIED): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => $status,
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
