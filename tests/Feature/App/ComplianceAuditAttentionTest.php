<?php

namespace Tests\Feature\App;

use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Compliance\ComplianceAuditAttentionService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Revisjoner → «Trenger oppmerksomhet».
 *
 * What these tests defend:
 *
 *  - Two reasons and no more: «Revisjon forfalt» (planned or in progress, planned end before today)
 *    and «Avvik uten oppfølging» (completed, with an avvik not handed off). Nothing is stored.
 *  - The lifecycle decides which reason can apply: completed is never overdue, cancelled never
 *    needs attention, and an audit under way again after a reopening no longer carries the
 *    completed-only reason.
 *  - Handing the avvik off clears the reason by itself — whatever the case's area or status.
 *  - The panel is the whole visible register, independent of search; the filter narrows the rows
 *    and combines with search, status and type. Only visible audits count; no N+1.
 */
class ComplianceAuditAttentionTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        DB::beginTransaction();
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(9, 0));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_planned_and_running_audits_are_overdue_only_after_the_end_date(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $service = app(ComplianceAuditAttentionService::class);

        $cases = [
            [ComplianceAudit::STATUS_PLANNED, '2026-11-14', ['overdue']],
            [ComplianceAudit::STATUS_IN_PROGRESS, '2026-11-14', ['overdue']],
            // The end date itself is not overdue.
            [ComplianceAudit::STATUS_PLANNED, '2026-11-15', []],
            [ComplianceAudit::STATUS_IN_PROGRESS, '2026-11-15', []],
            [ComplianceAudit::STATUS_IN_PROGRESS, '2026-12-01', []],
            // Completed and cancelled are never overdue.
            [ComplianceAudit::STATUS_COMPLETED, '2026-01-01', []],
            [ComplianceAudit::STATUS_CANCELLED, '2026-01-01', []],
        ];

        foreach ($cases as [$status, $end, $expected]) {
            $audit = $this->auditEnding($customer, $status, $end);
            $this->assertSame($expected, $service->reasonsForAudit($audit), "{$status} ending {$end}");
        }
    }

    public function test_only_an_avvik_not_handed_off_in_a_completed_audit_raises_the_finding_reason(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $service = app(ComplianceAuditAttentionService::class);

        $completed = $this->auditEnding($customer, ComplianceAudit::STATUS_COMPLETED, '2026-11-01');
        $this->finding($completed, ComplianceAuditFinding::TYPE_OBSERVATION);
        $this->finding($completed, ComplianceAuditFinding::TYPE_OPPORTUNITY);
        $this->assertSame([], $service->reasonsForAudit($completed), 'observations and opportunities never raise it');

        $avvik = $this->finding($completed, ComplianceAuditFinding::TYPE_NONCONFORMITY);
        $this->assertSame(['nonconformity_without_follow_up'], $service->reasonsForAudit($completed));

        // Only while completed: under way, or cancelled, it is not asked.
        foreach ([ComplianceAudit::STATUS_IN_PROGRESS, ComplianceAudit::STATUS_CANCELLED] as $status) {
            $other = $this->auditEnding($customer, $status, '2026-12-01');
            $this->finding($other, ComplianceAuditFinding::TYPE_NONCONFORMITY);
            $this->assertSame([], $service->reasonsForAudit($other), $status);
        }

        $this->assertNull($avvik->improvement_case_id);
    }

    public function test_handing_the_avvik_off_clears_the_reason_whatever_the_case(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $area = $this->area($customer, 'IT');
        $auditor = $this->complianceAuditor($customer);
        $this->grant($customer, $auditor, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);
        $audit = $this->auditEnding($customer, ComplianceAudit::STATUS_COMPLETED, '2026-11-01');
        $first = $this->finding($audit, ComplianceAuditFinding::TYPE_NONCONFORMITY);
        $second = $this->finding($audit, ComplianceAuditFinding::TYPE_NONCONFORMITY);

        $this->assertSame(['nonconformity_without_follow_up'], $this->showProps($auditor, $audit)['attention']);

        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/findings/{$first->id}/handoff", [
            'title' => $first->title, 'description' => $first->description, 'business_area_id' => $area->id, 'owner_user_id' => $auditor->id,
        ])->assertSessionHasNoErrors();
        // One avvik still open.
        $this->assertSame(['nonconformity_without_follow_up'], $this->showProps($auditor, $audit)['attention']);

        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/findings/{$second->id}/handoff", [
            'title' => $second->title, 'description' => $second->description, 'business_area_id' => $area->id, 'owner_user_id' => $auditor->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame([], $this->showProps($auditor, $audit)['attention']);

        // The audit itself was not touched by it.
        $this->assertSame(ComplianceAudit::STATUS_COMPLETED, $audit->fresh()->status);

        // A cancelled case still counts as followed up: the signal never reads the case.
        $case = ImprovementCase::query()->findOrFail($first->fresh()->improvement_case_id);
        $case->forceFill(['status' => 'cancelled', 'closed_at' => now(), 'closing_note' => 'Dekket av annen sak.'])->save();
        $this->assertSame([], $this->showProps($auditor, $audit)['attention']);
    }

    public function test_a_reopened_audit_drops_the_completed_only_reason_until_completed_again(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->auditEnding($customer, ComplianceAudit::STATUS_IN_PROGRESS, '2026-12-01');
        $this->finding($audit, ComplianceAuditFinding::TYPE_NONCONFORMITY);
        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/complete", ['conclusion' => 'Ett avvik.'])->assertSessionHasNoErrors();
        $this->assertSame(['nonconformity_without_follow_up'], $this->showProps($auditor, $audit)['attention']);

        $this->actingAs($auditor)->post("/app/compliance/audits/{$audit->id}/reopen", ['reason' => 'Rette funn.'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->showProps($auditor, $audit)['attention']);

        // Under way again and past its end: overdue — never both reasons at once.
        $audit->forceFill(['planned_end_date' => '2026-11-10'])->save();
        $this->assertSame(['overdue'], $this->showProps($auditor, $audit)['attention']);
    }

    public function test_the_register_lists_the_flagged_audits_and_the_filter_combines_with_the_others(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $overdue = $this->auditEnding($customer, ComplianceAudit::STATUS_PLANNED, '2026-11-01', 'Forfalt internrevisjon');
        $unhandled = $this->auditEnding($customer, ComplianceAudit::STATUS_COMPLETED, '2026-10-01', 'Ekstern leverandørrevisjon', ComplianceAudit::TYPE_EXTERNAL);
        $this->finding($unhandled, ComplianceAuditFinding::TYPE_NONCONFORMITY);
        $fine = $this->auditEnding($customer, ComplianceAudit::STATUS_IN_PROGRESS, '2026-12-01', 'Ryddig internrevisjon');
        $this->auditEnding($customer, ComplianceAudit::STATUS_CANCELLED, '2026-01-01', 'Avbrutt internrevisjon');

        $props = $this->indexProps($reader);
        $this->assertSame(2, $props['attention']['total']);
        $this->assertSame([$unhandled->id, $overdue->id], array_column($props['attention']['audits'], 'id'));
        $this->assertSame([['nonconformity_without_follow_up'], ['overdue']], array_column($props['attention']['audits'], 'reasons'));
        $this->assertSame(route('app.compliance.audits.show', ['auditId' => $overdue->id]), $props['attention']['audits'][1]['url']);
        $this->assertSame(['overdue', 'nonconformity_without_follow_up'], $props['attention_reasons']);
        $rows = array_column($props['audits'], 'attention', 'id');
        $this->assertSame([], $rows[$fine->id]);
        $this->assertSame(['overdue'], $rows[$overdue->id]);

        // The panel is the register's worklist: a search does not shrink it.
        $searched = $this->indexProps($reader, '?search=Ryddig');
        $this->assertSame([$fine->id], array_column($searched['audits'], 'id'));
        $this->assertSame(2, $searched['attention']['total']);

        // The filter narrows the rows, and combines with search, status and type.
        $this->assertSame([$overdue->id, $unhandled->id], array_column($this->indexProps($reader, '?attention=1')['audits'], 'id'));
        $this->assertSame([$overdue->id], array_column($this->indexProps($reader, '?attention=1&search=internrevisjon')['audits'], 'id'));
        $this->assertSame([$unhandled->id], array_column($this->indexProps($reader, '?attention=1&status=completed')['audits'], 'id'));
        $this->assertSame([$unhandled->id], array_column($this->indexProps($reader, '?attention=1&type=external')['audits'], 'id'));
        $this->assertSame([], $this->indexProps($reader, '?attention=1&status=cancelled')['audits']);
        $this->assertTrue($this->indexProps($reader, '?attention=1')['filters']['attention']);
        $this->assertFalse($this->indexProps($reader)['filters']['attention']);
    }

    public function test_only_visible_audits_count_and_nothing_crosses_customers(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        ['customer' => $foreign] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $this->auditEnding($foreign, ComplianceAudit::STATUS_PLANNED, '2026-01-01', 'Fremmed forfalt revisjon');
        $foreignCompleted = $this->auditEnding($foreign, ComplianceAudit::STATUS_COMPLETED, '2026-01-01', 'Fremmed fullført revisjon');
        $this->finding($foreignCompleted, ComplianceAuditFinding::TYPE_NONCONFORMITY);
        $own = $this->auditEnding($customer, ComplianceAudit::STATUS_IN_PROGRESS, '2026-11-01', 'Egen revisjon');

        $props = $this->indexProps($reader);
        $this->assertSame(1, $props['attention']['total']);
        $this->assertSame([$own->id], array_column($props['attention']['audits'], 'id'));
        $this->assertStringNotContainsString('Fremmed', json_encode([$props['audits'], $props['attention']]));

        // Without compliance.view: nothing at all.
        $this->actingAs($this->complianceMember($customer))->get('/app/compliance/audits')->assertForbidden();
        // System Owner without a role of their own: no implicit access.
        $this->actingAs($this->complianceContext()['owner'])->get('/app/compliance/audits')->assertForbidden();
    }

    public function test_the_register_reads_findings_once_whatever_the_number_of_audits(): void
    {
        ['customer' => $customer] = $this->complianceContext();
        $reader = $this->complianceReader($customer);
        $this->indexProps($reader);

        $count = function () use ($reader): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->indexProps($reader);
            $queries = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            return $queries->filter(fn (string $sql): bool => str_contains($sql, 'compliance_audit_findings'))->count();
        };

        for ($i = 0; $i < 2; $i++) {
            $audit = $this->auditEnding($customer, ComplianceAudit::STATUS_COMPLETED, '2026-10-01', "Revisjon {$i}");
            $this->finding($audit, ComplianceAuditFinding::TYPE_NONCONFORMITY);
        }
        $few = $count();

        for ($i = 2; $i < 12; $i++) {
            $audit = $this->auditEnding($customer, ComplianceAudit::STATUS_COMPLETED, '2026-10-01', "Revisjon {$i}");
            $this->finding($audit, ComplianceAuditFinding::TYPE_NONCONFORMITY);
        }
        $many = $count();

        $this->assertSame(1, $few);
        $this->assertSame($few, $many);
        $this->assertSame(12, $this->indexProps($reader)['attention']['total']);
    }

    private function auditEnding($customer, string $status, string $end, string $title = 'Internrevisjon', string $type = ComplianceAudit::TYPE_INTERNAL): ComplianceAudit
    {
        $audit = $this->complianceAudit($customer, null, $title, $status, $type);
        $audit->forceFill(['planned_start_date' => null, 'planned_end_date' => $end])->save();

        return $audit->fresh();
    }

    private function finding(ComplianceAudit $audit, string $type): ComplianceAuditFinding
    {
        return ComplianceAuditFinding::query()->create([
            'customer_id' => $audit->customer_id,
            'audit_id' => $audit->id,
            'finding_type' => $type,
            'title' => 'Funn',
            'description' => 'Beskrivelse.',
        ]);
    }

    /** @return array<string, mixed> */
    private function indexProps(User $user, string $query = ''): array
    {
        return $this->actingAs($user)->get('/app/compliance/audits'.$query)->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function showProps(User $user, ComplianceAudit $audit): array
    {
        return $this->actingAs($user)->get("/app/compliance/audits/{$audit->id}")->assertOk()->viewData('page')['props'];
    }
}
