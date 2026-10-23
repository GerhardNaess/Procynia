<?php

namespace Tests\Feature\Security;

use App\Exceptions\HumanDecisionRefused;
use App\Models\ComplianceAudit;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionVerification;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\QualityItem;
use App\Models\QualityProcessBlueprint;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\Requirements\RequirementEditorService;
use App\Services\Compliance\ComplianceAssessmentService;
use App\Services\Compliance\ComplianceAuditCheckService;
use App\Services\Compliance\ComplianceAuditFindingHandoffService;
use App\Services\Compliance\ComplianceAuditFindingService;
use App\Services\Compliance\ComplianceAuditLifecycleService;
use App\Services\Compliance\ComplianceAuditScopeService;
use App\Services\Compliance\ComplianceAuditService;
use App\Services\Compliance\ComplianceRequirementLifecycleService;
use App\Services\Compliance\ComplianceSourceService;
use App\Services\Compliance\Library\ComplianceApplicabilityService;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimContentRepairService;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentOwnerApprovalService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentUploadService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionFailureRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageDeletionService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageGenerationFailureRecoveryService;
use App\Services\EnterpriseWiki\EnterpriseWikiPageReviewService;
use App\Services\EnterpriseWiki\EnterpriseWikiRunDismissalService;
use App\Services\Improvements\ImprovementActionLifecycleService;
use App\Services\Improvements\ImprovementCaseLifecycleService;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewService;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiMeasurementService;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Services\Quality\QualityItemService;
use App\Services\Quality\QualityProcessBlueprintService;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskAssessmentService;
use App\Services\Risk\RiskLifecycleService;
use App\Services\SavedNoticeStatusService;
use App\Services\Security\ActorChannel;
use App\Services\Security\HumanDecisionGuard;
use App\Services\Suppliers\Assurance\SupplierControlRequirementService;
use App\Services\Suppliers\Assurance\SupplierDueDiligenceService;
use App\Services\Suppliers\Assurance\SupplierRequirementEvaluationService;
use App\Services\Suppliers\Assurance\SupplierRequirementOverrideService;
use App\Services\Suppliers\Assurance\SupplierRequirementTemplateLibrary;
use App\Services\Suppliers\Assurance\SupplierRequirementWikiGuidance;
use App\Services\Suppliers\SupplierAssessmentService;
use App\Services\Suppliers\SupplierCriticalityService;
use App\Services\Suppliers\SupplierLifecycleService;
use App\Services\Suppliers\SupplierRegistration;
use App\Support\HumanDecision;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;
use Throwable;

/**
 * Phase 0 step 20 (docs/external-api-phase-0-plan.md §6.1 no. 3, K1–K4): every human decision is
 * taken in a service method that refuses anything but a person acting interactively, before it
 * writes anything — whichever way the call arrives.
 *
 * DECISIONS is the register of those methods. It is checked both ways: every HumanDecision has a
 * method, every method in app/ that reaches HumanDecisionGuard is registered, and each registered
 * method is called with an integration identity and through the API channel and must refuse with
 * its own decision, without a single write to the database.
 */
class HumanDecisionContractTest extends TestCase
{
    use UsesProjectPostgresConnection;

