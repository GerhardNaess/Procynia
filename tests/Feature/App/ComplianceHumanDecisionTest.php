<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Services\Compliance\ComplianceAssessmentService;
use App\Services\Compliance\ComplianceAuditCheckService;
use App\Services\Compliance\ComplianceAuditFindingHandoffService;
use App\Services\Compliance\ComplianceAuditFindingService;
use App\Services\Compliance\ComplianceAuditLifecycleService;
use App\Services\Compliance\ComplianceAuditScopeService;
use App\Services\Compliance\ComplianceAuditService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Services\Compliance\ComplianceSourceService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesComplianceScenarios;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The decisions in Etterlevelse og revisjon are a person's, and the services refuse everyone else
 * themselves — phase 0 step 13 (docs/external-api-phase-0-plan.md §2i).
 *
 * compliance.assess (Vurder etterlevelse), compliance.audit (planning and running an audit: its
 * fields, scope, kontrollhandlinger, revisjonsfunn, the hand-off to Avvik og forbedringer and Start,
 * Fullfør, Avbryt and Gjenåpne) and compliance.delete are human-only keys. An integration, any
 * request through the API (delegated mode included), an inactive person, a person without the key,
 * a person of another customer — their System Owner too — and a customer without the module are
 * turned away by the service, whatever the entry point, and nothing is written.
 */
