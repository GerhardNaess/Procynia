<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiMeasurementService;
use App\Services\Objectives\KpiPeriod;
use App\Services\Objectives\KpiPeriods;
use App\Services\Objectives\ObjectiveAccessService;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Mål og KPI's human decisions — registering and withdrawing a measurement, deleting an objective or
 * a KPI — are refused in the service itself, phase 0 step 15 (docs/external-api-phase-0-plan.md §2k).
 *
 * An integration, any request through the API (delegated mode included) and a person without the
 * permission in the objective's fagområde are turned away there, whatever the entry point, and
 * nothing is written. The controllers keep their own checks first, so what a person sees is unchanged.
 */
class ObjectiveHumanDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::OBJECTIVE_VIEW,
        CustomerPermissionCatalog::OBJECTIVE_EDIT,
        CustomerPermissionCatalog::OBJECTIVE_MEASURE,
        CustomerPermissionCatalog::OBJECTIVE_DELETE,
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
     * never holds a human-only key has to hold in ObjectiveAccessService too (A6, as in Risiko).
     */
    public function test_an_integration_holds_no_human_only_objective_key_through_its_roles(): void
    {
        $case = $this->case();
        $integration = $case['integration'];
        $access = app(ObjectiveAccessService::class);

        foreach ([CustomerPermissionCatalog::OBJECTIVE_MEASURE, CustomerPermissionCatalog::OBJECTIVE_DELETE] as $key) {
            $this->assertSame([], $access->areaIdsFor($integration, $key), $key);
            $this->assertFalse($access->can($integration, $key, $case['objective']), $key);
            $this->assertFalse($access->reachesAllAreas($integration, $key), $key);
        }

        $this->assertFalse($access->canMeasure($integration, $case['objective']));
        $this->assertFalse($access->canDelete($integration, $case['objective']));

        // Keys a machine may hold reach its fagområder as before.
        foreach ([CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT] as $key) {
            $this->assertTrue($access->can($integration, $key, $case['objective']), $key);
            $this->assertTrue($access->reachesAllAreas($integration, $key), $key);
        }

        // The same role makes a person a full decider.
        $this->assertTrue($access->canMeasure($case['decider'], $case['objective']));
        $this->assertTrue($access->canDelete($case['decider'], $case['objective']));

        // An integration does not reach the customer frontend at all.
        $this->actingAs($integration)->get("/app/objectives/{$case['objective']->id}")->assertForbidden();
    }

    /** A person with the keys in the objective's area takes every decision, through the services. */
    public function test_a_person_with_the_keys_in_the_area_takes_every_decision(): void
    {
        $case = $this->case();
        $measurements = app(KpiMeasurementService::class);

        $recorded = $measurements->record($case['kpi'], $case['decider'], $this->period(), '97.5', 'Rettet etter revisjon');
        $this->assertSame((int) $case['decider']->id, (int) $recorded->recorded_by_user_id);
        $this->assertSame('98.0000', $recorded->target_min, 'the målverdi is copied from the KPI');

        $withdrawn = $measurements->withdraw($recorded, $case['decider'], 'Feil kilde');
        $this->assertSame((int) $case['decider']->id, (int) $withdrawn->withdrawn_by_user_id);

        app(KpiLifecycleService::class)->delete($case['decider'], $case['empty']);
        $this->assertFalse(Kpi::query()->whereKey($case['empty']->id)->exists());

        app(ObjectiveLifecycleService::class)->delete($case['decider'], $case['spare']);
        $this->assertFalse(Objective::query()->whereKey($case['spare']->id)->exists());
    }

    /** What carries history is closed or retired, never deleted — the service says so on its own. */
    public function test_an_objective_or_kpi_with_a_measurement_is_not_deleted_by_the_service(): void
    {
        $case = $this->case();

        foreach ([
            'objective' => fn () => app(ObjectiveLifecycleService::class)->delete($case['decider'], $case['objective']),
            'kpi' => fn () => app(KpiLifecycleService::class)->delete($case['decider'], $case['kpi']),
        ] as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} with a measurement must not be deleted");
            } catch (ConflictHttpException) {
                // Refused by its state, not by the person's rights.
            }
        }

        $this->assertTrue(Objective::query()->whereKey($case['objective']->id)->exists());
        $this->assertTrue(Kpi::query()->whereKey($case['kpi']->id)->exists());
        $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $case['kpi']->id)->count());
    }

    public function test_no_objective_decision_is_taken_by_an_integration(): void
    {
        $case = $this->case();

        $this->assertEveryDecisionRefused($case, $case['integration'], HumanDecisionRefused::class);
    }

    /** Delegated mode: a person's own account, but the request comes through the API. */
    public function test_no_objective_decision_is_taken_through_the_api_even_on_behalf_of_a_person(): void
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

    public function test_an_inactive_person_takes_no_objective_decision(): void
    {
        $case = $this->case();
        $case['decider']->forceFill(['is_active' => false])->save();

        $this->assertEveryDecisionRefused($case, $case['decider']->fresh(), HumanDecisionRefused::class);
    }

    /**
     * The service refuses on its own — the controller's check is not what protects the decision.
     * Each person lacks exactly one thing the decision needs.
     */
    public function test_a_person_without_the_key_in_the_objectives_area_is_refused_by_the_service(): void
    {
        $case = $this->case();
        $elsewhere = BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR']);

        $people = [
            'reads and edits, decides nothing' => $this->person($case['customer'], [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$case['area']]),
            'decides, but in another fagområde' => $this->person($case['customer'], self::EVERYTHING, [$elsewhere]),
            'holds the keys, but cannot see the objective' => $this->person($case['customer'], [CustomerPermissionCatalog::OBJECTIVE_MEASURE, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$case['area']]),
            'decision keys and view from two different roles' => $this->twoRoles($case['customer'], $case['area']),
            'System Owner without a role' => $this->member($case['customer'], User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
        ];

        foreach ($people as $name => $person) {
            $this->assertEveryDecisionRefused($case, $person, AuthorizationException::class, $name);
        }
    }

    /** measure and delete are separate keys: each one alone takes only its own decisions. */
    public function test_each_decision_key_takes_only_its_own_decisions(): void
    {
        $case = $this->case();
        $measurer = $this->person($case['customer'], [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$case['area']]);
        $deleter = $this->person($case['customer'], [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$case['area']]);

        $this->assertRefused(fn () => app(KpiLifecycleService::class)->delete($measurer, $case['empty']), AuthorizationException::class, 'measurer deletes a KPI');
        $this->assertRefused(fn () => app(ObjectiveLifecycleService::class)->delete($measurer, $case['spare']), AuthorizationException::class, 'measurer deletes an objective');
        $this->assertRefused(fn () => app(KpiMeasurementService::class)->record($case['kpi'], $deleter, $this->period(), '99', 'Rettet'), AuthorizationException::class, 'deleter measures');
        $this->assertRefused(fn () => app(KpiMeasurementService::class)->withdraw($case['measurement'], $deleter, 'Feil'), AuthorizationException::class, 'deleter withdraws');
        $this->assertUntouched($case);

        app(KpiMeasurementService::class)->record($case['kpi'], $measurer, $this->period(), '99', 'Rettet');
        app(KpiLifecycleService::class)->delete($deleter, $case['empty']);
        $this->assertSame(2, KpiMeasurement::query()->where('kpi_id', $case['kpi']->id)->count());
        $this->assertFalse(Kpi::query()->whereKey($case['empty']->id)->exists());
    }

    /** Customer separation: a decider in their own customer is nobody in another's. */
    public function test_a_customer_cannot_read_or_decide_on_another_customers_objective(): void
    {
        $case = $this->case();
        $other = $this->customer('Annen Kunde AS');
        $otherArea = BusinessArea::query()->create(['customer_id' => $other->id, 'name' => 'Drift']);
        $outsider = $this->person($other, self::EVERYTHING, [$otherArea]);
        $everywhere = $this->person($other, self::EVERYTHING, [], allAreas: true);
        $otherOwner = $this->member($other, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER);

        foreach (['explicit area' => $outsider, '«Alle»' => $everywhere, 'System Owner' => $otherOwner] as $name => $person) {
            $this->assertNull(app(ObjectiveAccessService::class)->findVisible($person, (int) $case['objective']->id), $name);
            $this->assertEveryDecisionRefused($case, $person, AuthorizationException::class, $name);

            $base = "/app/objectives/{$case['objective']->id}";
            $this->actingAs($person)->get($base)->assertNotFound();
            $this->actingAs($person)->post("{$base}/kpis/{$case['kpi']->id}/measurements", ['period' => '2026-02', 'value' => '50'])->assertNotFound();
            $this->actingAs($person)->post("{$base}/kpis/{$case['kpi']->id}/measurements/{$case['measurement']->id}/withdraw", ['reason' => 'Fremmed'])->assertNotFound();
            $this->actingAs($person)->delete("{$base}/kpis/{$case['empty']->id}")->assertNotFound();
            $this->actingAs($person)->delete("/app/objectives/{$case['spare']->id}")->assertNotFound();
        }

        $this->assertUntouched($case);
    }

    /** A customer without Mål og KPI takes no decision in it, even with a role holding every key. */
    public function test_a_customer_without_the_module_takes_no_objective_decision(): void
    {
        $case = $this->case();
        $case['customer']->packageEntitlements()->delete();

        $this->assertFalse(app(ObjectiveAccessService::class)->canMeasure($case['decider'], $case['objective']));
        $this->assertEveryDecisionRefused($case, $case['decider']->fresh(), AuthorizationException::class);
    }

    /** The service judges the objective as it stands now, not the copy the caller loaded a moment ago. */
    public function test_an_objective_moved_out_of_the_deciders_area_is_judged_where_it_is_now(): void
    {
        $case = $this->case(allAreas: false);
        $stale = Objective::query()->findOrFail($case['spare']->id);
        // As a controller holds it: the KPI with its objective loaded, before the move.
        $staleKpi = Kpi::query()->with('objective')->findOrFail($case['kpi']->id);
        $elsewhere = BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR']);
        DB::table('objectives')->whereIn('id', [$case['objective']->id, $case['spare']->id])->update(['business_area_id' => $elsewhere->id]);

        $this->assertEveryDecisionRefused(['spare' => $stale, 'kpi' => $staleKpi] + $case, $case['decider'], AuthorizationException::class);
    }

    /**
     * Every human decision in Mål og KPI, called directly on its service with this actor: each is
     * refused with $refusal, and nothing is written.
     *
     * @param  array<string, mixed>  $case
     * @param  class-string<\Throwable>  $refusal
     */
    private function assertEveryDecisionRefused(array $case, User $actor, string $refusal, string $who = ''): void
    {
        foreach ([
            'record' => fn () => app(KpiMeasurementService::class)->record($case['kpi'], $actor, $this->period(), '99', 'Uten rett'),
            'withdraw' => fn () => app(KpiMeasurementService::class)->withdraw($case['measurement'], $actor, 'Uten rett'),
            'delete kpi' => fn () => app(KpiLifecycleService::class)->delete($actor, $case['empty']),
            'delete objective' => fn () => app(ObjectiveLifecycleService::class)->delete($actor, $case['spare']),
        ] as $decision => $attempt) {
            $this->assertRefused($attempt, $refusal, "{$who}: {$decision}");
        }

        $this->assertUntouched($case, $who);
    }

    /** @param class-string<\Throwable> $refusal */
    private function assertRefused(callable $attempt, string $refusal, string $what): void
    {
        try {
            $attempt();
            $this->fail("{$what} must be refused");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($refusal, $e, "{$what} — ".$e->getMessage());
        }
    }

    /** @param array<string, mixed> $case */
    private function assertUntouched(array $case, string $who = ''): void
    {
        $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $case['kpi']->id)->count(), $who);
        $this->assertNull($case['measurement']->fresh()->withdrawn_at, $who);
        $this->assertTrue(Kpi::query()->whereKey($case['empty']->id)->exists(), $who);
        $this->assertTrue(Objective::query()->whereKey($case['spare']->id)->exists(), $who);
    }

    /** January 2026: ended, and the period the fixture's measurement is for — so a second one is a correction. */
    private function period(): KpiPeriod
    {
        return app(KpiPeriods::class)->fromKey(Kpi::FREQUENCY_MONTHLY, '2026-01');
    }

    /** objective.view in the area from one role, the decision keys in the same area from another — never added up. */
    private function twoRoles(Customer $customer, BusinessArea $area): User
    {
        $person = $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$area]);
        $elsewhere = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Økonomi '.Str::random(4)]);

        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $role->syncPermissions(self::EVERYTHING);
        $role->syncBusinessAreas(false, [(int) $elsewhere->id]);
        $person->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $person;
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

    /**
     * One objective with two KPIs: «Oppetid» with a measurement (history), «Tom» without; and a
     * second objective, «Reserve», registered by mistake and still deletable.
     *
     * @return array{customer: Customer, area: BusinessArea, objective: Objective, spare: Objective, kpi: Kpi, empty: Kpi, measurement: KpiMeasurement, decider: User, integration: User, role: CustomerRole}
     */
    private function case(bool $allAreas = true): array
    {
        $customer = $this->customer('Målbeslutning AS');
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);

        // By default «Alle» in one role holding every objective key: the strongest grant a role can give.
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Alt '.Str::upper(Str::random(6)), 'is_active' => true]);
        $role->syncPermissions(self::EVERYTHING);
        $role->syncBusinessAreas($allAreas, $allAreas ? [] : [(int) $area->id]);

        $decider = $this->member($customer);
        $decider->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $integration = $this->member($customer, User::ROLE_INTEGRATION);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $objective = Objective::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => 'Stabil drift',
            'owner_user_id' => $decider->id,
        ]);
        $spare = Objective::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => 'Reserve',
            'owner_user_id' => $decider->id,
        ]);
        $kpi = $this->kpi($objective, 'Oppetid');
        $empty = $this->kpi($objective, 'Tom');

        $measurement = KpiMeasurement::query()->create([
            'customer_id' => $customer->id,
            'kpi_id' => $kpi->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'value' => '99',
            'target_min' => '98',
            'recorded_by_user_id' => $decider->id,
            'recorded_at' => now()->subDay(),
        ]);

        return compact('customer', 'area', 'objective', 'spare', 'kpi', 'empty', 'measurement', 'decider', 'integration', 'role');
    }

    private function kpi(Objective $objective, string $title): Kpi
    {
        return Kpi::query()->create([
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => $title,
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '98',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ]);
    }

    private function customer(string $name, bool $module = true): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => 'maal-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($module) {
            app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');
        }

        return $customer;
    }

    private function member(Customer $customer, string $role = User::ROLE_USER, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'maalbeslutning-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
