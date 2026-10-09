<?php

namespace Tests\Feature\App;

use App\Models\CustomerPackageEntitlement;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\SavedNoticeInfoItem;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\MyTasks\MyTasksService;
use App\Services\Suppliers\SupplierAttentionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\ReadsMyTasks;
use Tests\TestCase;

/**
 * Punkt 6B: Leverandører in «Mine oppgaver».
 *
 * One task per supplier the person is intern ansvarlig for, carrying every reason
 * SupplierAttentionService gives it — never one task per signal. Nothing is stored: the task follows
 * the supplier's own data and owner field on every read, so fixing the last reason retires it and
 * reassigning the supplier moves it.
 */
class InfoCenterSupplierTaskTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use DatabaseTransactions;
    use ReadsMyTasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        // A Wednesday.
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_several_signals_are_one_task_for_the_owner(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $supplier = $this->supplier($customer, $owner, 'Stangeland Maskin AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $this->document($supplier, 'Forsikring 2025', '2026-10-06');
        $this->document($supplier, 'ISO 27001', '2026-11-20');

        $tasks = $this->supplierTasksFor($owner);

        $this->assertCount(1, $tasks);
        $task = $tasks[0];
        $this->assertSame('supplier-'.$supplier->id, $task['id']);
        $this->assertSame('supplier', $task['module']);
        $this->assertSame('Stangeland Maskin AS', $task['title']);
        $this->assertSame(['not_assessed', 'document_expired', 'document_expiring'], array_column($task['reasons'], 'key'));
        $this->assertSame(['Forsikring 2025', 'ISO 27001'], array_values(array_filter(array_column($task['reasons'], 'document_title'))));
        // The earliest date it is about, and overdue because a document already ran out.
        $this->assertSame('2026-10-06', $task['due_on']);
        $this->assertSame('overdue', $task['group']);
        $this->assertSame("/app/supplier-management/{$supplier->id}", $task['action_url']);
        $this->assertTrue($task['can_act']);
        $this->assertSame(1, $this->myTasksCount($owner));
    }

    public function test_the_groups_follow_the_supplier_dates(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $thisWeek = $this->supplier($customer, $owner, 'A Denne uken AS');
        $this->document($thisWeek, 'Forsikring', '2026-10-11');
        $later = $this->supplier($customer, $owner, 'B Senere AS');
        // Inside the 60 days the module warns about, so still a task — just not this week.
        $this->document($later, 'Sertifikat', '2026-12-06');
        $undated = $this->supplier($customer, $owner, 'C Uten frist AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 12));

        $groups = collect($this->infoCenterFor($owner)['my_tasks']['groups'])
            ->mapWithKeys(fn (array $group): array => [$group['key'] => array_column($group['tasks'], 'title')])
            ->all();

        $this->assertSame([
            'overdue' => [],
            'this_week' => ['A Denne uken AS'],
            'later' => ['B Senere AS'],
            'no_due' => ['C Uten frist AS'],
        ], $groups);
        $this->assertSame([$undated->id], array_map(fn (string $id): int => (int) Str::after($id, 'supplier-'), array_column(array_filter($this->supplierTasksFor($owner), fn (array $task): bool => $task['due_on'] === null), 'id')));
    }

    public function test_the_task_follows_the_data_and_goes_when_the_need_is_resolved(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $supplier = $this->supplier($customer, $owner, 'Drift AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 12));
        $document = $this->document($supplier, 'Forsikring 2025', '2026-10-06');

        $this->assertSame(['not_assessed', 'document_expired'], array_column($this->supplierTasksFor($owner)[0]['reasons'], 'key'));

        $this->assess($supplier, '2026-10-01');
        $this->assertSame(['document_expired'], array_column($this->supplierTasksFor($owner)[0]['reasons'], 'key'));

        // Registrer fornyet: the old row is replaced, the renewal valid for years.
        $renewal = $this->document($supplier, 'Forsikring 2026', '2029-10-06');
        $document->forceFill(['replaced_by_document_id' => $renewal->id])->save();

        $this->assertSame([], $this->supplierTasksFor($owner));
        $this->assertSame(0, $this->myTasksCount($owner));
    }

    public function test_reassigning_the_supplier_moves_the_task(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $first = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $second = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $first, 'Drift AS');
        $this->document($supplier, 'Forsikring', '2026-10-06');

        $this->assertCount(1, $this->supplierTasksFor($first));
        $this->assertSame([], $this->supplierTasksFor($second));

        $this->actingAs($first)
            ->patch("/app/supplier-management/{$supplier->id}", $this->supplierPayload($second, ['name' => 'Drift AS']))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->supplierTasksFor($first));
        $this->assertCount(1, $this->supplierTasksFor($second));
    }

    public function test_only_the_owner_sees_it_and_an_ended_supplier_is_no_task(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $colleague = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->document($this->supplier($customer, $owner, 'Aktiv AS'), 'Forsikring', '2026-10-06');
        $this->document($this->supplier($customer, $owner, 'Avsluttet AS', Supplier::STATUS_ENDED), 'Forsikring', '2026-10-06');

        $this->assertSame(['Aktiv AS'], array_column($this->supplierTasksFor($owner), 'title'));
        $this->assertSame([], $this->supplierTasksFor($colleague));
    }

    public function test_no_task_without_supplier_view_or_without_the_module(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $owner, 'Hemmelig Leverandor AS');
        $this->document($supplier, 'Forsikring', '2026-10-06');

        $this->assertCount(1, $this->supplierTasksFor($owner));

        CustomerPackageEntitlement::query()
            ->where('customer_id', $customer->id)
            ->whereIn('package_key', ['supplier', 'grc'])
            ->update(['status' => CustomerPackageEntitlement::STATUS_REVOKED]);
        $this->assertSame([], $this->supplierTasksFor($owner->fresh()));

        CustomerPackageEntitlement::query()
            ->where('customer_id', $customer->id)
            ->whereIn('package_key', ['supplier', 'grc'])
            ->update(['status' => CustomerPackageEntitlement::STATUS_ACTIVE]);
        $owner->customerRoles()->detach();

        $response = $this->actingAs($owner->fresh())->get(route('app.info-center.index', ['view' => 'my_tasks']))->assertOk();
        $this->assertSame([], $this->myTasksIn($response->viewData('page')['props']['infoCenter'], 'supplier'));
        $this->assertStringNotContainsString('Hemmelig Leverandor AS', $response->getContent());
    }

    public function test_a_supplier_of_another_customer_never_becomes_a_task(): void
    {
        ['customer' => $customer] = $this->context('grc');
        ['customer' => $other] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $outsider = $this->supplierUser($other, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $supplier = $this->supplier($customer, $owner, 'Drift AS');
        $this->document($supplier, 'Forsikring', '2026-10-06');
        // A corrupt owner pointing across the boundary still reaches nothing.
        Supplier::query()->whereKey($supplier->id)->update(['owner_user_id' => $outsider->id]);

        $this->assertSame([], $this->supplierTasksFor($outsider));
        $this->assertSame([], $this->supplierTasksFor($owner));
    }

    public function test_a_reader_without_the_action_permission_still_sees_the_task_and_is_told(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $reader, 'Drift AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $this->document($supplier, 'Forsikring', '2026-10-06');

        $task = $this->supplierTasksFor($reader)[0];

        $this->assertFalse($task['can_act']);
        $this->assertSame(['not_assessed' => false, 'document_expired' => false], array_column($task['reasons'], 'can_act', 'key'));

        // supplier.assess covers the assessment and nothing else.
        $assessor = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        Supplier::query()->whereKey($supplier->id)->update(['owner_user_id' => $assessor->id]);

        $this->assertSame(['not_assessed' => true, 'document_expired' => false], array_column($this->supplierTasksFor($assessor)[0]['reasons'], 'can_act', 'key'));
    }

    public function test_supplier_and_anbud_work_share_one_list_and_one_count(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->document($this->supplier($customer, $owner, 'Drift AS'), 'Forsikring', '2026-10-06');
        $this->aksjon($owner, 'Avklar kontraktsvilkår');

        $infoCenter = $this->infoCenterFor($owner);

        $this->assertSame(['supplier', 'tender'], array_column($this->myTasksIn($infoCenter), 'module'));
        $this->assertSame(2, $this->myTasksCount($owner));
        $this->assertSame('my_tasks', $infoCenter['default_view']);
    }

    /**
     * The task's reasons are SupplierAttentionService's findings, one for one and in its order —
     * Leverandørkontroll signals included. Nothing is decided again in the task source.
     */
    public function test_the_reasons_are_exactly_the_attention_findings(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_ASSURE]);
        SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Databehandleravtale', 'theme' => 'privacy', 'level' => 'mandatory',
            'control_point' => 'before_contract', 'applies_when' => [],
        ]);
        $suppliers = [
            $this->supplier($customer, $owner, 'Kritisk AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12)),
            $this->supplier($customer, $owner, 'Viktig AS', classification: $this->classification(Supplier::CRITICALITY_IMPORTANT, 6)),
            $this->supplier($customer, $owner, 'Under vurdering AS', Supplier::STATUS_ONBOARDING, $this->classification(Supplier::CRITICALITY_CRITICAL, 12)),
        ];
        $this->assess($suppliers[1], '2026-01-01');
        $this->document($suppliers[0], 'Forsikring', '2026-10-06');
        $this->document($suppliers[2], 'Sertifikat', '2026-11-01');

        $tasks = collect($this->supplierTasksFor($owner))->keyBy('title');
        $attention = app(SupplierAttentionService::class);

        foreach ($suppliers as $supplier) {
            $findings = $attention->findingsForSupplier($supplier->fresh());
            $this->assertNotSame([], $findings, $supplier->name.' should need follow-up in this scenario');
            $this->assertSame(array_column($findings, 'key'), array_column($tasks[$supplier->name]['reasons'], 'key'), $supplier->name);
        }

        // Every reason is one the person can act on — so nothing says «Mangler rettighet».
        $this->assertSame([true], $tasks->pluck('reasons')->flatten(1)->pluck('can_act')->unique()->values()->all());
        $this->assertSame([true], $tasks->pluck('can_act')->unique()->values()->all());
    }

    public function test_without_assure_only_the_control_reasons_say_the_permission_is_missing(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT, CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id, 'title' => 'Databehandleravtale', 'theme' => 'privacy', 'level' => 'mandatory',
            'control_point' => 'before_contract', 'applies_when' => [],
        ]);
        $supplier = $this->supplier($customer, $owner, 'Kritisk AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));
        $this->document($supplier, 'Forsikring', '2026-10-06');

        $reasons = collect($this->supplierTasksFor($owner)[0]['reasons']);
        $controlKeys = [SupplierAttentionService::DECISION_REQUIRED, SupplierAttentionService::REQUIREMENT_NOT_EVALUATED, SupplierAttentionService::CONTROL_OVERDUE];

        $this->assertNotEmpty($reasons->whereIn('key', $controlKeys));
        $this->assertSame([false], $reasons->whereIn('key', $controlKeys)->pluck('can_act')->unique()->values()->all());
        $this->assertSame([true], $reasons->whereNotIn('key', $controlKeys)->whereNotIn('key', ['profile_incomplete', 'due_diligence_missing'])->pluck('can_act')->unique()->values()->all());
    }

    /**
     * The task follows the person's rights as they change, and showing it widens nothing: the action
     * itself is still refused by the module.
     */
    public function test_rights_are_read_live_and_the_backend_still_refuses_the_action(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $reader = $this->supplierUser($customer, []);
        $supplier = $this->supplier($customer, $reader, 'Kritisk AS', classification: $this->classification(Supplier::CRITICALITY_CRITICAL, 12));

        $this->assertSame(['not_assessed' => false], array_column($this->supplierTasksFor($reader)[0]['reasons'], 'can_act', 'key'));
        $this->actingAs($reader)->post("/app/supplier-management/{$supplier->id}/assessments", [])->assertForbidden();

        $this->grantAll($customer, $reader, [CustomerPermissionCatalog::SUPPLIER_VIEW, CustomerPermissionCatalog::SUPPLIER_ASSESS]);
        $task = $this->supplierTasksFor($reader->fresh())[0];
        $this->assertTrue($task['can_act']);
        $this->assertSame(['not_assessed' => true], array_column($task['reasons'], 'can_act', 'key'));
    }

    public function test_a_deactivated_owner_has_no_tasks(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $this->document($this->supplier($customer, $owner, 'Drift AS'), 'Forsikring', '2026-10-06');
        $this->assertCount(1, $this->supplierTasksFor($owner));

        $owner->forceFill(['is_active' => false])->save();

        $this->assertSame(0, app(MyTasksService::class)->tasksFor($owner->fresh(), (int) $customer->id)->count());
        // Nor can they reach the page at all.
        $this->actingAs($owner->fresh())->get(route('app.info-center.index'))->assertStatus(403);
    }

    public function test_the_task_list_reads_suppliers_in_batches_not_one_query_per_supplier(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);
        $service = app(MyTasksService::class);

        $queriesFor = function (int $count) use ($customer, $owner, $service): int {
            foreach (range(1, $count) as $i) {
                $this->document($this->supplier($customer, $owner, 'Leverandør '.uniqid()), 'Forsikring', '2026-10-06');
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $tasks = $service->tasksFor($owner->fresh(), (int) $customer->id);
            DB::disableQueryLog();
            $this->assertGreaterThanOrEqual($count, $tasks->where('module', 'supplier')->count());

            return count(DB::getQueryLog());
        };

        $this->assertSame($queriesFor(2), $queriesFor(10));
    }

    public function test_with_nothing_assigned_the_page_shows_the_empty_state(): void
    {
        ['customer' => $customer] = $this->context('grc');
        $owner = $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]);

        $infoCenter = $this->infoCenterFor($owner);

        $this->assertSame(0, $infoCenter['my_tasks']['count']);
        $this->assertSame(['overdue', 'this_week', 'later', 'no_due'], array_column($infoCenter['my_tasks']['groups'], 'key'));
        $this->assertSame([[], [], [], []], array_column($infoCenter['my_tasks']['groups'], 'tasks'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function infoCenterFor(User $user): array
    {
        return $this->actingAs($user)
            ->get(route('app.info-center.index'))
            ->assertOk()
            ->viewData('page')['props']['infoCenter'];
    }

    /** @return list<array<string, mixed>> */
    private function supplierTasksFor(User $user): array
    {
        return $this->myTasksIn($this->infoCenterFor($user), 'supplier');
    }

    private function myTasksCount(User $user): int
    {
        return (int) collect($this->infoCenterFor($user)['summary']['items'])->firstWhere('key', 'my_tasks')['count'];
    }

    private function assess(Supplier $supplier, string $on): void
    {
        SupplierAssessment::query()->create([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id, 'assessed_on' => $on,
            'quality_rating' => 'good', 'delivery_rating' => 'good', 'security_rating' => 'good', 'compliance_rating' => 'good',
            'overall_result' => 'satisfactory', 'rationale' => 'Stabile leveranser.', 'supplier_name' => $supplier->name,
            'criticality' => $supplier->criticality, 'review_interval_months' => $supplier->review_interval_months, 'recorded_at' => now(),
        ]);
    }

    private function document(Supplier $supplier, string $title, ?string $validUntil): SupplierDocument
    {
        return SupplierDocument::query()->create([
            'customer_id' => $supplier->customer_id, 'supplier_id' => $supplier->id,
            'document_type' => 'insurance_certificate', 'title' => $title, 'valid_until' => $validUntil,
        ]);
    }

    private function aksjon(User $owner, string $subject): void
    {
        $reference = Str::upper(Str::random(10));
        Notice::query()->firstOrCreate(['notice_id' => $reference], ['title' => 'Kunngjøring '.$reference, 'source' => 'doffin']);
        $notice = SavedNotice::query()->create([
            'customer_id' => $owner->customer_id,
            'external_id' => $reference,
            'title' => 'Anskaffelse '.$reference,
            'buyer_name' => 'Testetaten',
            'bid_status' => SavedNotice::BID_STATUS_IN_PROGRESS,
            'bid_manager_user_id' => $owner->id,
        ]);

        (new SavedNoticeInfoItem)->forceFill([
            'saved_notice_id' => $notice->id,
            'type' => SavedNoticeInfoItem::TYPE_MESSAGE,
            'direction' => SavedNoticeInfoItem::DIRECTION_INTERNAL,
            'channel' => SavedNoticeInfoItem::CHANNEL_MANUAL,
            'subject' => $subject,
            'body' => $subject,
            'status' => SavedNoticeInfoItem::STATUS_OPEN,
            'requires_response' => false,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ])->save();
    }
}