    /** @var list<array{0: class-string, 1: string, 2: list<HumanDecision>}> */
    private const DECISIONS = [
        [RequirementEditorService::class, 'approveRequirement', [HumanDecision::TENDER_REQUIREMENT_DECIDE]],
        [RequirementEditorService::class, 'rejectRequirement', [HumanDecision::TENDER_REQUIREMENT_DECIDE]],
        [RequirementEditorService::class, 'restoreRequirement', [HumanDecision::TENDER_REQUIREMENT_DECIDE]],
        [RequirementEditorService::class, 'transitionRequirementReviewStatus', [HumanDecision::TENDER_REQUIREMENT_DECIDE]],
        [ComplianceAssessmentService::class, 'assess', [HumanDecision::COMPLIANCE_ASSESS]],
        [ComplianceAuditCheckService::class, 'create', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditCheckService::class, 'delete', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditCheckService::class, 'update', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditFindingHandoffService::class, 'handOff', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditFindingService::class, 'create', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditFindingService::class, 'delete', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditFindingService::class, 'update', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditLifecycleService::class, 'cancel', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditLifecycleService::class, 'complete', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditLifecycleService::class, 'delete', [HumanDecision::COMPLIANCE_DELETE]],
        [ComplianceAuditLifecycleService::class, 'reopen', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditLifecycleService::class, 'start', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditScopeService::class, 'addInitialRequirements', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditScopeService::class, 'addProcess', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditScopeService::class, 'addRequirements', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditScopeService::class, 'addRequirementsFromSource', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditScopeService::class, 'removeProcess', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditScopeService::class, 'removeRequirement', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditService::class, 'create', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceAuditService::class, 'update', [HumanDecision::COMPLIANCE_AUDIT]],
        [ComplianceRequirementLifecycleService::class, 'delete', [HumanDecision::COMPLIANCE_DELETE]],
        [ComplianceSourceService::class, 'delete', [HumanDecision::COMPLIANCE_DELETE]],
        [ComplianceApplicabilityService::class, 'set', [HumanDecision::COMPLIANCE_APPLICABILITY]],
        [EnterpriseWikiClaimContentRepairService::class, 'applyManualMixedBlockEdit', [HumanDecision::WIKI_CLAIM_REPAIR]],
        [EnterpriseWikiClaimContentRepairService::class, 'applyWorkingVersionBlockEdits', [HumanDecision::WIKI_PAGE_EDIT]],
        [EnterpriseWikiClaimContentRepairService::class, 'forkPublishedVersionForClaimDecision', [HumanDecision::WIKI_CLAIM_DECIDE]],
        [EnterpriseWikiClaimDecisionService::class, 'approve', [HumanDecision::WIKI_CLAIM_DECIDE]],
        [EnterpriseWikiClaimDecisionService::class, 'attachSourceReference', [HumanDecision::WIKI_CLAIM_DECIDE]],
        [EnterpriseWikiClaimDecisionService::class, 'reject', [HumanDecision::WIKI_CLAIM_DECIDE]],
        [EnterpriseWikiClaimDecisionService::class, 'setBlockingOverride', [HumanDecision::WIKI_CLAIM_DECIDE]],
        [EnterpriseWikiClaimDecisionService::class, 'unapprove', [HumanDecision::WIKI_CLAIM_DECIDE]],
        [EnterpriseWikiDocumentDeletionService::class, 'delete', [HumanDecision::WIKI_SOURCE_DELETE]],
        [EnterpriseWikiDocumentFlowService::class, 'cancelRun', [HumanDecision::WIKI_RUN_CANCEL]],
        [EnterpriseWikiDocumentFlowService::class, 'changeDocumentOwner', [HumanDecision::WIKI_SOURCE_OWNER_CHANGE]],
        [EnterpriseWikiDocumentFlowService::class, 'startForDocumentAs', [HumanDecision::WIKI_RUN_START]],
        [EnterpriseWikiDocumentOwnerApprovalService::class, 'decide', [HumanDecision::WIKI_DOCUMENT_OWNER_DECIDE]],
        [EnterpriseWikiDocumentUploadService::class, 'store', [HumanDecision::WIKI_SOURCE_UPLOAD]],
        [EnterpriseWikiMaintainerDecisionFailureRecoveryService::class, 'retryAs', [HumanDecision::WIKI_RUN_START]],
        [EnterpriseWikiPageDeletionService::class, 'deletePage', [HumanDecision::WIKI_PAGE_DELETE]],
        [EnterpriseWikiPageDeletionService::class, 'deletePages', [HumanDecision::WIKI_PAGE_DELETE]],
        [EnterpriseWikiPageGenerationFailureRecoveryService::class, 'retryAs', [HumanDecision::WIKI_RUN_START]],
        [EnterpriseWikiPageReviewService::class, 'approve', [HumanDecision::WIKI_PAGE_APPROVE]],
        [EnterpriseWikiPageReviewService::class, 'assertMayChangeClaimText', [HumanDecision::WIKI_CLAIM_DECIDE]],
        [EnterpriseWikiPageReviewService::class, 'assignQa', [HumanDecision::WIKI_QA_ASSIGN]],
        [EnterpriseWikiPageReviewService::class, 'reopenRejected', [HumanDecision::WIKI_PAGE_SUBMIT]],
        [EnterpriseWikiPageReviewService::class, 'returnToOwner', [HumanDecision::WIKI_PAGE_RETURN]],
        [EnterpriseWikiPageReviewService::class, 'submit', [HumanDecision::WIKI_PAGE_SUBMIT]],
        [EnterpriseWikiPageReviewService::class, 'withdraw', [HumanDecision::WIKI_PAGE_WITHDRAW]],
        [EnterpriseWikiRunDismissalService::class, 'dismiss', [HumanDecision::WIKI_RUN_DISMISS]],
        [ImprovementActionLifecycleService::class, 'verify', [HumanDecision::IMPROVEMENT_CLOSE]],
        [ImprovementCaseLifecycleService::class, 'cancel', [HumanDecision::IMPROVEMENT_CLOSE]],
        [ImprovementCaseLifecycleService::class, 'close', [HumanDecision::IMPROVEMENT_CLOSE]],
        [ImprovementCaseLifecycleService::class, 'delete', [HumanDecision::IMPROVEMENT_DELETE]],
        [ImprovementCaseLifecycleService::class, 'reopen', [HumanDecision::IMPROVEMENT_CLOSE]],
        [ManagementReviewFinalizationService::class, 'finalize', [HumanDecision::MANAGEMENT_REVIEW_FINALIZE]],
        [ManagementReviewService::class, 'addAmendment', [HumanDecision::MANAGEMENT_REVIEW_FINALIZE]],
        [ManagementReviewService::class, 'delete', [HumanDecision::MANAGEMENT_REVIEW_DELETE]],
        [KpiLifecycleService::class, 'delete', [HumanDecision::OBJECTIVE_DELETE]],
        [KpiLifecycleService::class, 'reopen', [HumanDecision::OBJECTIVE_LIFECYCLE]],
        [KpiLifecycleService::class, 'retire', [HumanDecision::OBJECTIVE_LIFECYCLE]],
        [KpiMeasurementService::class, 'record', [HumanDecision::OBJECTIVE_MEASURE]],
        [KpiMeasurementService::class, 'withdraw', [HumanDecision::OBJECTIVE_MEASURE]],
        [ObjectiveLifecycleService::class, 'close', [HumanDecision::OBJECTIVE_LIFECYCLE]],
        [ObjectiveLifecycleService::class, 'delete', [HumanDecision::OBJECTIVE_DELETE]],
        [ObjectiveLifecycleService::class, 'reopen', [HumanDecision::OBJECTIVE_LIFECYCLE]],
        [QualityItemService::class, 'deleteItem', [HumanDecision::QUALITY_DELETE]],
        [QualityProcessBlueprintService::class, 'approve', [HumanDecision::QUALITY_FLOW_APPROVE]],
        [QualityProcessBlueprintService::class, 'delete', [HumanDecision::QUALITY_DELETE]],
        [QualityProcessBlueprintService::class, 'store', [HumanDecision::QUALITY_FLOW_ADOPT]],
        [RiskAcceptanceService::class, 'accept', [HumanDecision::RISK_ACCEPT]],
        [RiskAcceptanceService::class, 'revoke', [HumanDecision::RISK_ACCEPT]],
        [RiskAssessmentService::class, 'record', [HumanDecision::RISK_ASSESS]],
        [RiskLifecycleService::class, 'delete', [HumanDecision::RISK_DELETE]],
        [SavedNoticeStatusService::class, 'reopenAfterNoGo', [HumanDecision::TENDER_BID_STATUS]],
        [SavedNoticeStatusService::class, 'transition', [HumanDecision::TENDER_BID_STATUS]],
        [SupplierControlRequirementService::class, 'create', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierControlRequirementService::class, 'delete', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierControlRequirementService::class, 'reactivate', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierControlRequirementService::class, 'retire', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierControlRequirementService::class, 'update', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierDueDiligenceService::class, 'record', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierRequirementEvaluationService::class, 'reconfirm', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierRequirementEvaluationService::class, 'record', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierRequirementOverrideService::class, 'record', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierRequirementTemplateLibrary::class, 'apply', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierRequirementWikiGuidance::class, 'link', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierRequirementWikiGuidance::class, 'unlink', [HumanDecision::SUPPLIER_ASSURE]],
        [SupplierAssessmentService::class, 'record', [HumanDecision::SUPPLIER_ASSESS]],
        [SupplierCriticalityService::class, 'change', [HumanDecision::SUPPLIER_CRITICALITY]],
        [SupplierLifecycleService::class, 'activate', [HumanDecision::SUPPLIER_LIFECYCLE]],
        [SupplierLifecycleService::class, 'delete', [HumanDecision::SUPPLIER_DELETE]],
        [SupplierLifecycleService::class, 'end', [HumanDecision::SUPPLIER_LIFECYCLE]],
        [SupplierLifecycleService::class, 'reopen', [HumanDecision::SUPPLIER_LIFECYCLE]],
        [SupplierRegistration::class, 'register', [HumanDecision::SUPPLIER_CRITICALITY, HumanDecision::SUPPLIER_LIFECYCLE]],
    ];

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

