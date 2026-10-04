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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Periodisk vurdering of a risk.
 *
 * What these tests defend:
 *
 *  - Only the interval is stored. The next review date is the latest assessment's day plus the
 *    interval in calendar months, computed on read: a new assessment moves it, a changed interval
 *    recomputes it, and without an interval or without any assessment there is none.
 *  - «Forfalt» from the day after the next review date; never for a closed risk.
 *  - Changing the interval takes risk.edit in the risk's area; risk.view reads the schedule; an
 *    assessment still takes risk.assess.
 *  - A hidden or foreign risk's schedule never leaks, and its interval cannot be changed.
 */
class RiskReviewScheduleTest extends TestCase
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
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_setting_an_interval_computes_the_next_review_from_the_latest_assessment(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Feil lønnsutbetaling', Risk::STATUS_MONITORED);
        $this->assessment($risk, '2026-08-15 09:00:00');
        $this->assessment($risk, '2026-10-04 08:00:00');
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        $props = $this->showProps($editor, $risk);
        $this->assertSame([1, 3, 6, 12], $props['review_intervals']);
        $this->assertSame([
            'interval_months' => null,
            'last_assessed_on' => '2026-10-04',
            'next_review_on' => null,
            'is_overdue' => false,
        ], $props['review_schedule']);

        $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['review_interval_months' => '3']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(3, $risk->fresh()->review_interval_months);
        $schedule = $this->showProps($editor, $risk)['review_schedule'];
        $this->assertSame(3, $schedule['interval_months']);
        $this->assertSame('2026-10-04', $schedule['last_assessed_on']);
        $this->assertSame('2027-01-04', $schedule['next_review_on']);
        $this->assertFalse($schedule['is_overdue']);

        // A changed interval is computed again from the same latest assessment.
        $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['review_interval_months' => '12']))
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-10-04', $this->showProps($editor, $risk)['review_schedule']['next_review_on']);

        // Back to no fixed interval: no next date.
        $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['review_interval_months' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($risk->fresh()->review_interval_months);
        $this->assertNull($this->showProps($editor, $risk)['review_schedule']['next_review_on']);
    }

    public function test_only_the_fixed_intervals_are_accepted_and_an_omitted_field_keeps_the_interval(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', interval: 6);
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        foreach (['2', '24', '0', 'kvartalsvis'] as $invalid) {
            $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['review_interval_months' => $invalid]))
                ->assertSessionHasErrors('review_interval_months');
        }
        $this->assertSame(6, $risk->fresh()->review_interval_months);

        $payload = $this->payload($risk, ['title' => 'Lønnsfeil i desember']);
        unset($payload['review_interval_months']);
        $this->actingAs($editor)->patch($this->url($risk), $payload)->assertSessionHasNoErrors();
        $this->assertSame(6, $risk->fresh()->review_interval_months);
        $this->assertSame('Lønnsfeil i desember', $risk->fresh()->title);
    }

    public function test_a_new_assessment_moves_the_next_review(): void
    {
        Carbon::setTestNow('2026-10-04 10:00:00');
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', Risk::STATUS_MONITORED, interval: 3);
        $this->assessment($risk, '2026-06-30 12:00:00');
        $assessor = $this->member($customer);
        $this->grant($customer, $assessor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS], [$hr]);

        $schedule = $this->showProps($assessor, $risk)['review_schedule'];
        $this->assertSame('2026-09-30', $schedule['next_review_on']);
        $this->assertTrue($schedule['is_overdue']);

        $this->actingAs($assessor)->post("/app/risk/risks/{$risk->id}/assessments", [
            'inherent_likelihood' => 3,
            'inherent_consequence' => 3,
            'rationale' => 'Kvartalsvis gjennomgang',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $schedule = $this->showProps($assessor, $risk)['review_schedule'];
        $this->assertSame('2026-10-04', $schedule['last_assessed_on']);
        $this->assertSame('2027-01-04', $schedule['next_review_on']);
        $this->assertFalse($schedule['is_overdue']);
    }

    public function test_an_interval_without_any_assessment_has_no_next_review_yet(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Ny risiko', interval: 1);
        $viewer = $this->member($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        $this->assertSame([
            'interval_months' => 1,
            'last_assessed_on' => null,
            'next_review_on' => null,
            'is_overdue' => false,
        ], $this->showProps($viewer, $risk)['review_schedule']);
    }

    public function test_overdue_starts_the_day_after_the_next_review_and_never_for_a_closed_risk(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', Risk::STATUS_IN_TREATMENT, interval: 3);
        $this->assessment($risk, '2026-10-04 08:00:00');
        $viewer = $this->member($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        Carbon::setTestNow('2027-01-04 22:00:00');
        $schedule = $this->showProps($viewer, $risk)['review_schedule'];
        $this->assertSame('2027-01-04', $schedule['next_review_on']);
        $this->assertFalse($schedule['is_overdue'], 'Due today is not overdue.');

        Carbon::setTestNow('2027-01-05 00:00:01');
        $this->assertTrue($this->showProps($viewer, $risk)['review_schedule']['is_overdue']);

        // Closed: the date is still shown, but nothing is overdue.
        $risk->forceFill(['status' => Risk::STATUS_CLOSED])->save();
        $schedule = $this->showProps($viewer, $risk)['review_schedule'];
        $this->assertSame('2027-01-04', $schedule['next_review_on']);
        $this->assertFalse($schedule['is_overdue']);
    }

    public function test_risk_view_reads_the_schedule_but_only_risk_edit_in_the_area_changes_the_interval(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $risk = $this->risk($customer, $hr, 'Lønnsfeil', interval: 3);

        $viewer = $this->member($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        $assessor = $this->member($customer);
        $this->grant($customer, $assessor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT], [$hr]);

        // risk.edit, but in another area; risk.view in HR comes from a different role.
        $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$finance]);

        $props = $this->showProps($viewer, $risk);
        $this->assertSame(3, $props['review_schedule']['interval_months']);
        $this->assertFalse($props['permissions']['can_edit']);

        foreach ([$viewer, $assessor, $elsewhere] as $user) {
            $this->actingAs($user)->patch($this->url($risk), $this->payload($risk, ['review_interval_months' => '12']))->assertForbidden();
        }

        // System Owner without a role reaching HR does not see the risk at all.
        $this->actingAs($systemOwner)->patch($this->url($risk), $this->payload($risk, ['review_interval_months' => '12']))->assertNotFound();

        $this->assertSame(3, $risk->fresh()->review_interval_months);
    }

    public function test_a_hidden_or_foreign_risk_leaks_no_schedule_and_its_interval_cannot_be_changed(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $hidden = $this->risk($customer, $finance, 'Skjult', interval: 3);
        $this->assessment($hidden, '2026-01-01 08:00:00');

        ['customer' => $foreignCustomer] = $this->context();
        $foreign = $this->risk($foreignCustomer, $this->area($foreignCustomer, 'HR'), 'Fremmed', interval: 3);
        $this->assessment($foreign, '2026-01-01 08:00:00');

        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);
        $visible = $this->risk($customer, $hr, 'Synlig');

        foreach ([$hidden, $foreign] as $risk) {
            $this->actingAs($editor)->get($this->url($risk))->assertNotFound();
            $this->actingAs($editor)->patch($this->url($risk), $this->payload($risk, ['review_interval_months' => '12']))->assertNotFound();
            $this->assertSame(3, $risk->fresh()->review_interval_months);
        }

        // The register lists only the visible risk, and nothing about the hidden one's schedule.
        $page = $this->actingAs($editor)->get('/app/risk')->assertOk()->viewData('page');
        $this->assertSame([$visible->id], array_column($page['props']['risks'], 'id'));
        $this->assertStringNotContainsString('Skjult', json_encode($page));

        // The visible risk's own schedule reflects only itself.
        $this->assertSame([
            'interval_months' => null,
            'last_assessed_on' => null,
            'next_review_on' => null,
            'is_overdue' => false,
        ], $this->showProps($editor, $visible)['review_schedule']);
    }

    private function assessment(Risk $risk, string $assessedAt): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_at' => $assessedAt,
            'rationale' => 'Vurdert',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 4,
            'inherent_consequence' => 4,
        ]);
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
            'description' => $risk->description,
            'business_area_id' => $risk->business_area_id,
            'owner_user_id' => $risk->owner_user_id,
            'status' => $risk->status,
            'review_interval_months' => $risk->review_interval_months,
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

    private function risk(Customer $customer, BusinessArea $area, string $title, string $status = Risk::STATUS_IDENTIFIED, ?int $interval = null): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'status' => $status,
            'review_interval_months' => $interval,
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
