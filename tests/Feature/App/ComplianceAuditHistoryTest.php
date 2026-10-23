<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceAuditFindingChange;
use App\Models\Customer;
use App\Models\User;
use App\Services\Compliance\ComplianceAuditFindingService;
use App\Services\Compliance\ComplianceAuditLifecycleService;
use App\Services\Compliance\ComplianceAuditService;
use App\Services\Security\ActorChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A34 (docs/external-api-phase-0-plan.md §2i): what a completed audit concluded and found stays,
 * while a reopened audit is still worked as before.
 *
 * Every Fullfør keeps its conclusion on its own immutable status change. A finding that was there
 * when the audit was completed is corrected after a reopening, never deleted — the service and the
 * database refuse it — and each correction keeps the values before, who and when. A finding
 * recorded after the reopening is worked exactly as before.
 */
class ComplianceAuditHistoryTest extends TestCase
{
    use CreatesComplianceScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    public function test_every_completion_keeps_its_conclusion_and_the_reopened_audit_is_edited_as_before(): void
    {
        ['auditor' => $auditor, 'audit' => $audit] = $this->completedAudit('Første konklusjon.');
        $lifecycle = app(ComplianceAuditLifecycleService::class);

        $lifecycle->reopen($audit, $auditor, 'Ett funn må rettes.');
        // Rediger as before: title and conclusion are open again.
        app(ComplianceAuditService::class)->update($auditor, $audit->fresh(), ['title' => 'Internrevisjon, rettet', 'conclusion' => 'Utkast til ny konklusjon.'] + $this->auditFields($auditor));
        $this->assertSame('Internrevisjon, rettet', $audit->fresh()->title);
        $lifecycle->complete($audit->fresh(), $auditor, 'Andre konklusjon.');

        $this->assertSame('Andre konklusjon.', $audit->fresh()->conclusion);
        $this->assertSame(
            ['Første konklusjon.', 'Andre konklusjon.'],
            $audit->statusChanges()->reorder('id')->where('to_status', ComplianceAudit::STATUS_COMPLETED)->pluck('conclusion')->all(),
        );
        $this->assertSame([null, null], $audit->statusChanges()->where('to_status', '!=', ComplianceAudit::STATUS_COMPLETED)->pluck('conclusion')->all());

        $completion = $audit->statusChanges()->reorder('id')->where('to_status', ComplianceAudit::STATUS_COMPLETED)->firstOrFail();
        $this->assertRefused(fn () => DB::table('compliance_audit_status_changes')->where('id', $completion->id)->update(['conclusion' => 'Omskrevet']), 'rewriting a conclusion');
    }