    /** @return array<string, array{0: class-string, 1: string, 2: list<HumanDecision>}> */
    public static function decisionMethods(): array
    {
        $cases = [];

        foreach (self::DECISIONS as $entry) {
            $cases[class_basename($entry[0]).'::'.$entry[1]] = $entry;
        }

        return $cases;
    }

    /** K1: an integration — even one holding the System Owner role — is refused before anything is written. */
    #[DataProvider('decisionMethods')]
    public function test_an_integration_is_refused_before_anything_is_written(string $class, string $method, array $decisions): void
    {
        $customer = $this->customer();
        $integration = $this->actor($customer, User::ROLE_INTEGRATION);

        $this->assertRefusedBeforeWriting($class, $method, $decisions, $integration, HumanDecisionGuard::REFUSAL_INTEGRATION);
    }

    /** K4 / B2: a person acting through the API — delegated mode included — is refused the same way. */
    #[DataProvider('decisionMethods')]
    public function test_a_person_through_the_api_is_refused_before_anything_is_written(string $class, string $method, array $decisions): void
    {
        $customer = $this->customer();
        $person = $this->actor($customer, User::ROLE_CUSTOMER_ADMIN);

        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertRefusedBeforeWriting($class, $method, $decisions, $person, HumanDecisionGuard::REFUSAL_API);
    }

