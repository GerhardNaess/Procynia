<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\EnterpriseWikiDocument;
use App\Models\Kpi;
use App\Models\KpiActivity;
use App\Models\KpiProcess;
use App\Models\KpiStatusChange;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeHandoffService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiQualityContextService;
use App\Services\Objectives\KpiRegistration;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Services\Objectives\ObjectiveRegistration;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\QualityFlowVersion;
use Tests\TestCase;

/**
 * The register work in Mål og KPI — Nytt mål, Rediger, Lukk mål, Gjenåpne, Ny KPI, Avslutt KPI,
 * Endre kobling to Kvalitet, Overfør til Wiki — is checked by the services themselves, phase 0 step
 * 15 (docs/external-api-phase-0-plan.md §2k): objective.edit in the objective's fagområde, the actor's
 * own customer and the `objectives` module, whatever the entry point.
 *
 * It is ordinary registration, not a human decision: an integration holding objective.edit does it.
 * Closing, retiring and reopening are human decisions on the same key (A39), checked here too for
 * people; ObjectiveLifecycleDecisionTest refuses them to integrations and the API.
 */
class ObjectiveServiceAuthorizationTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const EDITOR = [
        CustomerPermissionCatalog::OBJECTIVE_VIEW,
        CustomerPermissionCatalog::OBJECTIVE_EDIT,
        CustomerPermissionCatalog::QUALITY_VIEW,
        CustomerPermissionCatalog::WIKI_VIEW,
        CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        Queue::fake();
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

    public function test_an_editor_does_the_register_work_through_the_services(): void
    {
        $case = $this->case();

        foreach ($this->work($case, $case['editor']) as $what => $write) {
            $write();
        }

        $this->assertWritten($case, $case['editor']);
        $this->assertSame(1, EnterpriseWikiDocument::query()->where('customer_id', $case['customer']->id)->count());
    }

    /**
     * Register work is not a human decision: an integration holding objective.edit does it too.
     * Closing, retiring and reopening are (A39) — ObjectiveLifecycleDecisionTest.
     */
    public function test_an_integration_with_objective_edit_does_the_register_work(): void
    {
        $case = $this->case();

        foreach ($this->work($case, $case['integration'], handOff: false, lifecycle: false) as $write) {
            $write();
        }

        $this->assertWritten($case, $case['integration'], lifecycle: false);
    }

    /** Each lacks exactly what the work needs; nothing is written. */
    public function test_the_services_refuse_anyone_without_objective_edit_in_the_objectives_area(): void
    {
        $case = $this->case();
        $customer = $case['customer'];
        $elsewhere = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'HR']);
        $other = $this->customer('Annen Kunde AS');
        $otherArea = BusinessArea::query()->create(['customer_id' => $other->id, 'name' => 'Drift']);

        $people = [
            'reader' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF], [$case['area']]),
            'measurer' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE, CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF], [$case['area']]),
            'deleter' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_DELETE, CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF], [$case['area']]),
            'edit without view' => $this->person($customer, [CustomerPermissionCatalog::OBJECTIVE_EDIT, CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::WIKI_KNOWLEDGE_HANDOFF], [$case['area']]),
            'editor in another fagområde' => $this->person($customer, self::EDITOR, [$elsewhere]),
            'System Owner without a role' => $this->member($customer, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
            'another customer\'s editor' => $this->person($other, self::EDITOR, [$otherArea], allAreas: true),
            'another customer\'s System Owner' => $this->member($other, User::ROLE_CUSTOMER_ADMIN, User::BID_ROLE_SYSTEM_OWNER),
        ];

        foreach ($people as $who => $person) {
            $this->assertEveryWriteRefused($case, $person, $who);
        }
    }

    public function test_a_customer_without_the_module_does_no_register_work(): void
    {
        $case = $this->case();
        $case['customer']->packageEntitlements()->where('package_key', 'objectives')->delete();

        $this->assertEveryWriteRefused($case, $case['editor']->fresh(), 'no module');
    }

    /** Endre kobling shows Kvalitet's processes, so it takes Kvalitet read too — objective.edit never implies it. */
    public function test_linking_to_kvalitet_takes_kvalitet_read_in_the_service(): void
    {
        $case = $this->case();
        $editor = $this->person($case['customer'], [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$case['area']]);

        $this->assertRefused(fn () => app(KpiQualityContextService::class)->syncProcess($editor, $case['kpi'], (int) $case['process']->id, true, []), 'without Kvalitet read');
        $this->assertSame(0, KpiProcess::query()->where('kpi_id', $case['kpi']->id)->count());
    }

    /** A closed objective is reopened before anything under it changes; a retired KPI before it changes. */
    public function test_the_services_hold_the_status_rules_themselves(): void
    {
        $case = $this->case();
        $editor = $case['editor'];

        foreach ([
            'new KPI under a closed objective' => fn () => app(KpiRegistration::class)->create($editor, $case['closed'], $this->kpiFields('Ny')),
            'retire a KPI under a closed objective' => fn () => app(KpiLifecycleService::class)->retire($case['closedKpi'], $editor, null),
            'change a closed objective' => fn () => app(ObjectiveRegistration::class)->update($editor, $case['closed'], $this->objectiveFields($case, 'Endret')),
            'change a retired KPI' => fn () => app(KpiRegistration::class)->update($editor, $case['retired'], $this->kpiFields('Endret')),
            'link a retired KPI' => fn () => app(KpiQualityContextService::class)->syncProcess($editor, $case['retired'], (int) $case['process']->id, true, []),
        ] as $what => $write) {
            try {
                $write();
                $this->fail("{$what} must be refused");
            } catch (ValidationException) {
                // Refused by the state, with the module's own message.
            }
        }

        $this->assertSame(0, Kpi::query()->where('objective_id', $case['closed']->id)->where('title', 'Ny')->count());
        $this->assertSame('Stengt', $case['closed']->fresh()->title);
        $this->assertSame('Utgått', $case['retired']->fresh()->title);
        $this->assertTrue($case['closedKpi']->fresh()->isActive());
    }

    /** The services write a record's own fields only — never its customer, its objective or its status. */
    public function test_registration_never_writes_customer_objective_or_status(): void
    {
        $case = $this->case();
        $other = $this->customer('Annen Kunde AS');

        $kpi = app(KpiRegistration::class)->create($case['editor'], $case['objective'], $this->kpiFields('Ny') + [
            'customer_id' => $other->id,
            'objective_id' => $case['closed']->id,
            'status' => Kpi::STATUS_RETIRED,
        ]);
        $this->assertSame([(int) $case['customer']->id, (int) $case['objective']->id, Kpi::STATUS_ACTIVE], [(int) $kpi->customer_id, (int) $kpi->objective_id, $kpi->status]);

        app(KpiRegistration::class)->update($case['editor'], $kpi, $this->kpiFields('Ny') + ['objective_id' => $case['closed']->id, 'status' => Kpi::STATUS_RETIRED]);
        $kpi->refresh();
        $this->assertSame([(int) $case['objective']->id, Kpi::STATUS_ACTIVE], [(int) $kpi->objective_id, $kpi->status]);

        $objective = app(ObjectiveRegistration::class)->create($case['editor'], $this->objectiveFields($case, 'Nytt') + ['customer_id' => $other->id, 'status' => Objective::STATUS_ACHIEVED]);
        $this->assertSame([(int) $case['customer']->id, Objective::STATUS_ACTIVE], [(int) $objective->customer_id, $objective->status]);
    }

    /** Moving an objective takes objective.edit on both sides — reading the new area is not enough. */
    public function test_an_objective_is_moved_only_into_an_area_the_actor_may_edit_in(): void
    {
        $case = $this->case();
        $hr = BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR']);
        // Edits in Drift, reads in HR — and owns the objective, so the owner rule holds in both.
        $mover = $this->person($case['customer'], [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$case['area']]);
        $reader = CustomerRole::query()->create(['customer_id' => $case['customer']->id, 'name' => 'Leser HR', 'is_active' => true]);
        $reader->syncPermissions([CustomerPermissionCatalog::OBJECTIVE_VIEW]);
        $reader->syncBusinessAreas(false, [(int) $hr->id]);
        $mover->customerRoles()->attach($reader->id, ['customer_id' => $case['customer']->id]);

        try {
            app(ObjectiveRegistration::class)->update($mover, $case['spare'], ['business_area_id' => (int) $hr->id, 'owner_user_id' => (int) $mover->id] + $this->objectiveFields($case, 'Flyttet'));
            $this->fail('a move into an area without objective.edit must be refused');
        } catch (ValidationException $e) {
            $this->assertSame(['business_area_id'], array_keys($e->errors()));
        }

        $this->assertSame([(int) $case['area']->id, 'Reserve'], [(int) $case['spare']->fresh()->business_area_id, $case['spare']->fresh()->title]);
    }

    /** An owner who could not open what they own is refused by the service, whoever calls it. */
    public function test_an_owner_who_cannot_read_the_area_is_refused_by_the_service(): void
    {
        $case = $this->case();
        $outsider = $this->person($case['customer'], [CustomerPermissionCatalog::OBJECTIVE_VIEW], [BusinessArea::query()->create(['customer_id' => $case['customer']->id, 'name' => 'HR'])]);
        $foreign = $this->person($this->customer('Annen Kunde AS'), [CustomerPermissionCatalog::OBJECTIVE_VIEW], [], allAreas: true);

        foreach (['cannot read the area' => $outsider, 'another customer' => $foreign] as $who => $owner) {
            foreach ([
                'objective' => fn () => app(ObjectiveRegistration::class)->create($case['editor'], ['owner_user_id' => $owner->id] + $this->objectiveFields($case, 'Nytt')),
                'kpi' => fn () => app(KpiRegistration::class)->create($case['editor'], $case['objective'], ['owner_user_id' => $owner->id] + $this->kpiFields('Ny')),
            ] as $what => $write) {
                try {
                    $write();
                    $this->fail("{$who}: {$what} owner must be refused");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('owner_user_id', $e->errors(), "{$who}: {$what}");
                }
            }
        }

        $this->assertSame(0, Objective::query()->where('title', 'Nytt')->count());
        $this->assertSame(0, Kpi::query()->where('title', 'Ny')->count());
    }

    /**
     * Every register write in Mål og KPI, as callables taking nothing — each through its service.
     *
     * @param  array<string, mixed>  $case
     * @return array<string, callable(): mixed>
     */
    private function work(array $case, User $actor, bool $handOff = true, bool $lifecycle = true): array
    {
        $work = [
            'create objective' => fn () => app(ObjectiveRegistration::class)->create($actor, $this->objectiveFields($case, 'Nytt mål')),
            'update objective' => fn () => app(ObjectiveRegistration::class)->update($actor, $case['objective'], $this->objectiveFields($case, 'Endret mål')),
            'close objective' => fn () => app(ObjectiveLifecycleService::class)->close($case['spare'], $actor, Objective::STATUS_CANCELLED, null),
            'reopen objective' => fn () => app(ObjectiveLifecycleService::class)->reopen($case['closed'], $actor, 'Feil lukket'),
            'create kpi' => fn () => app(KpiRegistration::class)->create($actor, $case['objective'], $this->kpiFields('Ny KPI')),
            'update kpi' => fn () => app(KpiRegistration::class)->update($actor, $case['kpi'], $this->kpiFields('Endret KPI')),
            'retire kpi' => fn () => app(KpiLifecycleService::class)->retire($case['spareKpi'], $actor, null),
            'reopen kpi' => fn () => app(KpiLifecycleService::class)->reopen($case['retired'], $actor, 'Måles igjen'),
            'link to kvalitet' => fn () => app(KpiQualityContextService::class)->syncProcess($actor, $case['kpi'], (int) $case['process']->id, true, ['registrer-hendelse']),
        ];

        if (! $lifecycle) {
            unset($work['close objective'], $work['reopen objective'], $work['retire kpi'], $work['reopen kpi']);
        }

        if ($handOff) {
            $work['hand off to wiki'] = fn () => app(WikiKnowledgeHandoffService::class)->handOff($actor, 'objective', $case['objective'], 'Læring', ['about'], 'Dette bør andre vite.');
        }

        return $work;
    }

    /** @param array<string, mixed> $case */
    private function assertWritten(array $case, User $actor, bool $lifecycle = true): void
    {
        $this->assertSame(1, Objective::query()->where('customer_id', $case['customer']->id)->where('title', 'Nytt mål')->where('created_by', $actor->id)->count());
        $this->assertSame('Endret mål', $case['objective']->fresh()->title);
        $this->assertSame(1, Kpi::query()->where('objective_id', $case['objective']->id)->where('title', 'Ny KPI')->count());
        $this->assertSame('Endret KPI', $case['kpi']->fresh()->title);
        $this->assertSame(1, KpiProcess::query()->where('kpi_id', $case['kpi']->id)->count());
        $this->assertSame(1, KpiActivity::query()->where('kpi_id', $case['kpi']->id)->count());

        if (! $lifecycle) {
            return;
        }

        $this->assertSame(Objective::STATUS_CANCELLED, $case['spare']->fresh()->status);
        $this->assertSame(Objective::STATUS_ACTIVE, $case['closed']->fresh()->status);
        $this->assertSame(Kpi::STATUS_RETIRED, $case['spareKpi']->fresh()->status);
        $this->assertSame(Kpi::STATUS_ACTIVE, $case['retired']->fresh()->status);
        $this->assertSame((int) $actor->id, (int) ObjectiveStatusChange::query()->where('objective_id', $case['spare']->id)->latest('id')->value('changed_by_user_id'));
        $this->assertSame((int) $actor->id, (int) KpiStatusChange::query()->where('kpi_id', $case['spareKpi']->id)->latest('id')->value('changed_by_user_id'));
    }

    /** @param array<string, mixed> $case */
    private function assertEveryWriteRefused(array $case, User $actor, string $who): void
    {
        $before = $this->snapshot($case);

        foreach ($this->work($case, $actor) as $what => $write) {
            $this->assertRefused($write, "{$who}: {$what}");
        }

        $this->assertSame($before, $this->snapshot($case), $who);
    }

    private function assertRefused(callable $write, string $what): void
    {
        try {
            $write();
            $this->fail("{$what} must be refused");
        } catch (AuthorizationException|ValidationException $e) {
            // A 403, or the 422 the area check gives for an area id that came from a form.
            if ($e instanceof ValidationException) {
                $this->assertArrayHasKey('business_area_id', $e->errors(), $what);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function snapshot(array $case): array
    {
        $customerId = $case['customer']->id;

        return [
            'objectives' => Objective::query()->where('customer_id', $customerId)->orderBy('id')->get(['id', 'title', 'status', 'business_area_id'])->toArray(),
            'kpis' => Kpi::query()->where('customer_id', $customerId)->orderBy('id')->get(['id', 'title', 'status'])->toArray(),
            'objective_changes' => ObjectiveStatusChange::query()->where('customer_id', $customerId)->count(),
            'kpi_changes' => KpiStatusChange::query()->where('customer_id', $customerId)->count(),
            'links' => KpiProcess::query()->where('customer_id', $customerId)->count() + KpiActivity::query()->where('customer_id', $customerId)->count(),
            'wiki' => EnterpriseWikiDocument::query()->where('customer_id', $customerId)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{title: string, description: ?string, business_area_id: int, owner_user_id: int, target_date: ?string}
     */
    private function objectiveFields(array $case, string $title): array
    {
        return [
            'title' => $title,
            'description' => null,
            'business_area_id' => (int) $case['area']->id,
            'owner_user_id' => (int) $case['editor']->id,
            'target_date' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function kpiFields(string $title): array
    {
        return [
            'title' => $title,
            'description' => null,
            'owner_user_id' => null,
            'unit' => Kpi::UNIT_PERCENT,
            'unit_label' => null,
            'currency_code' => null,
            'target_min' => '98',
            'target_max' => null,
            'tolerance' => null,
            'baseline_value' => null,
            'baseline_date' => null,
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ];
    }

    /**
     * Objectives «Stabil drift» (KPIs «Oppetid» and «Svartid» active, «Utgått» retired), «Reserve»
     * (active) and «Stengt» (closed, with an active KPI); a Kvalitet process with one activity.
     *
     * @return array<string, mixed>
     */
    private function case(): array
    {
        $customer = $this->customer('Målregister AS');
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);

        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Redaktør '.Str::upper(Str::random(6)), 'is_active' => true]);
        $role->syncPermissions(self::EDITOR);
        $role->syncBusinessAreas(false, [(int) $area->id]);

        $editor = $this->member($customer);
        $editor->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);
        $integration = $this->member($customer, User::ROLE_INTEGRATION);
        $integration->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        $objective = $this->objective($customer, $area, $editor, 'Stabil drift');
        $spare = $this->objective($customer, $area, $editor, 'Reserve');
        $closed = $this->objective($customer, $area, $editor, 'Stengt');
        $kpi = $this->kpi($objective, 'Oppetid');
        $spareKpi = $this->kpi($objective, 'Svartid');
        $retired = $this->kpi($objective, 'Utgått');
        $closedKpi = $this->kpi($closed, 'Under stengt');

        app(KpiLifecycleService::class)->retire($retired, $editor, null);
        app(ObjectiveLifecycleService::class)->close($closed, $editor, Objective::STATUS_ACHIEVED, null);

        $process = QualityItem::query()->create([
            'customer_id' => $customer->id,
            'quality_type' => QualityItem::TYPE_PROCESS,
            'title' => 'Hendelseshåndtering',
            'status' => QualityItem::STATUS_DRAFT,
        ]);
        app(QualityProcessBlueprintService::class)->store((int) $customer->id, $process, [
            'lanes' => [['key' => 'drift', 'label' => 'Driftsansvarlig']],
            'nodes' => [
                ['key' => 'start', 'lane' => 'drift', 'type' => 'start', 'label' => 'Start'],
                ['key' => 'registrer-hendelse', 'lane' => 'drift', 'type' => 'step', 'label' => 'Registrer hendelse'],
                ['key' => 'slutt', 'lane' => 'drift', 'type' => 'end', 'label' => 'Slutt'],
            ],
            'edges' => [['from' => 'start', 'to' => 'registrer-hendelse'], ['from' => 'registrer-hendelse', 'to' => 'slutt']],
        ], QualityProcessBlueprint::SOURCE_MANUAL, $this->qualityAuthor($customer), basedOn: QualityFlowVersion::of($process));

        return [
            'customer' => $customer, 'area' => $area, 'editor' => $editor, 'integration' => $integration,
            'objective' => $objective->fresh(), 'spare' => $spare->fresh(), 'closed' => $closed->fresh(),
            'kpi' => $kpi->fresh(), 'spareKpi' => $spareKpi->fresh(), 'retired' => $retired->fresh(), 'closedKpi' => $closedKpi->fresh(),
            'process' => $process,
        ];
    }

    /** Kvalitet's flows are written by someone allowed to — not the people under test. */
    private function qualityAuthor(Customer $customer): User
    {
        return $this->person($customer, [CustomerPermissionCatalog::QUALITY_VIEW, CustomerPermissionCatalog::QUALITY_EDIT], []);
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

    private function kpi(Objective $objective, string $title): Kpi
    {
        return Kpi::query()->create(['customer_id' => $objective->customer_id, 'objective_id' => $objective->id] + $this->kpiFields($title));
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
            'slug' => 'maalregister-'.Str::lower(Str::random(10)),
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
            'email' => 'maalregister-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'bid_role' => $bidRole,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
