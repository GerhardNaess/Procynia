<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementAttentionService;
use App\Services\Improvements\ImprovementCaseLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * «Trenger oppmerksomhet» for Avvik og forbedringer: the six rules (Sak med passert frist, Tiltak med
 * passert frist, Sak mangler ansvarlig, Tiltak mangler ansvarlig, Venter på effektverifisering,
 * Tiltak ikke effektivt), what an ended case does to them, how cases and tiltak are counted, that
 * counts come from the user's visible cases only, that the number of queries does not grow with the
 * number of tiltak, and the register's tiltak indicator.
 *
 * Dates are fixed with travelTo(): the rules are calendar arithmetic.
 */
class ImprovementAttentionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
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

    // ---------------------------------------------------------------------
    // The rules
    // ---------------------------------------------------------------------

    public function test_a_case_is_past_its_frist_from_the_day_after(): void
    {
        $this->at('2026-09-29');
        ['customer' => $customer, 'area' => $area, 'user' => $user] = $this->world();
        $case = $this->improvementCase($customer, $area, 'Sak med frist', $user);
        $case->forceFill(['due_date' => '2026-09-30'])->save();

        $this->assertNull($this->category($this->overview($user, '2026-09-29'), ImprovementAttentionService::CASE_OVERDUE));
        $this->assertNull($this->category($this->overview($user, '2026-09-30'), ImprovementAttentionService::CASE_OVERDUE));

        $category = $this->category($this->overview($user, '2026-10-01'), ImprovementAttentionService::CASE_OVERDUE);
        $this->assertSame('case', $category['subject']);
        $this->assertSame([[
            'id' => (int) $case->id,
            'title' => 'Sak med frist',
            'case_title' => null,
            'area_name' => 'HR',
            'url' => route('app.improvements.show', ['caseId' => $case->id]),
            'detail' => 'Fristen 30. september 2026 er passert.',
        ]], $category['items']);

        // Started, still active: still a finding.
        app(ImprovementCaseLifecycleService::class)->start($case, $user);
        $this->assertSame(1, $this->overview($user, '2026-10-01')['case_total']);
    }

    public function test_a_tiltak_still_to_be_done_is_past_its_frist_from_the_day_after(): void
    {
        $this->at('2026-09-29');
        ['user' => $user, 'case' => $case] = $this->world();
        $planned = $this->improvementAction($case, 'Planlagt', $user, '2026-09-30');
        $started = $this->improvementAction($case, 'Startet', $user, '2026-09-30');
        $this->lifecycle()->start($started, $user);
        $completed = $this->improvementAction($case, 'Fullført', $user, '2026-09-30');
        $this->lifecycle()->complete($completed, $user, 'Gjort');
        $this->lifecycle()->verify($completed, $user, 'effective', 'Virket');
        $cancelled = $this->improvementAction($case, 'Avbrutt', $user, '2026-09-30');
        $this->lifecycle()->cancel($cancelled, $user, 'Unødvendig');

        $this->assertNull($this->category($this->overview($user, '2026-09-29'), ImprovementAttentionService::ACTION_OVERDUE));
        $this->assertNull($this->category($this->overview($user, '2026-09-30'), ImprovementAttentionService::ACTION_OVERDUE));

        $category = $this->category($this->overview($user, '2026-10-01'), ImprovementAttentionService::ACTION_OVERDUE);
        $this->assertSame('action', $category['subject']);
        $this->assertSame(['Planlagt', 'Startet'], array_column($category['items'], 'title'));
        $this->assertSame([
            'id' => (int) $planned->id,
            'title' => 'Planlagt',
            'case_title' => 'Sak',
            'area_name' => 'HR',
            'url' => route('app.improvements.show', ['caseId' => $case->id]).'#improvement-action-'.$planned->id,
            'detail' => 'Fristen 30. september 2026 er passert.',
        ], $category['items'][0]);
        // A tiltak finding never makes its case one.
        $this->assertSame(0, $this->overview($user, '2026-10-01')['case_total']);
    }

    public function test_a_case_without_owner_needs_attention(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $area, 'user' => $user] = $this->world();
        $this->improvementCase($customer, $area, 'Uten ansvarlig');

        $category = $this->category($this->overview($user), ImprovementAttentionService::CASE_OWNER_MISSING);
        $this->assertSame(['Uten ansvarlig'], array_column($category['items'], 'title'));
        $this->assertSame('Saken har ingen ansvarlig.', $category['items'][0]['detail']);
    }

    public function test_a_tiltak_without_owner_needs_attention_only_while_still_to_be_done(): void
    {
        $this->at('2026-10-02');
        ['user' => $user, 'case' => $case] = $this->world();
        $this->improvementAction($case, 'Planlagt uten ansvarlig');
        $started = $this->improvementAction($case, 'Startet uten ansvarlig');
        $this->lifecycle()->start($started, $user);
        $completed = $this->improvementAction($case, 'Fullført uten ansvarlig');
        $this->lifecycle()->complete($completed, $user, 'Gjort');
        $this->lifecycle()->verify($completed, $user, 'effective', 'Virket');
        $cancelled = $this->improvementAction($case, 'Avbrutt uten ansvarlig');
        $this->lifecycle()->cancel($cancelled, $user, 'Unødvendig');

        $category = $this->category($this->overview($user), ImprovementAttentionService::ACTION_OWNER_MISSING);
        $this->assertSame(['Planlagt uten ansvarlig', 'Startet uten ansvarlig'], array_column($category['items'], 'title'));
        $this->assertSame('Tiltaket har ingen ansvarlig.', $category['items'][0]['detail']);
    }

    public function test_a_completed_tiltak_awaits_verification_until_judged_and_not_effective_stays_a_finding(): void
    {
        $this->at('2026-10-02');
        ['user' => $user, 'case' => $case] = $this->world();
        $action = $this->improvementAction($case, 'Tiltak', $user);
        $this->lifecycle()->complete($action, $user, 'Gjort');

        $this->at('2026-10-05');
        $overview = $this->overview($user);
        $this->assertSame([ImprovementAttentionService::AWAITING_VERIFICATION], array_column($overview['categories'], 'key'));
        $this->assertSame('Tiltaket ble fullført 2. oktober 2026 og venter på effektverifisering.', $overview['categories'][0]['items'][0]['detail']);

        $this->lifecycle()->verify($action->fresh(), $user, 'not_effective', 'Feilen kom tilbake.');
        $overview = $this->overview($user);
        $this->assertSame([ImprovementAttentionService::NOT_EFFECTIVE], array_column($overview['categories'], 'key'));
        $this->assertSame('Siste effektverifisering konkluderte med at tiltaket ikke var effektivt.', $overview['categories'][0]['items'][0]['detail']);

        // A newer judgement of the same completion is the one that counts.
        $this->lifecycle()->verify($action->fresh(), $user, 'effective', 'Det var en annen feil.');
        $this->assertSame(['case_total' => 0, 'action_total' => 0, 'categories' => []], $this->overview($user));
    }

    public function test_not_effective_then_reopened_and_completed_again_awaits_a_new_verification(): void
    {
        $this->at('2026-10-02');
        ['user' => $user, 'case' => $case] = $this->world();
        $action = $this->improvementAction($case, 'Tiltak', $user, '2026-12-31');
        $this->lifecycle()->complete($action, $user, 'Gjort');
        $this->lifecycle()->verify($action->fresh(), $user, 'not_effective', 'Virket ikke.');

        // Reopened: still to be done, before its frist, with an owner — nothing to flag.
        $this->lifecycle()->reopen($action->fresh(), $user, 'Må arbeides videre med');
        $this->assertSame([], $this->overview($user)['categories']);

        // Completed again: the old judgement belongs to the old completion.
        $this->at('2026-10-04');
        $this->lifecycle()->complete($action->fresh(), $user, 'Gjort bedre');
        $overview = $this->overview($user);
        $this->assertSame([ImprovementAttentionService::AWAITING_VERIFICATION], array_column($overview['categories'], 'key'));
        $this->assertSame('Tiltaket ble fullført 4. oktober 2026 og venter på effektverifisering.', $overview['categories'][0]['items'][0]['detail']);
    }

    // ---------------------------------------------------------------------
    // Ended cases and counting
    // ---------------------------------------------------------------------

    public function test_an_ended_case_and_its_tiltak_give_no_findings(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $area, 'user' => $user] = $this->world();

        foreach ([ImprovementCase::STATUS_CLOSED, ImprovementCase::STATUS_CANCELLED] as $status) {
            $case = $this->improvementCase($customer, $area, "Avsluttet {$status}");
            $case->forceFill(['due_date' => '2026-01-01'])->save();
            // Old data, against today's rules: an ended case still holding unfinished tiltak.
            $this->improvementAction($case, 'Forsinket uten ansvarlig', null, '2026-01-01');
            $completed = $this->improvementAction($case, 'Fullført uten vurdering', $user);
            $this->lifecycle()->complete($completed, $user, 'Gjort');
            $case->forceFill(['status' => $status, 'closed_at' => now(), 'closing_note' => 'Eldre data'])->save();
        }

        $this->assertSame(['case_total' => 0, 'action_total' => 0, 'categories' => []], $this->overview($user));
        $this->assertNull(app(ImprovementAttentionService::class)->forCase($case->fresh()));
    }

    public function test_a_tiltak_with_several_findings_is_one_tiltak_and_a_case_with_several_is_one_case(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $area, 'user' => $user] = $this->world();
        $case = $this->improvementCase($customer, $area, 'Sak uten ansvarlig');
        $case->forceFill(['due_date' => '2026-09-01'])->save();
        $this->improvementAction($case, 'Forsinket uten ansvarlig', null, '2026-09-01');
        $this->improvementAction($case, 'Forsinket med ansvarlig', $user, '2026-09-01');

        $overview = $this->overview($user);
        $this->assertSame(1, $overview['case_total']);
        $this->assertSame(2, $overview['action_total']);
        $this->assertSame(
            ['case_overdue' => 1, 'action_overdue' => 2, 'case_owner_missing' => 1, 'action_owner_missing' => 1],
            array_column($overview['categories'], 'count', 'key'),
        );
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_counts_and_lists_come_from_the_visible_cases_only(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $area, 'user' => $user] = $this->world();
        $hidden = $this->area($customer, 'Økonomi');
        $secret = $this->improvementCase($customer, $hidden, 'Skjult sak');
        $this->improvementAction($secret, 'Skjult tiltak', null, '2026-01-01');

        $this->assertSame(['case_total' => 0, 'action_total' => 0, 'categories' => []], $this->overview($user));

        $props = $this->index($user);
        $this->assertSame('areas', $props['attention']['scope']);
        $this->assertSame([0, 0, []], [$props['attention']['case_total'], $props['attention']['action_total'], $props['attention']['categories']]);

        // «Alle» reaches the hidden area — and says it covers the whole organisation.
        $all = $this->member($customer);
        $this->grantAll($customer, $all, [CustomerPermissionCatalog::IMPROVEMENT_VIEW]);
        $this->assertSame([1, 1], [$this->overview($all)['case_total'], $this->overview($all)['action_total']]);
        $this->assertSame('all', $this->index($all)['attention']['scope']);

        // Attention needs view only.
        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hidden]);
        $this->assertSame(['Skjult sak'], array_column($this->category($this->overview($reader), ImprovementAttentionService::CASE_OWNER_MISSING)['items'], 'title'));
    }

    public function test_permission_and_area_from_different_roles_do_not_combine(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $hr] = $this->world();
        $finance = $this->area($customer, 'Økonomi');
        $this->improvementCase($customer, $hr, 'HR uten ansvarlig');
        $this->improvementCase($customer, $finance, 'Økonomi uten ansvarlig');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_EDIT, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$finance]);

        $items = $this->category($this->overview($user), ImprovementAttentionService::CASE_OWNER_MISSING)['items'];
        $this->assertSame(['HR uten ansvarlig'], array_column($items, 'title'));
    }

    public function test_system_owner_without_a_data_role_and_another_customer_see_no_counts(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'owner' => $systemOwner, 'area' => $area] = $this->world();
        $case = $this->improvementCase($customer, $area, 'Uten ansvarlig');
        $this->improvementAction($case, 'Uten ansvarlig', null, '2026-01-01');

        $this->assertSame(['case_total' => 0, 'action_total' => 0, 'categories' => []], $this->overview($systemOwner));
        $this->assertNull($this->index($systemOwner)['attention']);

        ['customer' => $other] = $this->context();
        $otherUser = $this->handler($other, $this->area($other, 'HR'));
        $this->assertSame(['case_total' => 0, 'action_total' => 0, 'categories' => []], $this->overview($otherUser));
    }

    // ---------------------------------------------------------------------
    // The case page and the register indicator
    // ---------------------------------------------------------------------

    public function test_the_case_page_carries_a_short_note(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $area, 'user' => $user, 'case' => $calm] = $this->world();
        $this->improvementAction($calm, 'I rute', $user, '2026-12-31');
        $this->assertNull($this->show($user, $calm)['attention']);

        $late = $this->improvementCase($customer, $area, 'Forsinket', $user);
        $late->forceFill(['due_date' => '2026-09-30'])->save();
        $this->improvementAction($late, 'Uten ansvarlig');
        $this->improvementAction($late, 'Forsinket', $user, '2026-09-01');
        $this->improvementAction($late, 'I rute', $user, '2026-12-31');

        $this->assertSame(
            ['action_count' => 2, 'reasons' => [['key' => 'case_overdue', 'detail' => 'Fristen 30. september 2026 er passert.']]],
            $this->show($user, $late)['attention'],
        );
    }

    public function test_the_register_names_how_many_tiltak_a_case_has_and_how_many_are_open(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $area, 'user' => $user, 'case' => $case] = $this->world();
        $this->improvementCase($customer, $area, 'Uten tiltak', $user);
        $this->improvementAction($case, 'Planlagt', $user);
        $started = $this->improvementAction($case, 'Startet', $user);
        $this->lifecycle()->start($started, $user);
        // Completed and not yet judged is not «open»: that is the attention panel's business.
        $completed = $this->improvementAction($case, 'Fullført', $user);
        $this->lifecycle()->complete($completed, $user, 'Gjort');
        $cancelled = $this->improvementAction($case, 'Avbrutt', $user);
        $this->lifecycle()->cancel($cancelled, $user, 'Unødvendig');

        $rows = collect($this->index($user)['cases'])->keyBy('title');
        $this->assertSame(['total' => 4, 'open' => 2], $rows['Sak']['action_summary']);
        $this->assertNull($rows['Uten tiltak']['action_summary']);
    }

    // ---------------------------------------------------------------------
    // Performance
    // ---------------------------------------------------------------------

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_tiltak(): void
    {
        $this->at('2026-10-02');
        ['customer' => $customer, 'area' => $area, 'user' => $user, 'case' => $case] = $this->world();
        $this->seedFindings($user, $case, 1);

        $few = $this->countQueries(fn () => $this->overview($user));
        // The register (list, indicator and panel) too, once warmed up.
        $this->index($user);
        $fewIndex = $this->countQueries(fn () => $this->index($user));

        foreach (range(1, 4) as $index) {
            $more = $this->improvementCase($customer, $area, "Sak {$index}");
            $this->seedFindings($user, $more, 3);
        }

        $many = $this->countQueries(fn () => $this->overview($user));

        $overview = $this->overview($user);
        $this->assertSame(4, $overview['case_total']);
        $this->assertSame(52, $overview['action_total']);
        $this->assertGreaterThan(0, $few);
        $this->assertSame($few, $many);

        // The register's indicator is counted in the list query itself.
        $this->assertSame($fewIndex, $this->countQueries(fn () => $this->index($user)));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Overdue, ownerless, awaiting and not effective tiltak — `$sets` of each. */
    private function seedFindings(User $user, ImprovementCase $case, int $sets): void
    {
        foreach (range(1, $sets) as $index) {
            $this->improvementAction($case, "Forsinket {$index}", $user, '2026-09-01');
            $this->improvementAction($case, "Uten ansvarlig {$index}");
            $awaiting = $this->improvementAction($case, "Venter {$index}", $user);
            $this->lifecycle()->complete($awaiting, $user, 'Gjort');
            $failed = $this->improvementAction($case, "Virket ikke {$index}", $user);
            $this->lifecycle()->complete($failed, $user, 'Gjort');
            $this->lifecycle()->verify($failed->fresh(), $user, 'not_effective', 'Virket ikke');
        }
    }

    /** @return array{customer: Customer, owner: User, area: BusinessArea, user: User, case: ImprovementCase} */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $area = $this->area($customer, 'HR');
        $user = $this->handler($customer, $area);
        $case = $this->improvementCase($customer, $area, 'Sak', $user);

        return ['customer' => $customer, 'owner' => $owner, 'area' => $area, 'user' => $user, 'case' => $case];
    }

    private function lifecycle(): ImprovementActionLifecycleService
    {
        return app(ImprovementActionLifecycleService::class);
    }

    private function at(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 10:00:00'));
    }

    /** @return array<string, mixed> */
    private function overview(User $user, ?string $today = null): array
    {
        return app(ImprovementAttentionService::class)->overview($user->fresh(), $today !== null ? CarbonImmutable::parse($today) : null);
    }

    /** @return array<string, mixed>|null */
    private function category(array $overview, string $key): ?array
    {
        return collect($overview['categories'])->firstWhere('key', $key);
    }

    /** @return array<string, mixed> */
    private function index(User $user): array
    {
        return $this->actingAs($user)->get('/app/improvements')->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function show(User $user, ImprovementCase $case): array
    {
        return $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
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
}