    public function test_a_finding_from_a_completed_audit_is_never_deleted_but_can_be_corrected_with_its_history(): void
    {
        ['auditor' => $auditor, 'audit' => $audit, 'finding' => $finding] = $this->completedAudit();
        $findings = app(ComplianceAuditFindingService::class);
        app(ComplianceAuditLifecycleService::class)->reopen($audit, $auditor, 'Ett funn må rettes.');
        $audit = $audit->fresh();

        // The page offers no Slett for it, and the service and the database refuse it.
        $row = collect($this->actingAs($auditor)->get("/app/compliance/audits/{$audit->id}")->assertOk()->viewData('page')['props']['findings'])->firstWhere('id', $finding->id);
        $this->assertTrue($row['permissions']['can_edit']);
        $this->assertFalse($row['permissions']['can_delete']);
        $this->actingAs($auditor)->delete("/app/compliance/audits/{$audit->id}/findings/{$finding->id}")
            ->assertSessionHasErrors(['finding' => __('procynia.compliance.audits.findings.validation.completed_kept')]);
        try {
            $findings->delete($auditor, $audit, $finding);
            $this->fail('the service refuses');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertRefused(fn () => DB::table('compliance_audit_findings')->where('id', $finding->id)->delete(), 'deleting in SQL');
        $this->assertNotNull(ComplianceAuditFinding::query()->find($finding->id));

        // A save that changes nothing leaves no trace; a correction keeps the values before.
        $findings->update($auditor, $audit, $finding, $this->findingInput('Tilganger fjernes ikke'));
        $this->assertSame(0, ComplianceAuditFindingChange::query()->where('finding_id', $finding->id)->count());

        $this->travel(1)->minutes();
        $findings->update($auditor, $audit, $finding->fresh(), $this->findingInput('Tilganger fjernes for sent', ComplianceAuditFinding::TYPE_OBSERVATION));

        $change = ComplianceAuditFindingChange::query()->where('finding_id', $finding->id)->sole();
        $this->assertSame((int) $auditor->id, (int) $change->changed_by_user_id);
        $this->assertNotNull($change->changed_at);
        $this->assertSame('Tilganger fjernes ikke', $change->previous['title']);
        $this->assertSame(ComplianceAuditFinding::TYPE_NONCONFORMITY, $change->previous['finding_type']);
        $this->assertSame('Tilganger fjernes for sent', $finding->fresh()->title);

        // The history itself can be neither changed nor deleted.
        $this->assertRefused(fn () => DB::table('compliance_audit_finding_changes')->where('id', $change->id)->update(['previous' => json_encode(['title' => 'x'])]), 'rewriting a correction');
        $this->assertRefused(fn () => DB::table('compliance_audit_finding_changes')->where('id', $change->id)->delete(), 'deleting a correction');
    }

    /** A finding recorded after the reopening is worked exactly as before: no history, deletable. */
    public function test_a_finding_recorded_after_reopening_is_worked_as_before(): void
    {
        ['auditor' => $auditor, 'audit' => $audit] = $this->completedAudit();
        app(ComplianceAuditLifecycleService::class)->reopen($audit, $auditor, 'Mer å se på.');
        $this->travel(1)->minutes();
        $findings = app(ComplianceAuditFindingService::class);

        $late = $findings->create($auditor, $audit->fresh(), $this->findingInput('Nytt funn'));
        $findings->update($auditor, $audit->fresh(), $late, $this->findingInput('Nytt funn, rettet'));
        $this->assertSame(0, ComplianceAuditFindingChange::query()->where('finding_id', $late->id)->count());

        $findings->delete($auditor, $audit->fresh(), $late->fresh());
        $this->assertNull(ComplianceAuditFinding::query()->find($late->id));
    }

    /** Correcting a kept finding is audit work: no integration, no API call, no other customer. */
    public function test_a_kept_finding_is_corrected_only_by_a_person_of_the_audits_customer(): void
    {
        ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit, 'finding' => $finding] = $this->completedAudit();
        app(ComplianceAuditLifecycleService::class)->reopen($audit, $auditor, 'Ett funn må rettes.');
        $audit = $audit->fresh();
        $findings = app(ComplianceAuditFindingService::class);

        $integration = $this->complianceAuditor($customer);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();
        ['customer' => $other] = $this->complianceContext();

        $attempts = [
            'integration' => [fn () => $findings->update($integration->fresh(), $audit, $finding, $this->findingInput('Endret av maskin')), HumanDecisionRefused::class],
            'other customer' => [fn () => $findings->update($this->complianceAuditor($other), $audit, $finding, $this->findingInput('Endret utenfra')), AuthorizationException::class],
            'reader' => [fn () => $findings->update($this->complianceReader($customer), $audit, $finding, $this->findingInput('Endret av leser')), AuthorizationException::class],
        ];

        foreach ($attempts as $who => [$attempt, $refusal]) {
            try {
                $attempt();
                $this->fail("{$who} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, $who);
            }
        }

        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });
        try {
            app(ComplianceAuditFindingService::class)->update($auditor, $audit, $finding, $this->findingInput('Endret via API'));
            $this->fail('the API is refused');
        } catch (HumanDecisionRefused) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('Tilganger fjernes ikke', $finding->fresh()->title);
        $this->assertSame(0, ComplianceAuditFindingChange::query()->count());
    }

    public function test_deleting_the_customer_still_takes_everything_along(): void
    {
        ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit, 'finding' => $finding] = $this->completedAudit();
        app(ComplianceAuditLifecycleService::class)->reopen($audit, $auditor, 'Ett funn må rettes.');
        app(ComplianceAuditFindingService::class)->update($auditor, $audit->fresh(), $finding, $this->findingInput('Rettet'));

        DB::table('customers')->where('id', $customer->id)->delete();

        foreach (['compliance_audits', 'compliance_audit_findings', 'compliance_audit_finding_changes', 'compliance_audit_status_changes'] as $table) {
            $this->assertSame(0, DB::table($table)->where('customer_id', $customer->id)->count(), $table);
        }
    }

    /** @return array{customer: Customer, auditor: User, audit: ComplianceAudit, finding: ComplianceAuditFinding} */
    private function completedAudit(string $conclusion = 'Ingen vesentlige avvik.'): array
    {
        ['customer' => $customer] = $this->complianceContext();
        $auditor = $this->complianceAuditor($customer);
        $audit = $this->complianceAudit($customer, $auditor);
        $lifecycle = app(ComplianceAuditLifecycleService::class);
        $lifecycle->start($audit, $auditor);
        $finding = app(ComplianceAuditFindingService::class)->create($auditor, $audit->fresh(), $this->findingInput('Tilganger fjernes ikke'));
        $lifecycle->complete($audit->fresh(), $auditor, $conclusion);

        return ['customer' => $customer, 'auditor' => $auditor, 'audit' => $audit->fresh(), 'finding' => $finding->fresh()];
    }

    /** @return array<string, mixed> */
    private function auditFields(User $responsible): array
    {
        return ['responsible_user_id' => (int) $responsible->id, 'planned_end_date' => '2026-11-15'];
    }

    /** @return array<string, mixed> */
    private function findingInput(string $title, string $type = ComplianceAuditFinding::TYPE_NONCONFORMITY): array
    {
        return [
            'finding_type' => $type,
            'title' => $title,
            'description' => 'Tre av ti sluttede brukere hadde fortsatt aktiv tilgang.',
            'requirement_id' => null,
            'check_id' => null,
            'quality_process_id' => null,
            'control_item_id' => null,
        ];
    }

    private function assertRefused(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("the database must refuse {$what}");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }
}
