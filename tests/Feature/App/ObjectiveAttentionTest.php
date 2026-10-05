<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\User;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiMeasurementService;
use App\Services\Objectives\KpiPeriods;
use App\Services\Objectives\ObjectiveAttentionService;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * «Trenger oppmerksomhet» for Mål og KPI: the four rules (KPI ikke på mål, Måling mangler, Måldato
 * passert, Mål mangler ansvarlig), what closed objectives and retired KPIs do to them, how objectives
 * and KPIs are counted, that counts come from the user's visible objectives only, and that the
 * number of queries does not grow with the number of KPIs.
 *
 * Dates are fixed with travelTo(): the rules are calendar arithmetic.
 */
class ObjectiveAttentionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const NBSP = "\u{00A0}";

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

    // ---------------------------------------------------------------------
    // KPI ikke på mål
    // ---------------------------------------------------------------------

    public function test_attention_and_off_target_are_findings_and_on_target_or_unmeasured_are_not(): void
    {
        $this->at('2026-10-02');
        ['objective' => $objective, 'viewer' => $viewer, 'measurer' => $measurer] = $this->world();
        $target = ['target_min' => '99.5', 'tolerance' => '1'];

        $attention = $this->kpi($objective, $target + ['title' => 'Backup']);
        $off = $this->kpi($objective, $target + ['title' => 'Oppetid']);
        $on = $this->kpi($objective, $target + ['title' => 'Patching']);
        $this->kpi($objective, $target + ['title' => 'Ikke målt']);

        $this->record($attention, $measurer, '2026-09', '98.7');
        $this->record($off, $measurer, '2026-09', '97');
        $this->record($on, $measurer, '2026-09', '99.5');

        $category = $this->category($this->overview($viewer), ObjectiveAttentionService::KPI_OFF_TARGET);

        $this->assertSame(2, $category['count']);
        $this->assertSame('kpi', $category['subject']);
        $this->assertSame(['Backup', 'Oppetid'], array_column($category['items'], 'title'));
        $this->assertSame(
            'Siste verdi 98,7'.self::NBSP.'% er under målet ≥'.self::NBSP.'99,5'.self::NBSP.'%.',
            $category['items'][0]['detail'],
        );
        $this->assertSame('Stabil drift', $category['items'][0]['objective_title']);
        $this->assertSame('Drift', $category['items'][0]['area_name']);
        $this->assertStringEndsWith("/app/objectives/{$objective->id}/kpis/{$attention->id}", $category['items'][0]['url']);
    }

    public function test_an_upper_bound_reads_as_above_the_target(): void
    {
        $this->at('2026-10-02');
        ['objective' => $objective, 'viewer' => $viewer, 'measurer' => $measurer] = $this->world();
        $kpi = $this->kpi($objective, [
            'title' => 'Hendelser',
            'unit' => Kpi::UNIT_COUNT,
            'unit_label' => 'hendelser',
            'target_min' => null,
            'target_max' => '3',
        ]);
        $this->record($kpi, $measurer, '2026-09', '5');

        $this->assertSame(
            'Siste verdi 5'.self::NBSP.'hendelser er over målet ≤'.self::NBSP.'3'.self::NBSP.'hendelser.',
            $this->category($this->overview($viewer), ObjectiveAttentionService::KPI_OFF_TARGET)['items'][0]['detail'],
        );
    }

    public function test_the_result_follows_todays_target_live(): void
    {
        $this->at('2026-10-02');
        ['objective' => $objective, 'viewer' => $viewer, 'measurer' => $measurer] = $this->world();
        $kpi = $this->kpi($objective, ['target_min' => '98']);
        $this->record($kpi, $measurer, '2026-09', '99');

        $this->assertNull($this->category($this->overview($viewer), ObjectiveAttentionService::KPI_OFF_TARGET));

        $kpi->update(['target_min' => '99.5']);

        $this->assertSame(1, $this->category($this->overview($viewer), ObjectiveAttentionService::KPI_OFF_TARGET)['count']);

        $kpi->update(['target_min' => '98', 'tolerance' => null]);

        $this->assertNull($this->category($this->overview($viewer), ObjectiveAttentionService::KPI_OFF_TARGET));
    }

    // ---------------------------------------------------------------------
    // Måling mangler
    // ---------------------------------------------------------------------

    public function test_a_missing_measurement_counts_only_after_the_deadline_day(): void
    {
        $this->at('2026-08-15');
        ['objective' => $objective, 'viewer' => $viewer, 'measurer' => $measurer] = $this->world();
        $kpi = $this->kpi($objective, ['title' => 'Kundetilfredshet', 'reporting_grace_days' => 7]);

        $this->at('2026-09-03');
        $this->record($kpi, $measurer, '2026-08', '99');

        // September ends on the 30th; seven grace days make the 7th of October the last day on time.
        $this->assertNull($this->category($this->overview($viewer, '2026-10-03'), ObjectiveAttentionService::MEASUREMENT_MISSING));
        $this->assertNull($this->category($this->overview($viewer, '2026-10-07'), ObjectiveAttentionService::MEASUREMENT_MISSING));

        $category = $this->category($this->overview($viewer, '2026-10-08'), ObjectiveAttentionService::MEASUREMENT_MISSING);
        $this->assertSame(1, $category['count']);
        $this->assertSame('Måling mangler for September 2026.', $category['items'][0]['detail']);
    }

    public function test_several_missing_periods_name_the_count_and_the_oldest(): void
    {
        $this->at('2026-06-10');
        ['objective' => $objective, 'viewer' => $viewer] = $this->world();
        $this->kpi($objective, ['title' => 'Kundetilfredshet']);

        $category = $this->category($this->overview($viewer, '2026-10-08'), ObjectiveAttentionService::MEASUREMENT_MISSING);

        $this->assertSame('4 måleperioder mangler. Eldste er Juni 2026.', $category['items'][0]['detail']);
    }

    public function test_a_kpi_without_frequency_is_never_missing_a_measurement(): void
    {
        $this->at('2026-01-10');
        ['objective' => $objective, 'viewer' => $viewer] = $this->world();
        $this->kpi($objective, ['frequency' => null]);

        $this->assertSame([], $this->overview($viewer, '2026-10-08')['categories']);
    }

    public function test_a_retired_kpi_gives_no_attention(): void
    {
        $this->at('2026-06-10');
        ['objective' => $objective, 'viewer' => $viewer, 'measurer' => $measurer, 'editor' => $editor] = $this->world();
        $kpi = $this->kpi($objective, ['target_min' => '99.5']);
        $this->record($kpi, $measurer, '2026-05', '90');

        $this->at('2026-10-08');
        $this->assertSame(1, $this->overview($viewer)['kpi_total']);

        app(KpiLifecycleService::class)->retire($kpi->fresh(), $editor, null);

        $this->assertSame(['objective_total' => 0, 'kpi_total' => 0, 'categories' => []], $this->overview($viewer));
    }

    public function test_a_closed_objective_silences_itself_and_its_kpis(): void
    {
        $this->at('2026-06-10');
        ['area' => $area, 'customer' => $customer, 'viewer' => $viewer, 'measurer' => $measurer, 'editor' => $editor] = $this->world();
        $objective = $this->objective($customer, $area, 'Lukkes', null, '2026-07-01');
        $kpi = $this->kpi($objective, ['target_min' => '99.5']);
        $this->record($kpi, $measurer, '2026-05', '90');

        $this->at('2026-10-08');
        $overview = $this->overview($viewer);
        $this->assertSame(1, $overview['objective_total']);
        $this->assertSame(1, $overview['kpi_total']);
        $this->assertCount(4, $overview['categories']);

        app(ObjectiveLifecycleService::class)->close($objective->fresh(), $editor, Objective::STATUS_NOT_ACHIEVED, null);

        $this->assertSame(['objective_total' => 0, 'kpi_total' => 0, 'categories' => []], $this->overview($viewer));
        $this->assertNull(app(ObjectiveAttentionService::class)->forObjective($objective->fresh()));
    }

    // ---------------------------------------------------------------------
    // Måldato passert
    // ---------------------------------------------------------------------

    public function test_the_target_date_is_passed_from_the_day_after(): void
    {
        $this->at('2026-09-01');
        ['area' => $area, 'customer' => $customer, 'viewer' => $viewer, 'editor' => $editor] = $this->world();
        $objective = $this->objective($customer, $area, 'Sertifisering', $editor, '2026-09-30');

        $this->assertNull($this->category($this->overview($viewer, '2026-09-29'), ObjectiveAttentionService::TARGET_DATE_PASSED));
        $this->assertNull($this->category($this->overview($viewer, '2026-09-30'), ObjectiveAttentionService::TARGET_DATE_PASSED));

        $category = $this->category($this->overview($viewer, '2026-10-01'), ObjectiveAttentionService::TARGET_DATE_PASSED);
        $this->assertSame('objective', $category['subject']);
        $this->assertSame([['id' => (int) $objective->id, 'title' => 'Sertifisering', 'objective_title' => null, 'area_name' => 'Drift']],
            array_map(fn (array $item): array => array_diff_key($item, ['url' => true, 'detail' => true]), $category['items']));
        $this->assertSame('Måldato 30. september 2026 er passert.', $category['items'][0]['detail']);
    }

    public function test_a_closed_objective_past_its_target_date_needs_no_attention(): void
    {
        $this->at('2026-09-01');
        ['area' => $area, 'customer' => $customer, 'viewer' => $viewer, 'editor' => $editor] = $this->world();
        $objective = $this->objective($customer, $area, 'Sertifisering', $editor, '2026-09-30');
        app(ObjectiveLifecycleService::class)->close($objective, $editor, Objective::STATUS_ACHIEVED, null);

        $this->assertSame([], $this->overview($viewer, '2026-10-05')['categories']);
    }

    // ---------------------------------------------------------------------
    // Mål mangler ansvarlig
    // ---------------------------------------------------------------------

    public function test_an_active_objective_without_owner_needs_attention_and_a_kpi_without_owner_does_not(): void
    {
        $this->at('2026-10-02');
        ['area' => $area, 'customer' => $customer, 'objective' => $owned, 'viewer' => $viewer, 'editor' => $editor] = $this->world();
        // The KPI has no owner of its own; it falls back to the objective's, which is no finding.
        $this->kpi($owned, ['frequency' => null, 'owner_user_id' => null]);
        $orphan = $this->objective($customer, $area, 'Uten ansvarlig', $this->member($customer));
        $orphan->owner->delete();

        $category = $this->category($this->overview($viewer), ObjectiveAttentionService::OWNER_MISSING);
        $this->assertSame(['Uten ansvarlig'], array_column($category['items'], 'title'));
        $this->assertSame('Målet har ingen ansvarlig.', $category['items'][0]['detail']);

        app(ObjectiveLifecycleService::class)->close($orphan->fresh(), $editor, Objective::STATUS_CANCELLED, null);

        $this->assertNull($this->category($this->overview($viewer), ObjectiveAttentionService::OWNER_MISSING));
    }

    // ---------------------------------------------------------------------
    // Counting
    // ---------------------------------------------------------------------

    public function test_a_kpi_with_two_findings_is_one_kpi_and_kpi_findings_never_flag_the_objective(): void
    {
        $this->at('2026-06-10');
        ['objective' => $objective, 'viewer' => $viewer, 'measurer' => $measurer] = $this->world();
        $kpi = $this->kpi($objective, ['target_min' => '99.5']);
        $this->record($kpi, $measurer, '2026-06', '90');

        $overview = $this->overview($viewer, '2026-10-08');

        $this->assertSame(1, $overview['kpi_total']);
        $this->assertSame(0, $overview['objective_total']);
        $this->assertSame(1, $this->category($overview, ObjectiveAttentionService::KPI_OFF_TARGET)['count']);
        $this->assertSame(1, $this->category($overview, ObjectiveAttentionService::MEASUREMENT_MISSING)['count']);
    }

    public function test_an_objective_with_two_findings_and_several_kpi_findings_is_counted_once(): void
    {
        $this->at('2026-06-10');
        ['area' => $area, 'customer' => $customer, 'viewer' => $viewer, 'measurer' => $measurer] = $this->world();
        $objective = $this->objective($customer, $area, 'Alt på en gang', null, '2026-09-01');
        $first = $this->kpi($objective, ['title' => 'A', 'target_min' => '99.5', 'frequency' => null]);
        $this->kpi($objective, ['title' => 'B']);
        $this->recordOn($first, $measurer, '2026-06-09', '50');

        $overview = $this->overview($viewer, '2026-10-08');

        $this->assertSame(1, $overview['objective_total']);
        $this->assertSame(2, $overview['kpi_total']);
        $this->assertSame([
            ObjectiveAttentionService::KPI_OFF_TARGET => 1,
            ObjectiveAttentionService::MEASUREMENT_MISSING => 1,
            ObjectiveAttentionService::TARGET_DATE_PASSED => 1,
            ObjectiveAttentionService::OWNER_MISSING => 1,
        ], collect($overview['categories'])->pluck('count', 'key')->all());

        $note = app(ObjectiveAttentionService::class)->forObjective($objective->fresh(), CarbonImmutable::parse('2026-10-08'));
        $this->assertSame(2, $note['kpi_count']);
        $this->assertSame([ObjectiveAttentionService::TARGET_DATE_PASSED, ObjectiveAttentionService::OWNER_MISSING], array_column($note['reasons'], 'key'));
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_counts_and_lists_come_from_the_visible_objectives_only(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'viewer' => $viewer] = $this->world();
        $hidden = $this->area($customer, 'Økonomi');
        $this->objective($customer, $hidden, 'Skjult mål', null, '2026-01-01');

        $overview = $this->overview($viewer);
        $this->assertSame(0, $overview['objective_total']);
        $this->assertSame([], $overview['categories']);

        $all = $this->member($customer);
        $this->grantAll($customer, $all, [CustomerPermissionCatalog::OBJECTIVE_VIEW]);
        $this->assertSame(1, $this->overview($all)['objective_total']);

        $props = $this->actingAs($all)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertSame('all', $props['attention']['scope']);
        $props = $this->actingAs($viewer)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertSame('areas', $props['attention']['scope']);
        $this->assertSame([], $props['attention']['categories']);
    }

    public function test_permission_and_area_from_different_roles_do_not_combine(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $drift] = $this->world();
        $finance = $this->area($customer, 'Økonomi');
        $this->objective($customer, $drift, 'Drift uten ansvarlig');
        $this->objective($customer, $finance, 'Økonomi uten ansvarlig');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$drift]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_EDIT], [$finance]);

        $items = $this->category($this->overview($user), ObjectiveAttentionService::OWNER_MISSING)['items'];
        $this->assertSame(['Drift uten ansvarlig'], array_column($items, 'title'));
    }

    public function test_system_owner_without_a_data_role_sees_no_counts(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'owner' => $systemOwner, 'area' => $area] = $this->world();
        $this->objective($customer, $area, 'Uten ansvarlig', null, '2026-01-01');

        $this->assertSame(['objective_total' => 0, 'kpi_total' => 0, 'categories' => []], $this->overview($systemOwner));
        $this->assertNull($this->actingAs($systemOwner)->get('/app/objectives')->assertOk()->viewData('page')['props']['attention']);
    }

    public function test_another_customers_objectives_never_count(): void
    {
        $this->at('2026-10-02');
        ['viewer' => $viewer] = $this->world();
        ['customer' => $other, 'area' => $otherArea] = $this->world();
        $this->objective($other, $otherArea, 'Annen kunde', null, '2026-01-01');

        $this->assertSame([], $this->overview($viewer)['categories']);
    }

    public function test_the_objective_page_carries_a_short_note(): void
    {
        $this->at('2026-10-02');
        ['area' => $area, 'customer' => $customer, 'objective' => $calm, 'viewer' => $viewer] = $this->world();
        $late = $this->objective($customer, $area, 'Forsinket', $viewer, '2026-09-30');

        $this->assertNull($this->actingAs($viewer)->get("/app/objectives/{$calm->id}")->assertOk()->viewData('page')['props']['attention']);
        $this->assertSame(
            ['kpi_count' => 0, 'reasons' => [['key' => 'target_date_passed', 'detail' => 'Måldato 30. september 2026 er passert.']]],
            $this->actingAs($viewer)->get("/app/objectives/{$late->id}")->assertOk()->viewData('page')['props']['attention'],
        );
    }

    // ---------------------------------------------------------------------
    // Performance
    // ---------------------------------------------------------------------

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_kpis(): void
    {
        $this->at('2026-03-10');
        ['customer' => $customer, 'area' => $area, 'objective' => $objective, 'viewer' => $viewer, 'measurer' => $measurer] = $this->world();
        $kpi = $this->kpi($objective, ['target_min' => '99.5']);
        $this->record($kpi, $measurer, '2026-02', '90');

        $this->at('2026-10-08');
        $few = $this->countQueries(fn () => $this->overview($viewer));

        $this->at('2026-03-10');
        foreach (range(1, 4) as $index) {
            $more = $this->objective($customer, $area, "Mål {$index}", null, '2026-05-01');

            foreach (range(1, 3) as $kpiIndex) {
                $added = $this->kpi($more, ['title' => "KPI {$index}.{$kpiIndex}", 'target_min' => '99.5']);
                $this->record($added, $measurer, '2026-02', '90');
            }
        }

        $this->at('2026-10-08');
        $many = $this->countQueries(fn () => $this->overview($viewer));

        $this->assertSame(13, $this->overview($viewer)['kpi_total']);
        $this->assertGreaterThan(0, $few);
        $this->assertSame($few, $many);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function at(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 10:00:00'));
    }

    /** @return array<string, mixed> */
    private function overview(User $user, ?string $today = null): array
    {
        return app(ObjectiveAttentionService::class)->overview($user->fresh(), $today !== null ? CarbonImmutable::parse($today) : null);
    }

    /** @return array<string, mixed>|null */
    private function category(array $overview, string $key): ?array
    {
        return collect($overview['categories'])->firstWhere('key', $key);
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $before = $count;
        $callback();

        return $count - $before;
    }

    private function record(Kpi $kpi, User $user, string $periodKey, string $value): void
    {
        $kpi = $kpi->fresh();

        app(KpiMeasurementService::class)->record($kpi, $user, app(KpiPeriods::class)->fromKey($kpi->frequency, $periodKey), $value, null);
    }

    private function recordOn(Kpi $kpi, User $user, string $date, string $value): void
    {
        app(KpiMeasurementService::class)->record($kpi->fresh(), $user, app(KpiPeriods::class)->day(CarbonImmutable::parse($date)), $value, null);
    }

    /**
     * A customer with the area Drift, an active objective there owned by its editor, a viewer, a
     * measurer and an editor in that area, and a System Owner without any objective role.
     *
     * @return array{customer: Customer, owner: User, area: BusinessArea, objective: Objective, viewer: User, measurer: User, editor: User}
     */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $area = $this->area($customer, 'Drift');
        $viewer = $this->member($customer);
        $this->grant($customer, $viewer, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$area]);
        $measurer = $this->member($customer);
        $this->grant($customer, $measurer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$area]);
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$area]);

        return [
            'customer' => $customer,
            'owner' => $owner,
            'area' => $area,
            'objective' => $this->objective($customer, $area, 'Stabil drift', $editor),
            'viewer' => $viewer,
            'measurer' => $measurer,
            'editor' => $editor,
        ];
    }

    /** @param array<string, mixed> $override */
    private function kpi(Objective $objective, array $override = []): Kpi
    {
        return Kpi::query()->create($override + [
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => 'Oppetid',
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '98',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ]);
    }

    private function objective(Customer $customer, BusinessArea $area, string $title, ?User $owner = null, ?string $targetDate = null): Objective
    {
        return Objective::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'owner_user_id' => $owner?->id,
            'target_date' => $targetDate,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /** @param  list<string>  $permissionKeys */
    private function grantAll(Customer $customer, User $user, array $permissionKeys): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys);
        $role->syncBusinessAreas(true, []);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /** @param  list<string>  $permissionKeys */
    private function role(Customer $customer, array $permissionKeys): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);

        return $role;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(6)),
            'email' => 'oppmerksomhet-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(): array
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
            'name' => 'Oppmerksomhet AS',
            'slug' => 'oppmerksomhet-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'quality'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'oppmerksomhet-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
