<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskAssessmentService;
use App\Services\Risk\RiskLifecycleService;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Risiko's human decisions — assessing a risk, accepting and revoking residual risk, deleting a
 * risk — are refused in the service itself, phase 0 step 9 (docs/external-api-phase-0-plan.md §4.4).
 *
 * An integration, any request through the API (delegated mode included) and a person without the
 * permission in the risk's fagområde are turned away there, whatever the entry point, and nothing
 * is written. The controllers keep their own checks first, so what a person sees is unchanged.
 */
class RiskHumanDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::RISK_VIEW,
        CustomerPermissionCatalog::RISK_CREATE,
        CustomerPermissionCatalog::RISK_EDIT,
        CustomerPermissionCatalog::RISK_ASSESS,
        CustomerPermissionCatalog::RISK_ACCEPT,
        CustomerPermissionCatalog::RISK_DELETE,
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
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /**
     * The (permission, area) pairs are read from the roles directly, so the rule that an integration
     * never holds a human-only key has to hold in RiskAccessService too — not only in
     * CustomerPermissionService::has().
     */
    public function test_an_integration_holds_no_human_only_risk_key_through_its_roles(): void
    {
        $case = $this->case();
        $integration = $case['integration'];
        $access = app(RiskAccessService::class);

        foreach ([CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT, CustomerPermissionCatalog::RISK_DELETE] as $key) {
            $this->assertSame([], $access->areaIdsFor($integration, $key), $key);
            $this->assertFalse($access->can($integration, $key, $case['risk']), $key);
            $this->assertFalse($access->reachesAllAreas($integration, $key), $key);
        }

        // Keys a machine may hold reach its fagområder as before.
        foreach ([CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_CREATE, CustomerPermissionCatalog::RISK_EDIT] as $key) {
            $this->assertTrue($access->can($integration, $key, $case['risk']), $key);
            $this->assertTrue($access->reachesAllAreas($integration, $key), $key);
        }

        // The same role makes a person a full decider.
        $this->assertTrue($access->can($case['decider'], CustomerPermissionCatalog::RISK_ACCEPT, $case['risk']));

        // An integration does not reach the customer frontend at all.
        $this->actingAs($integration)->get("/app/risk/risks/{$case['risk']->id}")->assertForbidden();
    }

    /** A person with the keys in the risk's area assesses, accepts and revokes — through the services, as before. */
    public function test_a_person_with_the_keys_in_the_area_takes_every_decision(): void
    {
        $case = $this->case();

        $assessment = app(RiskAssessmentService::class)->record($case['decider'], $case['risk'], $this->scores(), '  Vurdert etter øvelse.  ');
        $this->assertSame((int) $case['decider']->id, (int) $assessment->assessed_by);
        $this->assertSame('Vurdert etter øvelse.', $assessment->rationale);
        $this->assertSame([3, 2], [$assessment->residual_likelihood, $assessment->residual_consequence]);
        $this->assertSame('Strømmen går', $assessment->risk_event, 'what was assessed is the risk as it read then');

        $acceptance = app(RiskAcceptanceService::class)->accept($case['decider'], $case['risk'], (int) $assessment->id, 'Akseptabelt.', null);
        $this->assertSame((int) $case['decider']->id, (int) $acceptance->accepted_by_user_id);

        app(RiskAcceptanceService::class)->revoke($case['decider'], $acceptance);
        $this->assertSame((int) $case['decider']->id, (int) $acceptance->fresh()->revoked_by_user_id);
        $this->assertNotNull($acceptance->revoked_at);
    }

    public function test_no_risk_decision_is_taken_by_an_integration(): void
    {
        $case = $this->case();

        $this->assertEveryDecisionRefused($case, $case['integration'], HumanDecisionRefused::class);
    }

    /** Delegated mode: a person's own account, but the request comes through the API. */
    public function test_no_risk_decision_is_taken_through_the_api_even_on_behalf_of_a_person(): void
    {
        $case = $this->case();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertEveryDecisionRefused($case, $case['decider'], HumanDecisionRefused::class);
    }

    public function test_an_inactive_person_takes_no_risk_decision(): void
    {
        $case = $this->case();
        $case['decider']->forceFill(['is_active' => false])->save();

        $this->assertEveryDecisionRefused($case, $case['decider']->fresh(), HumanDecisionRefused::class);
    }

    /**
     * The service refuses on its own — the controller's check is not what protects the decision.
     * Each person lacks exactly one thing the decision needs.
     */
    public function test_a_person_without_the_key_in_the_risks_area_is_refused_by_the_service(): void
    {
        $case = $this->case();
        $elsewhere = BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR']);
        $decisions = [CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT, CustomerPermissionCatalog::RISK_DELETE];

        $people = [
            'reads and edits, decides nothing' => $this->person($case['customer'], [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$case['area']]),
            'decides, but in another fagområde' => $this->person($case['customer'], self::EVERYTHING, [$elsewhere]),
            'holds the keys, but cannot see the risk' => $this->person($case['customer'], $decisions, [$case['area']]),
            'decision key and view from two different roles' => $this->twoRoles($case['customer'], $case['area']),
            'System Owner without a role' => $this->member($case['customer'], User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
        ];

        foreach ($people as $name => $person) {
            $this->assertEveryDecisionRefused($case, $person, AuthorizationException::class, $name);
        }
    }

    /** Customer separation: a decider in their own customer is nobody in another's. */
    public function test_a_customer_cannot_read_or_decide_on_another_customers_risk(): void
    {
        $case = $this->case();
        $other = $this->customer('Annen Kunde AS');
        $otherArea = BusinessArea::query()->create(['customer_id' => $other->id, 'name' => 'Drift']);
        $outsider = $this->person($other, self::EVERYTHING, [$otherArea]);
        $everywhere = $this->person($other, self::EVERYTHING, [], allAreas: true);

        foreach (['explicit area' => $outsider, '«Alle»' => $everywhere] as $name => $person) {
            $this->assertNull(app(RiskAccessService::class)->findVisible($person, (int) $case['risk']->id), $name);
            $this->assertEveryDecisionRefused($case, $person, AuthorizationException::class, $name);

            $base = "/app/risk/risks/{$case['risk']->id}";
            $this->actingAs($person)->get($base)->assertNotFound();
            $this->actingAs($person)->patch($base, ['title' => 'Overtatt'])->assertNotFound();
            $this->actingAs($person)->post("{$base}/assessments", $this->scores() + ['rationale' => 'Fremmed'])->assertNotFound();
            $this->actingAs($person)->post("{$base}/acceptances", ['assessment_id' => $case['assessment']->id, 'rationale' => 'Fremmed'])->assertNotFound();
            $this->actingAs($person)->post("{$base}/acceptances/{$case['acceptance']->id}/revoke")->assertNotFound();
            $this->actingAs($person)->delete($base)->assertNotFound();
        }

        $this->assertSame('Strømbrudd', $case['risk']->fresh()->title);
        $this->assertUntouched($case);
    }

    /** The service judges the risk as it stands now, not the copy the caller loaded a moment ago. */
    public function test_a_risk_moved_out_of_the_deciders_area_is_judged_where_it_is_now(): void
    {
        $case = $this->case(allAreas: false);
        $stale = Risk::query()->findOrFail($case['risk']->id);
        $elsewhere = BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR']);
        DB::table('risks')->where('id', $case['risk']->id)->update(['business_area_id' => $elsewhere->id]);

        $this->assertEveryDecisionRefused(['risk' => $stale] + $case, $case['decider'], AuthorizationException::class);
    }

    /**
     * Every human decision in Risiko, called directly on its service with this actor: each is
     * refused with $refusal, and the risk's assessments and acceptances are exactly as they were.
     *
     * @param  array<string, mixed>  $case
     * @param  class-string<\Throwable>  $refusal
     */
    private function assertEveryDecisionRefused(array $case, User $actor, string $refusal, string $who = ''): void
    {
        foreach ([
            'assess' => fn () => app(RiskAssessmentService::class)->record($actor, $case['risk'], $this->scores(), 'Uten rett.'),
            'accept' => fn () => app(RiskAcceptanceService::class)->accept($actor, $case['risk'], (int) $case['assessment']->id, 'Uten rett.', null),
            'revoke' => fn () => app(RiskAcceptanceService::class)->revoke($actor, $case['acceptance']),
            'delete' => fn () => app(RiskLifecycleService::class)->delete($actor, $case['risk']),
        ] as $decision => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e->getMessage());
            }
        }

        $this->assertUntouched($case, $who);
    }

    /** @param array<string, mixed> $case */
    private function assertUntouched(array $case, string $who = ''): void
    {
        $this->assertSame(1, RiskAssessment::query()->where('risk_id', $case['risk']->id)->count(), $who);
        $this->assertSame(1, RiskAcceptance::query()->where('risk_id', $case['risk']->id)->count(), $who);
        $this->assertNull($case['acceptance']->fresh()->revoked_at, $who);
        $this->assertTrue(Risk::query()->whereKey($case['risk']->id)->exists(), $who);
    }

    /** @return array{inherent_likelihood: int, inherent_consequence: int, residual_likelihood: int, residual_consequence: int} */
    private function scores(): array
    {
        return ['inherent_likelihood' => 4, 'inherent_consequence' => 4, 'residual_likelihood' => 3, 'residual_consequence' => 2];
    }

    /**
     * @param  list<string>  $keys
     * @param  list<BusinessArea>  $areas
     */
    private function person(Customer $customer, array $keys, array $areas, bool $allAreas = false): User
    {
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $role->syncPermissions($keys);
        $role->syncBusinessAreas($allAreas, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));

        $person = $this->member($customer);
        $person->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $person;
    }

    /** risk.view in the area from one role, the decision keys in the same area from another — never added up. */
    private function twoRoles(Customer $customer, BusinessArea $area): User
    {
        $person = $this->person($customer, [CustomerPermissionCatalog::RISK_VIEW], [$area]);
        $elsewhere = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Økonomi '.Str::random(4)]);

        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $role->syncPermissions([CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_ASSESS, CustomerPermissionCatalog::RISK_ACCEPT, CustomerPermissionCatalog::RISK_DELETE]);
        $role->syncBusinessAreas(false, [(int) $elsewhere->id]);
        $person->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $person;
    }

    /** @return array{customer: Customer, area: BusinessArea, risk: Risk, decider: User, integration: User, role: CustomerRole} */
    private function case(bool $allAreas = true): array
    {
        $customer = $this->customer('Risikobeslutning AS');
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);
        $risk = $this->risk($customer, $area, 'Strømbrudd');

        // By default «Alle» in one role holding every risk key: the strongest grant a role can give.
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Alt '.Str::upper(Str::random(6)), 'is_active' => true]);
        $role->syncPermissions(self::EVERYTHING);
        $role->syncBusinessAreas($allAreas, $allAreas ? [] : [(int) $area->id]);

        $decider = $this->member($customer);
        $decider->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $integration = $this->member($customer, User::ROLE_INTEGRATION);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        // History to act on: an assessment with residual risk, accepted.
        $assessment = RiskAssessment::query()->create([
            'customer_id' => $customer->id, 'risk_id' => $risk->id, 'assessed_by' => $decider->id, 'assessed_at' => now()->subDay(),
            'rationale' => 'Første vurdering', 'criteria_key' => 'standard_5x5_v1',
            'inherent_likelihood' => 4, 'inherent_consequence' => 4, 'residual_likelihood' => 3, 'residual_consequence' => 2,
        ]);
        $acceptance = RiskAcceptance::query()->create([
            'customer_id' => $customer->id, 'risk_id' => $risk->id, 'assessment_id' => $assessment->id,
            'accepted_by_user_id' => $decider->id, 'rationale' => 'Akseptert', 'accepted_at' => now()->subDay(),
        ]);

        return compact('customer', 'area', 'risk', 'decider', 'integration', 'role', 'assessment', 'acceptance');
    }

    private function customer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => 'risiko-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        return $customer;
    }

    private function risk(Customer $customer, BusinessArea $area, string $title): Risk
    {
        return Risk::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'cause' => 'Uvær',
            'event' => 'Strømmen går',
            'consequence' => 'Driften stopper',
            'status' => Risk::STATUS_IDENTIFIED,
        ]);
    }

    private function member(Customer $customer, string $role = User::ROLE_USER, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'risikobeslutning-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