    public function test_every_decision_has_a_registered_method(): void
    {
        $registered = array_merge(...array_map(static fn (array $entry): array => $entry[2], self::DECISIONS));

        foreach (HumanDecision::cases() as $decision) {
            $this->assertContains($decision, $registered, "{$decision->value} has no service method that takes it");
        }
    }

    /**
     * The other direction: every public method in app/ that reaches HumanDecisionGuard with a
     * decision — itself or through its own helpers — is in the register, with the decisions it
     * reaches. A new decision path cannot be added without being called here.
     */
    public function test_every_method_that_takes_a_decision_is_registered(): void
    {
        $found = $this->decisionMethodsInApp();
        $registered = [];

        foreach (self::DECISIONS as [$class, $method, $decisions]) {
            $registered[$class.'::'.$method] = array_map(static fn (HumanDecision $d): string => $d->name, $decisions);
            sort($registered[$class.'::'.$method]);
        }

        ksort($found);
        ksort($registered);

        $this->assertSame($registered, $found);
    }

    /**
     * K3: the actor is a required User — never ?User and never defaulting to null. The one
     * exception is the older two-shape signature of applyManualMixedBlockEdit, which takes the actor
     * in either of two places and refuses when neither holds one (InvalidArgumentException).
     */
    public function test_no_decision_method_takes_an_optional_actor(): void
    {
        $allowed = ['EnterpriseWikiClaimContentRepairService::applyManualMixedBlockEdit'];

        foreach (self::DECISIONS as [$class, $method]) {
            $label = class_basename($class).'::'.$method;
            $userParameters = array_filter(
                (new ReflectionMethod($class, $method))->getParameters(),
                static fn (ReflectionParameter $p): bool => $p->getType() instanceof ReflectionNamedType && is_a($p->getType()->getName(), User::class, true),
            );

            $this->assertNotEmpty($userParameters, "{$label} takes no actor");

            if (in_array($label, $allowed, true)) {
                continue;
            }

            foreach ($userParameters as $parameter) {
                $this->assertFalse($parameter->allowsNull(), "{$label}: \${$parameter->getName()} is nullable");
                $this->assertFalse($parameter->isDefaultValueAvailable(), "{$label}: \${$parameter->getName()} has a default");
            }
        }
    }

