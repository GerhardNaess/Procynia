<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\KpiStatusChange;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiRegistration;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Services\Objectives\ObjectiveRegistration;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Lukk mål (Nådd, Ikke nådd, Avbrutt), Gjenåpne, Avslutt KPI and Gjenåpne KPI are a person's decision
 * (A39, docs/external-api-phase-0-plan.md §2k): the organisation's conclusion on goal attainment, and
 * its decision to stop measuring. They ride on objective.edit, as before — the key is unchanged and so
 * is who may do it — but HumanDecisionGuard refuses an integration holding the key and any request
 * through the API, delegated mode included. Register work that could change a status by the side
 * door does not.
 */
class ObjectiveLifecycleDecisionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EDITOR = [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    /** Nothing changes for a person with objective.edit: every decision, with their name on the history. */
    public function test_a_person_with_objective_edit_takes_every_lifecycle_decision(): void
    {
        $case = $this->case();
        $editor = $case['editor'];

        // The KPIs first: a closed objective's KPIs are left as they were.
        app(KpiLifecycleService::class)->retire($case['kpi'], $editor, 'Måles ikke lenger');
        app(KpiLifecycleService::class)->reopen($case['retired'], $editor, 'Måles igjen');
        app(ObjectiveLifecycleService::class)->close($case['open'], $editor, Objective::STATUS_ACHIEVED, 'Nådd i september');
        app(ObjectiveLifecycleService::class)->reopen($case['closed'], $editor, 'Lukket ved en feil');

        $this->assertSame(Objective::STATUS_ACHIEVED, $case['open']->fresh()->status);
        $this->assertSame(Objective::STATUS_ACTIVE, $case['closed']->fresh()->status);
        $this->assertSame(Kpi::STATUS_RETIRED, $case['kpi']->fresh()->status);
        $this->assertSame(Kpi::STATUS_ACTIVE, $case['retired']->fresh()->status);

        foreach ([$case['open'], $case['closed']] as $objective) {
            $this->assertSame((int) $editor->id, (int) ObjectiveStatusChange::query()->where('objective_id', $objective->id)->latest('id')->value('changed_by_user_id'));
        }

        foreach ([$case['kpi'], $case['retired']] as $kpi) {
            $this->assertSame((int) $editor->id, (int) KpiStatusChange::query()->where('kpi_id', $kpi->id)->latest('id')->value('changed_by_user_id'));
        }

        // And through the pages, unchanged.
        $base = "/app/objectives/{$case['spare']->id}";
        $this->actingAs($editor)->post("{$base}/close", ['status' => Objective::STATUS_CANCELLED])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->actingAs($editor)->post("{$base}/reopen", ['reason' => 'Tilbake'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(Objective::STATUS_ACTIVE, $case['spare']->fresh()->status);
    }

    /** The key is not enough for a machine: an integration holding objective.edit is refused all four. */
    public function test_an_integration_with_objective_edit_takes_no_lifecycle_decision(): void
    {
        $case = $this->case();

        $this->assertEveryDecisionRefused($case, $case['integration'], HumanDecisionRefused::class);
    }

    /** Delegated mode: a person's own account, but the request comes through the API. */
    public function test_no_lifecycle_decision_is_taken_through_the_api_even_on_behalf_of_a_person(): void
    {
        $case = $this->case();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertEveryDecisionRefused($case, $case['editor'], HumanDecisionRefused::class);
    }

    public function test_an_inactive_person_takes_no_lifecycle_decision(): void
    {
        $case = $this->case();
        $case['editor']->forceFill(['is_active' => false])->save();

        $this->assertEveryDecisionRefused($case, $case['editor']->fresh(), HumanDecisionRefused::class);
    }

    /** Past the guard, the rule is objective.edit as before — in the objective's area, customer and module. */
    public function test_a_person_without_objective_edit_in_the_area_is_refused(): void
    {
        $case = $this->case();
        $customer = $case['customer'];
        $elsewhere = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'HR']);
        $other = $this->customer('Annen Kunde AS');
        $otherArea = BusinessArea::query()->create(['customer_id' => $other->id, 'name' => 'Drift']);

        $people = [
            'reader' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$case['area']]),
            'measurer' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$case['area']]),
            'deleter' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$case['area']]),
            'edit without view' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_EDIT], [$case['area']]),
            'editor in another fagområde' => $this->person($customer, self::EDITOR, [$elsewhere]),
            'System Owner without a role' => $this->member($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
            'another customer\'s editor' => $this->person($other, self::EDITOR, [$otherArea], allAreas: true),
            'another customer\'s System Owner' => $this->member($other, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
        ];

        foreach ($people as $who => $person) {
            $this->assertEveryDecisionRefused($case, $person, AuthorizationException::class, $who);
        }
    }

    public function test_a_customer_without_the_module_takes_no_lifecycle_decision(): void
    {
        $case = $this->case();
        $case['customer']->packageEntitlements()->where('package_key', 'objectives')->delete();

        $this->assertEveryDecisionRefused($case, $case['editor']->fresh(), AuthorizationException::class);
    }

    /**
     * No side door: register work never writes a status, whoever sends one, and neither model takes
     * a status by mass assignment. A new objective or KPI starts active — creating is not a decision.
     */
    public function test_register_work_never_changes_a_status(): void
    {
        $case = $this->case();
        $integration = $case['integration'];

        app(ObjectiveRegistration::class)->update($integration, $case['open'], [
            'title' => 'Endret', 'description' => null, 'business_area_id' => (int) $case['area']->id,
            'owner_user_id' => (int) $case['editor']->id, 'target_date' => null, 'status' => Objective::STATUS_ACHIEVED,
        ]);
        app(KpiRegistration::class)->update($integration, $case['kpi'], $this->kpiFields('Endret') + ['status' => Kpi::STATUS_RETIRED]);
        $created = app(KpiRegistration::class)->create($integration, $case['open'], $this->kpiFields('Ny') + ['status' => Kpi::STATUS_RETIRED]);

        $this->assertSame([Objective::STATUS_ACTIVE, 'Endret'], [$case['open']->fresh()->status, $case['open']->fresh()->title]);
        $this->assertSame([Kpi::STATUS_ACTIVE, 'Endret'], [$case['kpi']->fresh()->status, $case['kpi']->fresh()->title]);
        $this->assertSame(Kpi::STATUS_ACTIVE, $created->fresh()->status);
        $this->assertSame(0, ObjectiveStatusChange::query()->where('objective_id', $case['open']->id)->count());

        $this->assertNotContains('status', (new Objective)->getFillable());
        $this->assertNotContains('status', (new Kpi)->getFillable());
        $this->assertSame(Objective::STATUS_ACTIVE, (new Objective)->fill(['status' => Objective::STATUS_ACHIEVED])->status);
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  class-string<\Throwable>  $refusal
     */
    private function assertEveryDecisionRefused(array $case, User $actor, string $refusal, string $who = ''): void
    {
        foreach ([
            'close objective' => fn () => app(ObjectiveLifecycleService::class)->close($case['open'], $actor, Objective::STATUS_ACHIEVED, null),
            'reopen objective' => fn () => app(ObjectiveLifecycleService::class)->reopen($case['closed'], $actor, 'Uten rett'),
            'retire kpi' => fn () => app(KpiLifecycleService::class)->retire($case['kpi'], $actor, null),
            'reopen kpi' => fn () => app(KpiLifecycleService::class)->reopen($case['retired'], $actor, 'Uten rett'),
        ] as $decision => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e->getMessage());
            }
        }

        $this->assertSame(Objective::STATUS_ACTIVE, $case['open']->fresh()->status, $who);
        $this->assertSame(Objective::STATUS_ACHIEVED, $case['closed']->fresh()->status, $who);
        $this->assertSame(Kpi::STATUS_ACTIVE, $case['kpi']->fresh()->status, $who);
        $this->assertSame(Kpi::STATUS_RETIRED, $case['retired']->fresh()->status, $who);
        $this->assertSame(1, ObjectiveStatusChange::query()->where('customer_id', $case['customer']->id)->count(), $who);
        $this->assertSame(1, KpiStatusChange::query()->where('customer_id', $case['customer']->id)->count(), $who);
    }

    /**
     * «Stabil drift» (active, KPIs «Oppetid» active and «Utgått» retired), «Stengt» (closed as Nådd)
     * and «Reserve» (active); an editor and an integration holding the same role.
     *
     * @return array<string, mixed>
     */
    private function case(): array
    {
        $customer = $this->customer('Målbeslutning AS');
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);

        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Redaktør '.Str::upper(Str::random(6)), 'is_active' => true]);
        $role->syncPermissions(self::EDITOR);
        $role->syncBusinessAreas(false, [(int) $area->id]);

        $editor = $this->member($customer);
        $editor->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);
        $integration = $this->member($customer, User::ROLE_INTEGRATION);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $open = $this->objective($customer, $area, $editor, 'Stabil drift');
        $closed = $this->objective($customer, $area, $editor, 'Stengt');
        $spare = $this->objective($customer, $area, $editor, 'Reserve');
        $kpi = Kpi::query()->create(['customer_id' => $customer->id, 'objective_id' => $open->id] + $this->kpiFields('Oppetid'));
        $retired = Kpi::query()->create(['customer_id' => $customer->id, 'objective_id' => $open->id] + $this->kpiFields('Utgått'));

        app(ObjectiveLifecycleService::class)->close($closed, $editor, Objective::STATUS_ACHIEVED, null);
        app(KpiLifecycleService::class)->retire($retired, $editor, null);

        return [
            'customer' => $customer, 'area' => $area, 'editor' => $editor, 'integration' => $integration,
            'open' => $open->fresh(), 'closed' => $closed->fresh(), 'spare' => $spare->fresh(),
            'kpi' => $kpi->fresh(), 'retired' => $retired->fresh(),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiFields(string $title): array
    {
        return [
            'title' => $title, 'description' => null, 'owner_user_id' => null,
            'unit' => Kpi::UNIT_PERCENT, 'unit_label' => null, 'currency_code' => null,
            'target_min' => '98', 'target_max' => null, 'tolerance' => null,
            'baseline_value' => null, 'baseline_date' => null,
            'frequency' => Kpi::FREQUENCY_MONTHLY, 'reporting_grace_days' => 7,
        ];
    }

    private function objective(Customer $customer, BusinessArea $area, User $owner, string $title): Objective
    {
        return Objective::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'owner_user_id' => $owner->id,
        ]);
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

    private function customer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => 'maallivslop-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        return $customer;
    }

    private function member(Customer $customer, string $role = User::ROLE_USER, string $bidRole = User::BID_ROLE_CONTRIBUTOR): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'maallivslop-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
