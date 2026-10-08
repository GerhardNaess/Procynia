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
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * «Trenger oppmerksomhet» on the risk register.
 *
 * What these tests defend:
 *
 *  - Six fixed rules, each giving the right finding: ikke vurdert, restrisiko ikke vurdert, høy/svært
 *    høy restrisiko, vurdering forfalt, aksept utløpt, tiltak forfalt.
 *  - Closed risks never count. The total counts unique risks; category counts may overlap.
 *  - Deadlines today are not overdue; old-assessment and revoked acceptances and completed tiltak
 *    never count; high inherent risk without residual is not "high residual".
 *  - Everything is computed over the visible set only: hidden areas move neither the total nor a
 *    category, «Alle» sees the whole customer, and System Owner without an area gets nothing.
 */
class RiskAttentionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
        Carbon::setTestNow('2026-10-04 10:00:00');
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

    public function test_each_rule_gives_its_finding(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $viewer = $this->viewer($customer, [$hr]);

        $unassessed = $this->risk($customer, $hr, 'A Ikke vurdert');

        $noResidual = $this->risk($customer, $hr, 'B Uten restrisiko');
        $this->assessment($noResidual, '2026-09-01 09:00:00');

        $high = $this->risk($customer, $hr, 'C Høy restrisiko');
        $this->assessment($high, '2026-09-01 09:00:00', residual: [4, 3]);

        $veryHigh = $this->risk($customer, $hr, 'D Svært høy restrisiko');
        $this->assessment($veryHigh, '2026-09-01 09:00:00', residual: [5, 5]);

        $overdueReview = $this->risk($customer, $hr, 'E Forfalt vurdering', interval: 1);
        $this->assessment($overdueReview, '2026-08-15 09:00:00', residual: [1, 1]);

        $expiredAcceptance = $this->risk($customer, $hr, 'F Utløpt aksept');
        $assessed = $this->assessment($expiredAcceptance, '2026-09-01 09:00:00', residual: [2, 2]);
        $this->acceptance($assessed, '2026-10-03');

        $overdueAction = $this->risk($customer, $hr, 'G Forfalt tiltak');
        $this->assessment($overdueAction, '2026-09-01 09:00:00', residual: [1, 2]);
        $this->action($overdueAction, '2026-10-01');
        $this->action($overdueAction, '2026-09-20');

        $calm = $this->risk($customer, $hr, 'H Rolig');
        $this->assessment($calm, '2026-09-01 09:00:00', residual: [2, 2]);

        $attention = $this->attention($viewer);

        $this->assertSame([
            'high_residual' => [$high->id, $veryHigh->id],
            'not_assessed' => [$unassessed->id],
            'residual_not_assessed' => [$noResidual->id],
            'review_overdue' => [$overdueReview->id],
            'acceptance_expired' => [$expiredAcceptance->id],
            'actions_overdue' => [$overdueAction->id],
        ], $this->idsByCategory($attention));
        $this->assertSame(7, $attention['total']);
        $this->assertSame('areas', $attention['scope']);

        $rows = $this->rowsByCategory($attention);
        $this->assertSame(['level' => 'high', 'score' => 12], $rows['high_residual'][0]['detail']);
        $this->assertSame(['level' => 'very_high', 'score' => 25], $rows['high_residual'][1]['detail']);
        $this->assertSame(['assessed_on' => '2026-09-01'], $rows['residual_not_assessed'][0]['detail']);
        $this->assertSame(['next_review_on' => '2026-09-15'], $rows['review_overdue'][0]['detail']);
        $this->assertSame(['valid_until' => '2026-10-03'], $rows['acceptance_expired'][0]['detail']);
        $this->assertSame(['count' => 2, 'earliest_due_on' => '2026-09-20'], $rows['actions_overdue'][0]['detail']);
        $this->assertSame('HR', $rows['not_assessed'][0]['area_name']);
        $this->assertSame(route('app.risk.show', ['riskId' => $unassessed->id]), $rows['not_assessed'][0]['url']);
    }

    public function test_a_risk_with_several_findings_counts_once_in_the_total_and_in_each_category(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $viewer = $this->viewer($customer, [$hr]);

        $everything = $this->risk($customer, $hr, 'Alt på en gang', interval: 1);
        $assessed = $this->assessment($everything, '2026-08-01 09:00:00', residual: [4, 4]);
        $this->acceptance($assessed, '2026-09-30');
        $this->action($everything, '2026-10-01');

        $other = $this->risk($customer, $hr, 'Bare forfalt tiltak');
        $this->assessment($other, '2026-09-01 09:00:00', residual: [1, 1]);
        $this->action($other, '2026-10-02');

        $attention = $this->attention($viewer);

        $this->assertSame(2, $attention['total']);
        $this->assertSame([
            'high_residual' => [$everything->id],
            'review_overdue' => [$everything->id],
            'acceptance_expired' => [$everything->id],
            'actions_overdue' => [$everything->id, $other->id],
        ], $this->idsByCategory($attention));
    }

    public function test_closed_risks_never_count(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $viewer = $this->viewer($customer, [$hr]);

        $closed = $this->risk($customer, $hr, 'Lukket', Risk::STATUS_CLOSED, interval: 1);
        $assessed = $this->assessment($closed, '2026-01-01 09:00:00', residual: [5, 5]);
        $this->acceptance($assessed, '2026-02-01');
        $this->action($closed, '2026-03-01');
        $this->risk($customer, $hr, 'Lukket uten vurdering', Risk::STATUS_CLOSED);

        $attention = $this->attention($viewer);

        $this->assertSame(0, $attention['total']);
        $this->assertSame([], $attention['categories']);
    }

    public function test_high_inherent_risk_without_residual_is_not_high_residual(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $viewer = $this->viewer($customer, [$hr]);

        $risk = $this->risk($customer, $hr, 'Høy iboende');
        $this->assessment($risk, '2026-09-01 09:00:00', inherent: [5, 5]);

        // Only the latest assessment counts: an older high residual is history.
        $older = $this->risk($customer, $hr, 'Tidligere høy');
        $this->assessment($older, '2026-06-01 09:00:00', residual: [5, 5]);
        $this->assessment($older, '2026-09-01 09:00:00', residual: [2, 2]);

        $this->assertSame(['residual_not_assessed' => [$risk->id]], $this->idsByCategory($this->attention($viewer)));
    }

    public function test_deadlines_today_are_not_overdue(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $viewer = $this->viewer($customer, [$hr]);

        $risk = $this->risk($customer, $hr, 'Frist i dag', interval: 1);
        $assessed = $this->assessment($risk, '2026-09-04 09:00:00', residual: [1, 1]);
        $this->acceptance($assessed, '2026-10-04');
        $this->action($risk, '2026-10-04');

        $this->assertSame(0, $this->attention($viewer)['total']);

        Carbon::setTestNow('2026-10-05 08:00:00');

        $this->assertSame([
            'review_overdue' => [$risk->id],
            'acceptance_expired' => [$risk->id],
            'actions_overdue' => [$risk->id],
        ], $this->idsByCategory($this->attention($viewer)));
    }

    public function test_old_revoked_and_unexpired_acceptances_and_completed_actions_do_not_count(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $viewer = $this->viewer($customer, [$hr]);

        // An expired acceptance of an older assessment is history.
        $superseded = $this->risk($customer, $hr, 'Ny vurdering etter aksept');
        $old = $this->assessment($superseded, '2026-06-01 09:00:00', residual: [2, 2]);
        $this->acceptance($old, '2026-07-01');
        $this->assessment($superseded, '2026-09-01 09:00:00', residual: [2, 2]);

        // A revoked, expired acceptance of the latest assessment does not count either.
        $revoked = $this->risk($customer, $hr, 'Tilbaketrukket aksept');
        $latest = $this->assessment($revoked, '2026-09-01 09:00:00', residual: [2, 2]);
        $this->acceptance($latest, '2026-09-30', revoked: true);

        // Neither one without valid_until, nor one still valid.
        $open = $this->risk($customer, $hr, 'Gyldig aksept');
        $this->acceptance($this->assessment($open, '2026-09-01 09:00:00', residual: [2, 2]), null);
        $valid = $this->risk($customer, $hr, 'Aksept i kraft');
        $this->acceptance($this->assessment($valid, '2026-09-01 09:00:00', residual: [2, 2]), '2027-01-01');

        $done = $this->risk($customer, $hr, 'Fullført tiltak');
        $this->assessment($done, '2026-09-01 09:00:00', residual: [1, 1]);
        $this->action($done, '2026-09-01', RiskTreatmentAction::STATUS_COMPLETED);
        $this->action($done, '2026-12-01');

        $this->assertSame(0, $this->attention($viewer)['total']);
    }

    public function test_hidden_areas_and_other_tenants_move_neither_total_nor_categories(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $viewer = $this->viewer($customer, [$hr]);

        $visible = $this->risk($customer, $hr, 'Synlig');

        // Every rule fires in the hidden area.
        $hidden = $this->risk($customer, $finance, 'Skjult', interval: 1);
        $assessed = $this->assessment($hidden, '2026-01-01 09:00:00', residual: [5, 5]);
        $this->acceptance($assessed, '2026-02-01');
        $this->action($hidden, '2026-03-01');
        $this->risk($customer, $finance, 'Skjult uten vurdering');

        // An edit role in Økonomi without risk.view does not reach it either.
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::RISK_EDIT], [$finance]);

        ['customer' => $foreign] = $this->context();
        $this->risk($foreign, $this->area($foreign, 'HR'), 'Annen kunde');

        $attention = $this->attention($viewer);

        $this->assertSame(1, $attention['total']);
        $this->assertSame(['not_assessed' => [$visible->id]], $this->idsByCategory($attention));
        $this->assertSame('areas', $attention['scope']);

        $page = json_encode($attention);
        $this->assertStringNotContainsString('Skjult', $page);
        $this->assertStringNotContainsString('Økonomi', $page);
    }

    public function test_a_role_with_all_areas_sees_the_whole_authorised_customer_picture(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $a = $this->risk($customer, $hr, 'A HR');
        $b = $this->risk($customer, $finance, 'B Økonomi');

        $all = $this->member($customer);
        $role = $this->grant($customer, $all, [CustomerPermissionCatalog::RISK_VIEW]);
        $role->syncBusinessAreas(true, []);

        // An area created later is reached too.
        $later = $this->risk($customer, $this->area($customer, 'Beredskap'), 'C Beredskap');

        ['customer' => $foreign] = $this->context();
        $this->risk($foreign, $this->area($foreign, 'HR'), 'Annen kunde');

        $attention = $this->attention($all);

        $this->assertSame('all', $attention['scope']);
        $this->assertSame(3, $attention['total']);
        $this->assertSame(['not_assessed' => [$a->id, $b->id, $later->id]], $this->idsByCategory($attention));
    }

    public function test_system_owner_without_an_area_gets_no_aggregates(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $this->risk($customer, $hr, 'Sensitiv');

        $props = $this->indexProps($owner);

        $this->assertFalse($props['has_areas']);
        $this->assertNull($props['attention']);
        $this->assertSame(0, $props['visible_count']);
        $this->assertStringNotContainsString('Sensitiv', json_encode($props));

        // Given an explicit area, System Owner sees that area only — and never «Alle» implicitly.
        $this->grant($customer, $owner, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);
        $this->risk($customer, $this->area($customer, 'Økonomi'), 'Skjult');

        $attention = $this->indexProps($owner)['attention'];
        $this->assertSame('areas', $attention['scope']);
        $this->assertSame(1, $attention['total']);
    }

    public function test_the_empty_picture_is_reported_as_such(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $viewer = $this->viewer($customer, [$hr]);

        $this->assertSame(['total' => 0, 'categories' => [], 'scope' => 'areas'], $this->attention($viewer));
    }

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function attention(User $user): array
    {
        return $this->indexProps($user)['attention'];
    }

    /** @return array<string, mixed> */
    private function indexProps(User $user): array
    {
        return $this->actingAs($user)->get('/app/risk')->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, list<int>> */
    private function idsByCategory(array $attention): array
    {
        return collect($attention['categories'])
            ->mapWithKeys(fn (array $category): array => [$category['key'] => array_column($category['risks'], 'id')])
            ->each(fn (array $ids, string $key) => $this->assertSame(count($ids), collect($attention['categories'])->firstWhere('key', $key)['count']))
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rowsByCategory(array $attention): array
    {
        return collect($attention['categories'])->mapWithKeys(fn (array $category): array => [$category['key'] => $category['risks']])->all();
    }

    /** @param  list<BusinessArea>  $areas */
    private function viewer(Customer $customer, array $areas): User
    {
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW], $areas);

        return $user;
    }

    /**
     * @param  array{0: int, 1: int}  $inherent
     * @param  array{0: int, 1: int}|null  $residual
     */
    private function assessment(Risk $risk, string $assessedAt, array $inherent = [3, 3], ?array $residual = null): RiskAssessment
    {
        return RiskAssessment::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'assessed_at' => $assessedAt,
            'rationale' => 'Vurdert',
            'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => $inherent[0],
            'inherent_consequence' => $inherent[1],
            'residual_likelihood' => $residual[0] ?? null,
            'residual_consequence' => $residual[1] ?? null,
        ]);
    }

    private function acceptance(RiskAssessment $assessment, ?string $validUntil, bool $revoked = false): RiskAcceptance
    {
        return RiskAcceptance::query()->create([
            'customer_id' => $assessment->customer_id,
            'risk_id' => $assessment->risk_id,
            'assessment_id' => $assessment->id,
            'rationale' => 'Akseptert',
            'accepted_at' => $assessment->assessed_at,
            'valid_until' => $validUntil,
            'revoked_at' => $revoked ? now() : null,
        ]);
    }

    private function action(Risk $risk, string $dueAt, string $status = RiskTreatmentAction::STATUS_OPEN): RiskTreatmentAction
    {
        return RiskTreatmentAction::query()->create([
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'title' => 'Tiltak',
            'due_at' => $dueAt,
            'status' => $status,
            'completed_at' => $status === RiskTreatmentAction::STATUS_COMPLETED ? now() : null,
        ]);
    }

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
            'cause' => 'manglende rutiner',
            'event' => 'en hendelse inntreffer',
            'consequence' => 'virksomheten rammes',
            'status' => $status,
            'review_interval_months' => $interval,
        ]);
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