    /**
     * K2: no controller writes a column that names who took a decision; the services do.
     *
     * A write is the column inside forceFill/fill/create/update/insert/updateOrCreate, or assigned
     * as ->column =. Reading a value into page props is not. The scan goes by column name, so a
     * controller writing a column of the same name on another table is listed below, with the table.
     */
    public function test_no_controller_writes_a_decision_column(): void
    {
        $otherTables = [
            // saved_notice_ai_requirement_assessments: an AI assessment that failed, not a decision.
            'app/Http/Controllers/App/AiController.php: assessed_by_user_id',
            // saved_notice_info_items: who closed an aksjon and who created it (§2m).
            'app/Http/Controllers/App/NoticeController.php: closed_by_user_id',
            'app/Http/Controllers/App/NoticeController.php: created_by_user_id',
        ];
        $columns = array_unique(array_merge(...array_values(HumanDecisionActorTriggerTest::DECISION_COLUMNS)));
        $violations = [];

        foreach ($this->phpFiles(app_path('Http/Controllers')) as $file) {
            $source = (string) file_get_contents($file);
            $relative = str_replace(base_path().'/', '', $file);

            foreach ($columns as $column) {
                if (preg_match('/->'.$column.'\s*=(?![=>])/', $source) === 1) {
                    $violations[] = "{$relative}: {$column}";

                    continue;
                }

                preg_match_all("/'{$column}'\s*+=>/", $source, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [, $offset]) {
                    $before = substr($source, max(0, $offset - 1500), min(1500, $offset));
                    $write = max(array_map(
                        static fn (string $call): int => (int) strrpos($before, $call),
                        ['forceFill([', 'fill([', '->create([', '->update([', '->insert([', 'updateOrCreate(', 'firstOrCreate(', 'insertGetId(['],
                    ));
                    $closed = max((int) strrpos($before, ']);'), (int) strrpos($before, '])->'), (int) strrpos($before, '];'));

                    if ($write > 0 && $write > $closed) {
                        $violations[] = "{$relative}: {$column}";

                        break;
                    }
                }
            }
        }

        $this->assertSame($otherTables, array_values(array_unique($violations)));
    }

