<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use App\Models\SupplierDueDiligenceAssessment;
use App\Models\SupplierRequirementOverride;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Permissions\CustomerPermissionService;
use App\Services\Security\ActorChannel;
use App\Services\Suppliers\Assurance\SupplierControlRequirementService;
use App\Services\Suppliers\Assurance\SupplierDueDiligenceService;
use App\Services\Suppliers\Assurance\SupplierRequirementEvaluationService;
use App\Services\Suppliers\Assurance\SupplierRequirementOverrideService;
use App\Services\Suppliers\Assurance\SupplierRequirementTemplateLibrary;
use App\Services\Suppliers\Assurance\SupplierRequirementWikiGuidance;
use App\Services\Suppliers\SupplierAssessmentService;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Support\CustomerPermissionCatalog;
use App\Support\Suppliers\RequirementTemplates\RequirementTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\CreatesSupplierScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The decisions in Leverandøroppfølging are a person's, and the services refuse everyone else
 * themselves — phase 0 step 14 (docs/external-api-phase-0-plan.md §2j).
 *
 * supplier.assess (Vurder leverandør), supplier.assure (Leverandørkontroll: controls, whether a
 * requirement applies, aktsomhetsvurdering and the Kontrollkrav catalogue) and supplier.delete are
 * human-only keys. An integration, any request through the API (delegated mode included), an
 * inactive person, a person without the key, a person of another customer — their System Owner too —
 * and a customer without the module are turned away by the service, whatever the entry point, and
 * nothing is written.
 */
class SupplierHumanDecisionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use CreatesSupplierScenarios;
    use UsesProjectPostgresConnection;

    private const EVERYTHING = [
        CustomerPermissionCatalog::SUPPLIER_VIEW,
        CustomerPermissionCatalog::SUPPLIER_EDIT,
        CustomerPermissionCatalog::SUPPLIER_ASSESS,
        CustomerPermissionCatalog::SUPPLIER_ASSURE,
        CustomerPermissionCatalog::SUPPLIER_DELETE,
    ];

    /** The decisions that need supplier.assure; the other two need supplier.assess and supplier.delete. */
    private const ASSURE_WORK = [
        'control', 'reconfirm', 'override', 'due_diligence', 'create_requirement', 'update_requirement',
        'retire_requirement', 'delete_requirement', 'apply_template', 'link_guidance',
    ];

    private const ALL = ['assess', 'delete', ...self::ASSURE_WORK];

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
        $supplier = $world['supplier'];

        $assessment = app(SupplierAssessmentService::class)->record($supplier, $decider, $this->assessmentInput());
        $this->assertSame((int) $decider->id, (int) $assessment->assessed_by_user_id);

        $control = app(SupplierRequirementEvaluationService::class)->record($decider, $supplier, $this->controlInput($world));
        $this->assertSame((int) $decider->id, (int) $control->evaluated_by_user_id);

        $override = app(SupplierRequirementOverrideService::class)->record($decider, $supplier, (int) $world['conditional']->id, SupplierRequirementOverride::ACTION_INCLUDE, 'Leverandøren bruker underleverandører likevel.');
        $this->assertSame((int) $decider->id, (int) $override->created_by_user_id);

        $dueDiligence = app(SupplierDueDiligenceService::class)->record($decider, $supplier, $this->dueDiligenceInput());
        $this->assertSame((int) $decider->id, (int) $dueDiligence->assessed_by_user_id);

        $catalogue = app(SupplierControlRequirementService::class);
        $created = $catalogue->create($decider, $this->requirementInput('Nytt krav'));
        $catalogue->update($decider, $created, $this->requirementInput('Rettet krav'));
        $catalogue->retire($decider, $created->fresh());
        $this->assertSame([SupplierControlRequirement::STATUS_RETIRED, 'Rettet krav'], [$created->fresh()->status, $created->fresh()->title]);
        $catalogue->delete($decider, $world['unusedRequirement']);
        $this->assertNull(SupplierControlRequirement::query()->find($world['unusedRequirement']->id));

        $this->assertGreaterThan(0, app(SupplierRequirementTemplateLibrary::class)->apply($decider, RequirementTemplates::keys()[0])['created']);

        $this->assertTrue(app(SupplierLifecycleService::class)->delete($world['mistake'], $decider));
        $this->assertNull(Supplier::query()->find($world['mistake']->id));
        $this->assertDatabaseHas('supplier_deletions', ['supplier_id' => $world['mistake']->id, 'deleted_by_user_id' => $decider->id]);
    }

    /** The keys are human-only: an integration gets none of them, and with them it is still refused. */
    public function test_an_integration_takes_no_decision_whatever_its_roles(): void
    {
        $world = $this->world();
        $integration = $this->supplierUser($world['customer'], self::EVERYTHING);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        $permissions = app(CustomerPermissionService::class);
        foreach ([CustomerPermissionCatalog::SUPPLIER_ASSESS, CustomerPermissionCatalog::SUPPLIER_ASSURE, CustomerPermissionCatalog::SUPPLIER_DELETE] as $key) {
            $this->assertFalse($permissions->has($integration->fresh(), $key), $key);
        }

        $this->assertDecisionsRefused($world, $integration->fresh(), HumanDecisionRefused::class, self::ALL);
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

        $this->assertDecisionsRefused($world, $world['decider'], HumanDecisionRefused::class, self::ALL);
    }

    public function test_an_inactive_person_takes_no_decision(): void
    {
        $world = $this->world();
        $world['decider']->forceFill(['is_active' => false])->save();

        $this->assertDecisionsRefused($world, $world['decider']->fresh(), HumanDecisionRefused::class, self::ALL);
    }

    /** The service refuses on its own, each decision for want of its own key. */
    public function test_each_decision_takes_its_own_key(): void
    {
        $world = $this->world();
        $customer = $world['customer'];

        $this->assertDecisionsRefused($world, $this->supplierUser($customer, []), AuthorizationException::class, self::ALL, 'view only');
        $this->assertDecisionsRefused($world, $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_EDIT]), AuthorizationException::class, self::ALL, 'view + edit');
        $this->assertDecisionsRefused($world, $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSESS]), AuthorizationException::class, ['delete', ...self::ASSURE_WORK], 'assess only');
        $this->assertDecisionsRefused($world, $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_ASSURE]), AuthorizationException::class, ['assess', 'delete'], 'assure only');
        $this->assertDecisionsRefused($world, $this->supplierUser($customer, [CustomerPermissionCatalog::SUPPLIER_DELETE]), AuthorizationException::class, ['assess', ...self::ASSURE_WORK], 'delete only');

        // The keys without supplier.view open nothing either.
        $blind = $this->member($customer);
        $this->grantAll($customer, $blind, array_values(array_diff(self::EVERYTHING, [CustomerPermissionCatalog::SUPPLIER_VIEW])));
        $this->assertDecisionsRefused($world, $blind->fresh(), AuthorizationException::class, self::ALL, 'no view');
    }

    /**
     * Customer separation: a decider in their own customer is nobody in another's — their System
     * Owner included, even with a role holding every key.
     */
    public function test_another_customers_decider_is_refused(): void
    {
        $world = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->context('grc');
        $outsider = $this->supplierUser($other, self::EVERYTHING);
        $this->grantAll($other, $otherOwner, self::EVERYTHING);

        // The catalogue and the templates are always the actor's own customer's, so they are not a way
        // in either; what is left is everything on this customer's suppliers and requirements.
        $intoTheWorld = ['assess', 'delete', 'control', 'reconfirm', 'override', 'due_diligence', 'update_requirement', 'retire_requirement', 'delete_requirement'];

        foreach (['role with every key' => $outsider, 'System Owner with every key' => $otherOwner->fresh()] as $who => $person) {
            $this->assertDecisionsRefused($world, $person, \Throwable::class, $intoTheWorld, $who);
        }
    }

    /** System Owner holds supplier.* only through a role of their own (explicit grant), in the service too. */
    public function test_a_system_owner_without_a_role_takes_no_decision(): void
    {
        $world = $this->world();

        $this->assertDecisionsRefused($world, $world['owner'], AuthorizationException::class, self::ALL);
    }

    /** Without the module the keys open nothing. */
    public function test_a_customer_without_the_module_takes_no_decision(): void
    {
        $world = $this->world();
        $world['customer']->packageEntitlements()->delete();
        $this->assertFalse(app(ModuleEntitlementService::class)->hasModule($world['customer']->fresh(), 'supplier'));

        $this->assertDecisionsRefused($world, $world['decider']->fresh(), AuthorizationException::class, self::ALL);
    }

    /**
     * What has been decided keeps its history: Slett leverandør, called directly on the service by a
     * person with every key, refuses a supplier with an assessment — read again under the lock — and
     * the database refuses to change or remove the assessment itself.
     */
    public function test_a_supplier_with_a_decision_is_never_deleted_and_the_decision_never_changes(): void
    {
        $world = $this->world();
        $assessment = app(SupplierAssessmentService::class)->record($world['supplier'], $world['decider'], $this->assessmentInput());

        $this->assertFalse(app(SupplierLifecycleService::class)->delete($world['supplier'], $world['decider']));
        $this->assertNotNull(Supplier::query()->find($world['supplier']->id));

        foreach ([
            'update' => fn () => DB::table('supplier_assessments')->where('id', $assessment->id)->update(['overall_result' => SupplierAssessment::RESULT_SATISFACTORY, 'rationale' => 'Skrevet om.']),
            'delete' => fn () => DB::table('supplier_assessments')->where('id', $assessment->id)->delete(),
            'supplier' => fn () => DB::table('suppliers')->where('id', $world['supplier']->id)->delete(),
        ] as $what => $attempt) {
            DB::statement('SAVEPOINT refused');

            try {
                $attempt();
                $this->fail("{$what} must be refused by the database");
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT refused');
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Leverer som avtalt.', SupplierAssessment::query()->findOrFail($assessment->id)->rationale);
    }

    /**
     * Each decision called directly on its service with this actor: refused with $refusal, and the
     * suppliers, the catalogue and every history table are exactly as they were.
     *
     * @param  array<string, mixed>  $world
     * @param  class-string<\Throwable>  $refusal
     * @param  list<string>  $decisions
     */
    private function assertDecisionsRefused(array $world, User $actor, string $refusal, array $decisions, string $who = ''): void
    {
        $before = $this->snapshot();
        $supplier = $world['supplier'];
        $catalogue = app(SupplierControlRequirementService::class);

        $attempts = [
            'assess' => fn () => app(SupplierAssessmentService::class)->record($supplier, $actor, $this->assessmentInput()),
            'delete' => fn () => app(SupplierLifecycleService::class)->delete($world['mistake'], $actor),
            'control' => fn () => app(SupplierRequirementEvaluationService::class)->record($actor, $supplier, $this->controlInput($world)),
            'reconfirm' => fn () => app(SupplierRequirementEvaluationService::class)->reconfirm($actor, $supplier, (int) $world['document']->id, [(int) $world['requirement']->id], 'Uten rett.'),
            'override' => fn () => app(SupplierRequirementOverrideService::class)->record($actor, $supplier, (int) $world['conditional']->id, SupplierRequirementOverride::ACTION_INCLUDE, 'Uten rett.'),
            'due_diligence' => fn () => app(SupplierDueDiligenceService::class)->record($actor, $supplier, $this->dueDiligenceInput()),
            'create_requirement' => fn () => $catalogue->create($actor, $this->requirementInput('Uten rett')),
            'update_requirement' => fn () => $catalogue->update($actor, $world['requirement'], $this->requirementInput('Overskrevet')),
            'retire_requirement' => fn () => $catalogue->retire($actor, $world['requirement']),
            'delete_requirement' => fn () => $catalogue->delete($actor, $world['unusedRequirement']),
            'apply_template' => fn () => app(SupplierRequirementTemplateLibrary::class)->apply($actor, RequirementTemplates::keys()[0]),
            'link_guidance' => fn () => app(SupplierRequirementWikiGuidance::class)->link($actor, $world['requirement'], 1),
        ];

        foreach ($decisions as $decision) {
            try {
                $result = $attempts[$decision]();
                $this->fail("{$who}: {$decision} must be refused".($result === false ? ' (returned false)' : ''));
            } catch (AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e::class.': '.$e->getMessage());
                $this->assertNotInstanceOf(ValidationException::class, $e, "{$who}: {$decision} reached validation — ".$e->getMessage());
            }
        }

        $this->assertSame($before, $this->snapshot(), $who);
    }

    /** @return array<string, mixed> everything a decision could have written */
    private function snapshot(): array
    {
        $rows = fn (string $table, array $columns): array => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row): array => (array) $row)->all();

        return [
            'suppliers' => $rows('suppliers', ['id', 'status']),
            'deletions' => $rows('supplier_deletions', ['id']),
            'assessments' => $rows('supplier_assessments', ['id']),
            'controls' => $rows('supplier_requirement_evaluations', ['id']),
            'overrides' => $rows('supplier_requirement_overrides', ['id']),
            'due_diligence' => $rows('supplier_due_diligence_assessments', ['id']),
            'requirements' => $rows('supplier_control_requirements', ['id', 'title', 'status']),
            'requirement_status_changes' => $rows('supplier_control_requirement_status_changes', ['id']),
            'guidance' => $rows('supplier_control_requirement_wiki_pages', ['id']),
        ];
    }

    /**
     * A GRC customer with an active supplier, a document, a requirement that applies to everyone, one
     * that does not apply automatically, an unused one, and a supplier registered by mistake. The
     * decider holds every supplier key.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context('grc');
        $decider = $this->supplierUser($customer, self::EVERYTHING);
        $supplier = $this->supplier($customer, $decider, 'Drift AS');

        return [
            'customer' => $customer,
            'owner' => $owner,
            'decider' => $decider,
            'supplier' => $supplier,
            'mistake' => $this->supplier($customer, $decider, 'Registrert ved en feil'),
            'document' => SupplierDocument::query()->create([
                'customer_id' => $customer->id,
                'supplier_id' => $supplier->id,
                'document_type' => 'certificate',
                'title' => 'ISO 27001-sertifikat',
            ]),
            'requirement' => $this->requirement($customer, 'Informasjonssikkerhet', []),
            'conditional' => $this->requirement($customer, 'Underleverandører', [['subcontractors']]),
            'unusedRequirement' => $this->requirement($customer, 'Ubrukt krav', [['subcontractors']]),
        ];
    }

    private function requirement(Customer $customer, string $title, array $rule): SupplierControlRequirement
    {
        return SupplierControlRequirement::query()->create([
            'customer_id' => $customer->id,
            'title' => $title,
            'theme' => 'privacy',
            'level' => 'standard',
            'control_point' => 'before_contract',
            'applies_when' => $rule,
        ]);
    }

    /** @return array<string, mixed> */
    private function assessmentInput(): array
    {
        $input = [];

        foreach (SupplierAssessment::CRITERIA as $criterion) {
            $input[$criterion] = SupplierAssessment::RATINGS[0];
        }

        return $input + [
            'overall_result' => SupplierAssessment::RESULT_SATISFACTORY,
            'rationale' => 'Leverer som avtalt.',
            'assessed_on' => now()->toDateString(),
        ];
    }

    /** @param  array<string, mixed>  $world
     * @return array<string, mixed> */
    private function controlInput(array $world): array
    {
        return [
            'requirement_id' => (int) $world['requirement']->id,
            'status' => 'documented',
            'rationale' => 'Sertifikatet dekker kravet.',
            'evaluated_on' => now()->toDateString(),
            'document_ids' => [(int) $world['document']->id],
        ];
    }

    /** @return array<string, mixed> */
    private function dueDiligenceInput(): array
    {
        $input = [];

        foreach (SupplierDueDiligenceAssessment::AREAS as $area) {
            $input[$area] = SupplierDueDiligenceAssessment::LEVELS[0];
        }

        return $input + [
            'conclusion' => SupplierDueDiligenceAssessment::CONCLUSIONS[0],
            'rationale' => 'Lav risiko i leverandørkjeden.',
            'review_interval_months' => SupplierDueDiligenceAssessment::REVIEW_INTERVALS[0],
            'assessed_on' => now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function requirementInput(string $title): array
    {
        return [
            'title' => $title,
            'theme' => 'privacy',
            'level' => 'standard',
            'control_point' => 'before_contract',
            'rule_mode' => 'all',
        ];
    }
}