class ComplianceHumanDecisionTest extends TestCase
{
    use CreatesComplianceScenarios;
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::COMPLIANCE_VIEW,
        CustomerPermissionCatalog::COMPLIANCE_EDIT,
        CustomerPermissionCatalog::COMPLIANCE_ASSESS,
        CustomerPermissionCatalog::COMPLIANCE_AUDIT,
        CustomerPermissionCatalog::COMPLIANCE_DELETE,
    ];

    /** The writes that need compliance.audit; the rest need compliance.assess or compliance.delete. */
    private const AUDIT_WORK = [
        'start', 'complete', 'cancel', 'reopen', 'create_audit', 'update_audit', 'add_scope', 'remove_scope',
        'create_check', 'update_check', 'delete_check', 'create_finding', 'update_finding', 'delete_finding', 'hand_off',
    ];

    private const DELETES = ['delete_requirement', 'delete_audit', 'delete_source'];

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

    /** A person with the keys takes every decision, and the history carries their name. */
    public function test_a_person_with_the_keys_takes_every_decision_under_their_own_name(): void
    {
        $world = $this->world();
        $decider = $world['decider'];

        $assessment = app(ComplianceAssessmentService::class)->assess($world['requirement'], $decider, ComplianceAssessment::RESULT_COMPLIANT, 'Rutinen følges.');
        $this->assertSame((int) $decider->id, (int) $assessment->assessed_by_user_id);

        $lifecycle = app(ComplianceAuditLifecycleService::class);
        $lifecycle->start($world['planned'], $decider);
        $lifecycle->complete($world['running'], $decider, 'Ingen vesentlige avvik.');
        $lifecycle->reopen($world['completed'], $decider, 'Ett funn må rettes.');
        $this->assertSame(
            [(int) $decider->id],
            DB::table('compliance_audit_status_changes')->whereIn('audit_id', [$world['planned']->id, $world['running']->id, $world['completed']->id])->where('changed_at', '>=', now()->subMinute())->pluck('changed_by_user_id')->unique()->map(fn ($id): int => (int) $id)->values()->all(),
        );

        $finding = app(ComplianceAuditFindingService::class)->create($decider, $world['completed']->fresh(), $this->findingInput('Nytt funn'));
        $this->assertSame((int) $decider->id, (int) $finding->created_by);

        $mistake = $this->complianceAudit($world['customer'], $decider, 'Registrert ved en feil');
        $lifecycle->delete($mistake, $decider);
        $this->assertNull(ComplianceAudit::query()->find($mistake->id));

        $unused = $this->complianceRequirement($world['source'], 'Ubrukt krav');
        app(ComplianceRequirementLifecycleService::class)->delete($unused, $decider);
        $this->assertNull(ComplianceRequirement::query()->find($unused->id));

        app(ComplianceSourceService::class)->delete($world['unusedSource'], $decider);
        $this->assertDatabaseMissing('compliance_sources', ['id' => $world['unusedSource']->id]);
    }

    /** The keys are human-only: an integration gets none of them, and with them it is still refused. */
    public function test_an_integration_takes_no_decision_whatever_its_roles(): void
    {
        $world = $this->world();
        $integration = $this->complianceMember($world['customer']);
        $this->complianceGrant($world['customer'], $integration, self::EVERYTHING);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        $permissions = app(CustomerPermissionService::class);
        foreach ([CustomerPermissionCatalog::COMPLIANCE_ASSESS, CustomerPermissionCatalog::COMPLIANCE_AUDIT, CustomerPermissionCatalog::COMPLIANCE_DELETE] as $key) {
            $this->assertFalse($permissions->has($integration->fresh(), $key), $key);
        }

        $this->assertEveryDecisionRefused($world, $integration->fresh(), HumanDecisionRefused::class);
    }

    /** Delegated mode: a person's own account, but the request comes through the API. */
    public function test_no_decision_is_taken_through_the_api_even_on_behalf_of_a_person(): void
    {
        $world = $this->world();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertEveryDecisionRefused($world, $world['decider'], HumanDecisionRefused::class);
    }

    public function test_an_inactive_person_takes_no_decision(): void
    {
        $world = $this->world();
        $world['decider']->forceFill(['is_active' => false])->save();

        $this->assertEveryDecisionRefused($world, $world['decider']->fresh(), HumanDecisionRefused::class);
    }

    /** The service refuses on its own, each decision for want of its own key. */
    public function test_each_decision_takes_its_own_key(): void
    {
        $world = $this->world();
        $customer = $world['customer'];

        $this->assertEveryDecisionRefused($world, $this->complianceReader($customer), AuthorizationException::class, 'view only');
        $this->assertEveryDecisionRefused($world, $this->complianceEditor($customer), AuthorizationException::class, 'view + edit');

        $assessor = $this->complianceMember($customer);
        $this->complianceGrant($customer, $assessor, [CustomerPermissionCatalog::COMPLIANCE_VIEW, CustomerPermissionCatalog::COMPLIANCE_ASSESS]);
        $this->assertDecisionsRefused($world, $assessor, AuthorizationException::class, [...self::AUDIT_WORK, ...self::DELETES], 'assess only');

        $auditor = $this->complianceAuditor($customer, [CustomerPermissionCatalog::IMPROVEMENT_EDIT]);
        $this->assertDecisionsRefused($world, $auditor, AuthorizationException::class, ['assess', ...self::DELETES], 'audit only');

        $deleter = $this->complianceManager($customer);
        $this->assertDecisionsRefused($world, $deleter, AuthorizationException::class, ['assess', ...self::AUDIT_WORK], 'edit + delete');

        // The keys without compliance.view open nothing either.
        $blind = $this->complianceMember($customer);
        $this->complianceGrant($customer, $blind, array_values(array_diff(self::EVERYTHING, [CustomerPermissionCatalog::COMPLIANCE_VIEW])));
        $this->assertEveryDecisionRefused($world, $blind, AuthorizationException::class, 'no view');
    }

    /**
     * Customer separation: a decider in their own customer is nobody in another's — their System
     * Owner included, even with a role holding every key.
     */
    public function test_another_customers_decider_is_refused(): void
    {
        $world = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->complianceContext();
        $outsider = $this->complianceMember($other);
        $this->complianceGrant($other, $outsider, self::EVERYTHING);
        $this->complianceGrant($other, $otherOwner, self::EVERYTHING);

        // Ny revisjon is always in the actor's own customer, so it is not a way in either.
        $intoTheWorld = array_values(array_diff([...self::AUDIT_WORK, 'assess', ...self::DELETES], ['create_audit']));

        foreach (['role with every key' => $outsider, 'System Owner with every key' => $otherOwner] as $who => $person) {
            $this->assertDecisionsRefused($world, $person, AuthorizationException::class, $intoTheWorld, $who);
        }
    }

    /** System Owner holds compliance.* only through a role of their own (explicit grant), in the service too. */
    public function test_a_system_owner_without_a_role_takes_no_decision(): void
    {
        $world = $this->world();

        $this->assertEveryDecisionRefused($world, $world['owner'], AuthorizationException::class);
    }

    /** Without the module the keys open nothing. */
    public function test_a_customer_without_the_module_takes_no_decision(): void
    {
        $world = $this->world();
        $world['customer']->packageEntitlements()->delete();
        $this->assertFalse(app(ModuleEntitlementService::class)->hasModule($world['customer']->fresh(), 'compliance'));

        $this->assertEveryDecisionRefused($world, $world['decider']->fresh(), AuthorizationException::class);
    }

    /**
     * A finding or a kontrollhandling is changed only as one of the audit it is passed with: one of
     * another audit — of the same customer — is not found, and nothing is written.
     */
    public function test_a_finding_or_check_of_another_audit_is_not_reached_through_this_one(): void
    {
        $world = $this->world();
        $elsewhere = $this->complianceAudit($world['customer'], $world['decider'], 'Annen revisjon');
        app(ComplianceAuditLifecycleService::class)->start($elsewhere, $world['decider']);
        $before = $this->snapshot();

        foreach ([
            'update_finding' => fn () => app(ComplianceAuditFindingService::class)->update($world['decider'], $elsewhere->fresh(), $world['finding'], $this->findingInput('Overskrevet')),
            'delete_finding' => fn () => app(ComplianceAuditFindingService::class)->delete($world['decider'], $elsewhere->fresh(), $world['finding']),
            'hand_off' => fn () => app(ComplianceAuditFindingHandoffService::class)->handOff($world['decider'], $elsewhere->fresh(), $world['finding'], $this->handoffInput($world)),
            'update_check' => fn () => app(ComplianceAuditCheckService::class)->update($world['decider'], $elsewhere->fresh(), $world['check'], $this->checkInput('Overskrevet')),
            'delete_check' => fn () => app(ComplianceAuditCheckService::class)->delete($world['decider'], $elsewhere->fresh(), $world['check']),
        ] as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} through another audit must be refused");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * What has been used keeps its history: Slett, called directly on the service by a person with
     * every key, refuses an assessed requirement, one in an audit's scope, an audit that has moved
     * and a source in use — read again under the lock. Nothing is deleted.
     */
    public function test_what_carries_history_is_never_deleted_through_the_services(): void
    {
        $world = $this->world();
        $decider = $world['decider'];
        $assessed = $this->complianceRequirement($world['source'], 'Vurdert krav');
        app(ComplianceAssessmentService::class)->assess($assessed, $decider, ComplianceAssessment::RESULT_COMPLIANT, 'Rutinen følges.');
        $before = $this->snapshot();

        foreach ([
            'assessed requirement' => fn () => app(ComplianceRequirementLifecycleService::class)->delete($assessed, $decider),
            'requirement in scope' => fn () => app(ComplianceRequirementLifecycleService::class)->delete($world['requirement'], $decider),
            'audit under way' => fn () => app(ComplianceAuditLifecycleService::class)->delete($world['running'], $decider),
            'completed audit' => fn () => app(ComplianceAuditLifecycleService::class)->delete($world['completed'], $decider),
            'source in use' => fn () => app(ComplianceSourceService::class)->delete($world['source'], $decider),
        ] as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} must not be deleted");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * A17 (§2i): deleting a customer still takes an audit with kontrollhandlinger, scope and findings
     * along — the foreign key from a check's requirement to the scope row is NO ACTION, and the
     * cascade order does not make the delete fail. Verified on a freshly migrated database too.
     */
    public function test_deleting_the_customer_takes_audits_with_checks_and_findings_along(): void
    {
        $world = $this->world();
        $checkId = (int) $world['check']->id;
        app(ComplianceAuditCheckService::class)->update($world['decider'], $world['running'], $world['check'], ['requirement_ids' => [(int) $world['requirement']->id]] + $this->checkInput('Stikkprøve'));
        app(ComplianceAuditFindingService::class)->update($world['decider'], $world['running'], $world['finding'], ['check_id' => $checkId, 'requirement_id' => (int) $world['requirement']->id] + $this->findingInput('Funn'));
        $this->assertTrue(DB::table('compliance_audit_check_requirements')->where('check_id', $checkId)->exists());

        DB::table('customers')->where('id', $world['customer']->id)->delete();

        foreach (['compliance_audits', 'compliance_audit_checks', 'compliance_audit_findings', 'compliance_audit_status_changes', 'compliance_requirements'] as $table) {
            $this->assertSame(0, DB::table($table)->where('customer_id', $world['customer']->id)->count(), $table);
        }
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  class-string<\Throwable>  $refusal
     */
    private function assertEveryDecisionRefused(array $world, User $actor, string $refusal, string $who = ''): void
    {
        $this->assertDecisionsRefused($world, $actor, $refusal, ['assess', ...self::AUDIT_WORK, ...self::DELETES], $who);
    }

    /**
     * Each decision called directly on its service with this actor: refused with $refusal, and the
     * register, the audits and their history are exactly as they were.
     *
     * @param  array<string, mixed>  $world
     * @param  class-string<\Throwable>  $refusal
     * @param  list<string>  $decisions
     */
    private function assertDecisionsRefused(array $world, User $actor, string $refusal, array $decisions, string $who = ''): void
    {
        $before = $this->snapshot();
        $lifecycle = app(ComplianceAuditLifecycleService::class);
        $scope = app(ComplianceAuditScopeService::class);
        $checks = app(ComplianceAuditCheckService::class);
        $findings = app(ComplianceAuditFindingService::class);

        $attempts = [
            'assess' => fn () => app(ComplianceAssessmentService::class)->assess($world['requirement'], $actor, ComplianceAssessment::RESULT_COMPLIANT, 'Uten rett.'),
            'start' => fn () => $lifecycle->start($world['planned'], $actor),
            'complete' => fn () => $lifecycle->complete($world['running'], $actor, 'Uten rett.'),
            'cancel' => fn () => $lifecycle->cancel($world['running'], $actor, 'Uten rett.'),
            'reopen' => fn () => $lifecycle->reopen($world['completed'], $actor, 'Uten rett.'),
            'create_audit' => fn () => app(ComplianceAuditService::class)->create($actor, $this->auditFields($world['decider'])),
            'update_audit' => fn () => app(ComplianceAuditService::class)->update($actor, $world['planned'], ['title' => 'Overskrevet'] + $this->auditFields($world['decider'])),
            'add_scope' => fn () => $scope->addRequirements($actor, $world['planned'], [(int) $world['requirement']->id]),
            'remove_scope' => fn () => $scope->removeRequirement($actor, $world['running'], (int) $world['requirement']->id),
            'create_check' => fn () => $checks->create($actor, $world['running'], $this->checkInput('Uten rett')),
            'update_check' => fn () => $checks->update($actor, $world['running'], $world['check'], $this->checkInput('Overskrevet')),
            'delete_check' => fn () => $checks->delete($actor, $world['running'], $world['check']),
            'create_finding' => fn () => $findings->create($actor, $world['running'], $this->findingInput('Uten rett')),
            'update_finding' => fn () => $findings->update($actor, $world['running'], $world['finding'], $this->findingInput('Overskrevet')),
            'delete_finding' => fn () => $findings->delete($actor, $world['running'], $world['finding']),
            'hand_off' => fn () => app(ComplianceAuditFindingHandoffService::class)->handOff($actor, $world['running'], $world['finding'], $this->handoffInput($world)),
            'delete_requirement' => fn () => app(ComplianceRequirementLifecycleService::class)->delete($world['unusedRequirement'], $actor),
            'delete_audit' => fn () => $lifecycle->delete($world['planned'], $actor),
            'delete_source' => fn () => app(ComplianceSourceService::class)->delete($world['unusedSource'], $actor),
        ];

        foreach ($decisions as $decision) {
            try {
                $attempts[$decision]();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, mixed> everything a decision could have written */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns): array => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row): array => (array) $row)->all();

        return [
            'requirements' => $rows('compliance_requirements', ['id', 'status']),
            'sources' => $rows('compliance_sources', ['id']),
            'assessments' => $rows('compliance_assessments', ['id']),
            'audits' => $rows('compliance_audits', ['id', 'title', 'status', 'conclusion']),
            'status_changes' => $rows('compliance_audit_status_changes', ['id']),
            'scope' => DB::table('compliance_audit_requirements')->orderBy('audit_id')->orderBy('requirement_id')->get(['audit_id', 'requirement_id'])->map(fn ($row): array => (array) $row)->all(),
            'checks' => $rows('compliance_audit_checks', ['id', 'title']),
            'findings' => $rows('compliance_audit_findings', ['id', 'title', 'improvement_case_id']),
            'cases' => $rows('improvement_cases', ['id']),
        ];
    }

    /**
     * A customer with a requirement, an unused requirement and source, and three audits — planned,
     * in progress (with a check, a finding and the requirement in scope) and completed — moved there
     * through the lifecycle, so they carry history. The decider holds every key and may register
     * cases in the fagområde.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->complianceContext();
        $area = $this->area($customer, 'IT');
        $decider = $this->complianceMember($customer, 'Revisor Med Alle Nøkler');
        $this->complianceGrant($customer, $decider, self::EVERYTHING);
        $this->grant($customer, $decider, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$area]);

        $source = $this->complianceSource($customer);
        $requirement = $this->complianceRequirement($source, 'Tilgangsstyring', null, 'A.5.15');
        $unusedRequirement = $this->complianceRequirement($source, 'Registrert ved en feil', null, 'A.5.99');
        $unusedSource = $this->complianceSource($customer, 'Ubrukt kilde', null, 'internal');

        $lifecycle = app(ComplianceAuditLifecycleService::class);
        $planned = $this->complianceAudit($customer, $decider, 'Planlagt revisjon');
        $running = $this->complianceAudit($customer, $decider, 'Pågående revisjon');
        app(ComplianceAuditScopeService::class)->addRequirements($decider, $running, [(int) $requirement->id]);
        $lifecycle->start($running, $decider);
        $completed = $this->complianceAudit($customer, $decider, 'Fullført revisjon');
        $lifecycle->start($completed, $decider);
        $lifecycle->complete($completed->fresh(), $decider, 'Ingen vesentlige avvik.');

        $check = app(ComplianceAuditCheckService::class)->create($decider, $running->fresh(), $this->checkInput('Stikkprøve av tilganger'));
        $finding = app(ComplianceAuditFindingService::class)->create($decider, $running->fresh(), $this->findingInput('Tilganger fjernes ikke'));

        return [
            'customer' => $customer,
            'owner' => $owner,
            'area' => $area,
            'decider' => $decider,
            'source' => $source,
            'requirement' => $requirement,
            'unusedRequirement' => $unusedRequirement,
            'unusedSource' => $unusedSource,
            'planned' => $planned->fresh(),
            'running' => $running->fresh(),
            'completed' => $completed->fresh(),
            'check' => $check->fresh(),
            'finding' => $finding->fresh(),
        ];
    }

    /** @return array<string, mixed> */
    private function auditFields(User $responsible): array
    {
        return [
            'title' => 'Ny revisjon',
            'audit_type' => ComplianceAudit::TYPE_INTERNAL,
            'responsible_user_id' => (int) $responsible->id,
            'planned_end_date' => '2026-12-15',
        ];
    }

    /** @return array<string, mixed> */
    private function checkInput(string $title): array
    {
        return ['title' => $title, 'observation' => 'Ti brukere kontrollert.', 'evidence' => null, 'requirement_ids' => []];
    }

    /** @return array<string, mixed> */
    private function findingInput(string $title): array
    {
        return [
            'finding_type' => ComplianceAuditFinding::TYPE_NONCONFORMITY,
            'title' => $title,
            'description' => 'Tre av ti sluttede brukere hadde fortsatt aktiv tilgang.',
            'requirement_id' => null,
            'check_id' => null,
            'quality_process_id' => null,
            'control_item_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $world
     * @return array<string, mixed>
     */
    private function handoffInput(array $world): array
    {
        return [
            'title' => 'Tilganger fjernes ikke',
            'description' => 'Fra internrevisjonen.',
            'business_area_id' => $world['area']->id,
            'owner_user_id' => $world['decider']->id,
            'due_date' => '2030-03-31',
        ];
    }
}