    /**
     * Background work takes no human decision (§4.3): no job, command, listener, observer,
     * notification or provider calls a decision method. A job that did would run in the worker's
     * interactive channel and so pass the guard on behalf of whoever queued it — through the API too.
     */
    public function test_no_background_path_calls_a_decision_method(): void
    {
        $violations = [];
        $directories = ['Jobs', 'Console', 'Listeners', 'Observers', 'Notifications', 'Providers', 'Events'];

        foreach ($directories as $directory) {
            foreach ($this->phpFiles(app_path($directory)) as $file) {
                $source = (string) file_get_contents($file);

                foreach (self::DECISIONS as [$class, $method]) {
                    if (! str_contains($source, class_basename($class))) {
                        continue;
                    }

                    if (preg_match('/->'.$method.'\(/', $source) === 1) {
                        $violations[] = str_replace(base_path().'/', '', $file).': '.class_basename($class)."::{$method}";
                    }
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /** @return array<string, list<string>> class::method => decision names it reaches */
    private function decisionMethodsInApp(): array
    {
        $found = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            if ($file === app_path('Support/HumanDecision.php')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'HumanDecision::') || preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1 || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait)\s+(\w+)/m', $source, $cls) !== 1) {
                continue;
            }

            $class = $ns[1].'\\'.$cls[1];
            $lines = file($file);
            $bodies = [];
            $public = [];

            foreach ((new ReflectionClass($class))->getMethods() as $reflection) {
                if ($reflection->getDeclaringClass()->getName() !== $class || $reflection->getFileName() !== $file) {
                    continue;
                }

                $bodies[$reflection->getName()] = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
                $public[$reflection->getName()] = $reflection->isPublic() && ! $reflection->isConstructor();
            }

            $reach = [];

            foreach ($bodies as $name => $body) {
                preg_match_all('/HumanDecision::([A-Z_]+)/', $body, $m);
                $reach[$name] = array_unique($m[1]);
            }

            do {
                $changed = false;

                foreach ($bodies as $name => $body) {
                    preg_match_all('/\$this->(\w+)\(/', $body, $calls);

                    foreach (array_unique($calls[1]) as $callee) {
                        $merged = array_values(array_unique(array_merge($reach[$name], $reach[$callee] ?? [])));

                        if (count($merged) !== count($reach[$name])) {
                            $reach[$name] = $merged;
                            $changed = true;
                        }
                    }
                }
            } while ($changed);

            foreach ($reach as $name => $decisions) {
                if ($public[$name] && $decisions !== []) {
                    sort($decisions);
                    $found[$class.'::'.$name] = $decisions;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @param list<HumanDecision> $decisions */
    private function assertRefusedBeforeWriting(string $class, string $method, array $decisions, User $actor, string $refusal): void
    {
        // Resolved after the channel is bound, so the service's guard sees it.
        $service = app($class);
        $label = class_basename($class).'::'.$method;
        $arguments = array_key_exists($label, $this->arguments())
            ? $this->arguments()[$label]($actor)
            : $this->argumentsFor(new ReflectionMethod($class, $method), $actor, $label);

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        try {
            $service->{$method}(...$arguments);
            $this->fail("{$class}::{$method} must refuse");
        } catch (HumanDecisionRefused $e) {
            $this->assertContains($e->decision, $decisions, "{$class}::{$method} refused under another decision");
            $this->assertSame($refusal, $e->refusal);
        } catch (Throwable $e) {
            $this->fail("{$class}::{$method} failed before the guard: ".$e::class.': '.$e->getMessage());
        }

        $this->assertSame([], $writes, "{$class}::{$method} wrote before refusing");
    }

    /**
     * Methods that check their input before the guard (a reason, an outcome, the edited blocks), take
     * the decision only on one branch (adopting an AI flow, registering a supplier as in use), or
     * have nothing to do without a real row (deleting no pages deletes nothing). They get arguments
     * that reach the guard; everything else is built from the signature.
     *
     * @return array<string, \Closure(User): list<mixed>>
     */
    private function arguments(): array
    {
        $page = fn (User $actor): EnterpriseWikiPage => EnterpriseWikiPage::query()->create([
            'customer_id' => $actor->customer_id,
            'slug' => 'kontrakt-'.Str::lower(Str::random(6)),
            'title' => 'Kontraktside',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        $block = [['block_key' => 'block-0001', 'markdown' => 'Ny tekst.']];

        return [
            'ComplianceAuditLifecycleService::cancel' => fn (User $actor) => [new ComplianceAudit, $actor, 'Avbrutt i kontrakttesten.'],
            'ComplianceAuditLifecycleService::reopen' => fn (User $actor) => [new ComplianceAudit, $actor, 'Gjenåpnet i kontrakttesten.'],
            'ImprovementActionLifecycleService::verify' => fn (User $actor) => [new ImprovementAction, $actor, ImprovementActionVerification::RESULTS[0], 'Effekten er kontrollert.'],
            'ObjectiveLifecycleService::close' => fn (User $actor) => [new Objective, $actor, Objective::CLOSED_STATUSES[0], null],
            'SupplierLifecycleService::end' => fn (User $actor) => [new Supplier, $actor, 'Avsluttet i kontrakttesten.'],
            'SupplierLifecycleService::reopen' => fn (User $actor) => [new Supplier, $actor, 'Gjenåpnet i kontrakttesten.'],
            'SupplierRegistration::register' => fn (User $actor) => [$actor, [], Supplier::STATUS_ACTIVE, null],
            'QualityProcessBlueprintService::store' => fn (User $actor) => [(int) $actor->customer_id, new QualityItem, [], QualityProcessBlueprint::SOURCE_AI, $actor, ''],
            'EnterpriseWikiClaimContentRepairService::applyManualMixedBlockEdit' => fn (User $actor) => [new EnterpriseWikiIngestRun, new EnterpriseWikiPage, new EnterpriseWikiPageVersion, new EnterpriseWikiClaim, $block, $actor],
            'EnterpriseWikiClaimContentRepairService::applyWorkingVersionBlockEdits' => fn (User $actor) => [new EnterpriseWikiPage, new EnterpriseWikiPageVersion, $block, $actor],
            'EnterpriseWikiPageDeletionService::deletePage' => fn (User $actor) => [$page($actor), $actor],
            'EnterpriseWikiPageDeletionService::deletePages' => fn (User $actor) => [(int) $actor->customer_id, collect([$page($actor)->id]), $actor],
        ];
    }

    /** @return list<mixed> */
    private function argumentsFor(ReflectionMethod $method, User $actor, string $label): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                break;
            }

            $arguments[] = $this->argumentFor($parameter, $actor, $label);
        }

        return $arguments;
    }

    private function argumentFor(ReflectionParameter $parameter, User $actor, string $label): mixed
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        if ($type->allowsNull() && ! is_a($type->getName(), User::class, true)) {
            return null;
        }

        $name = $type->getName();

        return match (true) {
            $name === 'int' => 0,
            $name === 'string' => '',
            $name === 'bool' => false,
            $name === 'float' => 0.0,
            $name === 'array' => [],
            $name === 'callable', $name === \Closure::class => static fn () => null,
            is_a($name, User::class, true) => $actor,
            is_a($name, Model::class, true) => new $name,
            is_a($name, UploadedFile::class, true) => UploadedFile::fake()->createWithContent('kilde.pdf', 'x'),
            is_a($name, Collection::class, true) => new $name,
            is_a($name, Carbon::class, true), $name === \DateTimeInterface::class, $name === CarbonInterface::class => Carbon::now(),
            is_a($name, BackedEnum::class, true) => $name::cases()[0],
            class_exists($name) => (new ReflectionClass($name))->newInstanceWithoutConstructor(),
            default => $this->fail("{$label}: no test value for parameter \${$parameter->getName()} of type {$name}"),
        };
    }

    private function actor(Customer $customer, string $role): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Aktør '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@contract.test',
            'password' => bcrypt('secret'),
            'role' => $role,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ])->save();

        return $user;
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Kontrakt AS',
            'slug' => 'kontrakt-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
