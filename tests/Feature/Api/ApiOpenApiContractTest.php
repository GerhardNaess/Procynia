<?php

namespace Tests\Feature\Api;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\Kpi;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\Objective;
use App\Models\Risk;
use App\Models\RiskTreatmentAction;
use App\Services\Api\ApiErrorResponder;
use App\Services\Compliance\ComplianceAssessmentOverview;
use App\Services\Compliance\ComplianceAttentionService;
use App\Services\Compliance\ComplianceAuditAttentionService;
use App\Services\Compliance\ComplianceStatusResolver;
use App\Services\Improvements\ImprovementAttentionService;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as ReviewSections;
use App\Services\Objectives\KpiTarget;
use App\Services\Objectives\KpiTargetPolicy;
use App\Services\Objectives\ObjectiveAttentionService;
use App\Services\Risk\RiskAttentionService;
use App\Services\Risk\RiskScoringPolicy;
use App\Support\ApiOperationCatalog;
use App\Support\Objectives\KpiPresenter as KpiPagePresenter;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * docs/api/openapi.yaml describes the API that is actually there (docs/api/README.md).
 *
 *  - Every API route is in the spec with its method, and the spec has no path the route table lacks.
 *  - Every operation names the Procynia-Customer header. Write routes, and only they, require
 *    Procynia.Write.All, and every catalogued write operation is named in the spec.
 *  - The enums are the code's own values, and the error codes are ApiErrorResponder's.
 *
 * The spec is read line by line, not with a YAML parser (the project has none). That relies on its
 * layout: paths at two spaces, methods at four, schemas at four under components, and each enum on
 * one line as `enum: [a, b]`. A spec that breaks the layout fails here rather than passing unread.
 */
class ApiOpenApiContractTest extends TestCase
{
    private const SPEC = 'docs/api/openapi.yaml';

    /** Methods the route table adds by itself and the spec does not list. */
    private const IMPLIED_METHODS = ['HEAD'];

    public function test_the_spec_is_openapi_3_1(): void
    {
        $this->assertSame('openapi: 3.1.0', $this->lines()[0]);
    }

    public function test_the_spec_lists_exactly_the_api_routes_and_their_methods(): void
    {
        $routes = [];

        foreach ($this->apiRoutes() as $route) {
            foreach (array_diff($route->methods(), self::IMPLIED_METHODS) as $method) {
                $routes[] = strtolower($method).' /'.$route->uri();
            }
        }

        $documented = array_map(
            fn (array $operation): string => $operation['method'].' /api'.$operation['path'],
            $this->operations(),
        );

        $this->assertNotEmpty($routes);
        $this->assertEqualsCanonicalizing($routes, $documented);
    }

    public function test_every_operation_names_the_customer_header_and_write_routes_require_the_write_role(): void
    {
        $writePaths = [];

        foreach ($this->apiRoutes() as $route) {
            if (in_array('api.write', $route->gatherMiddleware(), true)) {
                $writePaths[] = '/'.$route->uri();
            }
        }

        foreach ($this->operations() as $operation) {
            $block = implode("\n", $operation['lines']);
            $label = $operation['method'].' '.$operation['path'];

            $this->assertStringContainsString("\$ref: '#/components/parameters/ProcyniaCustomer'", $block, "[{$label}] does not name Procynia-Customer.");

            if (in_array('/api'.$operation['path'], $writePaths, true)) {
                $this->assertStringContainsString('entraApplicationToken: [Procynia.Read.All, Procynia.Write.All]', $block, "Write operation [{$label}] does not require Procynia.Write.All.");
            } else {
                $this->assertStringNotContainsString('Procynia.Write.All', $block, "Read operation [{$label}] requires the write role.");
            }
        }

        $spec = implode("\n", $this->lines());

        foreach (ApiOperationCatalog::all() as $operation) {
            $this->assertStringContainsString("`{$operation}`", $spec, "Write operation [{$operation}] is not described.");
        }
    }

    public function test_the_enums_are_the_codes_own_values(): void
    {
        $levels = array_column((new RiskScoringPolicy)->criteria()['bands'], 'level');

        $expected = [
            'RiskStatus' => Risk::STATUSES,
            'RiskTreatmentStrategy' => Risk::TREATMENT_STRATEGIES,
            'RiskLevel' => $levels,
            'RiskAttentionCode' => RiskAttentionService::CATEGORIES,
            'RiskTreatmentActionStatus' => [RiskTreatmentAction::STATUS_OPEN],
            'RiskTreatmentActionRowStatus' => RiskTreatmentAction::STATUSES,
            'RiskAcceptanceState' => ['current', 'superseded', 'revoked'],
            'ImprovementCaseStatus' => ImprovementCase::STATUSES,
            'ImprovementAttentionCode' => array_keys(ImprovementAttentionService::CATEGORIES),
            'ImprovementActionStatus' => ImprovementAction::STATUSES,
            'ImprovementActionVerificationResult' => ImprovementActionVerification::RESULTS,
            'ObjectiveStatus' => Objective::STATUSES,
            'ObjectiveAttentionCode' => [ObjectiveAttentionService::TARGET_DATE_PASSED, ObjectiveAttentionService::OWNER_MISSING],
            'KpiStatus' => Kpi::STATUSES,
            'KpiUnit' => Kpi::UNITS,
            'KpiFrequency' => Kpi::FREQUENCIES,
            'KpiTargetType' => KpiTarget::TYPES,
            'KpiResult' => KpiTargetPolicy::RESULTS,
            'KpiCurrentResult' => [...KpiTargetPolicy::RESULTS, KpiPagePresenter::NOT_MEASURED],
            'KpiAttentionCode' => [ObjectiveAttentionService::KPI_OFF_TARGET, ObjectiveAttentionService::MEASUREMENT_MISSING],
            'ErrorCode' => array_keys(ApiErrorResponder::MESSAGES),
            'ComplianceRequirementStatus' => ComplianceRequirement::STATUSES,
            'ComplianceApplicability' => ComplianceRequirement::APPLICABILITIES,
            'ComplianceAssessmentResult' => ComplianceAssessment::RESULTS,
            'ComplianceResult' => [...ComplianceAssessment::RESULTS, ComplianceStatusResolver::STATUS_NOT_ASSESSED],
            'ComplianceOverviewGroup' => [...ComplianceAssessmentOverview::ASSESSABLE, ComplianceAssessmentOverview::NOT_APPLICABLE, ComplianceAssessmentOverview::RELEVANCE_OPEN],
            'ComplianceRequirementAttentionCode' => ComplianceAttentionService::REASONS,
            'ComplianceSourceKind' => ComplianceSource::KINDS,
            'ComplianceAuditStatus' => ComplianceAudit::STATUSES,
            'ComplianceAuditType' => ComplianceAudit::TYPES,
            'ComplianceAuditAttentionCode' => ComplianceAuditAttentionService::REASONS,
            'ComplianceFindingType' => ComplianceAuditFinding::TYPES,
            'ComplianceRelevance' => [ComplianceRequirement::APPLICABILITY_APPLIES, ComplianceAssessmentOverview::NOT_APPLICABLE, ComplianceAssessmentOverview::RELEVANCE_OPEN],
            'ComplianceFrameworkNodeKind' => [ComplianceFrameworkNode::KIND_SECTION, ComplianceFrameworkNode::KIND_REQUIREMENT],
            'ManagementReviewStatus' => ManagementReview::STATUSES,
            'ManagementReviewJudgement' => ManagementReview::JUDGEMENTS,
            'ManagementReviewSectionType' => [ReviewSections::TYPE_OWN, ReviewSections::TYPE_MANUAL, ReviewSections::TYPE_MODULE],
            'ManagementReviewSectionState' => [ReviewSections::STATE_AVAILABLE, ReviewSections::STATE_MODULE_UNAVAILABLE, ReviewSections::STATE_NO_ACCESS, ReviewSections::STATE_NOT_CAPTURED],
            'ManagementReviewDecisionKind' => ManagementReviewDecision::KINDS,
            'ManagementReviewDecisionFollowUp' => [ManagementReviewDecision::FOLLOW_UP_OWN, ManagementReviewDecision::FOLLOW_UP_IMPROVEMENT_CASE],
            'ManagementReviewDecisionStatus' => ManagementReviewDecision::STATUSES,
            // The keys of ApiAccessSummary::for() and the item types of ApiAttentionOverview.
            'ApiModule' => ['risk', 'deviations', 'objectives'],
            'AttentionItemType' => ['risk', 'deviation', 'improvement', 'objective', 'kpi', 'compliance_requirement', 'compliance_audit'],
        ];

        foreach ($expected as $schema => $values) {
            $this->assertSame($values, $this->enumOf($schema), "The enum of [{$schema}] differs from the code.");
        }
    }

    /** @return list<Route> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route): bool => str_starts_with($route->uri(), 'api/'),
        ));
    }

    /**
     * The operations under `paths:`, each with the lines of its block.
     *
     * @return list<array{path: string, method: string, lines: list<string>}>
     */
    private function operations(): array
    {
        $operations = [];
        $inPaths = false;
        $path = null;
        $current = null;

        foreach ($this->lines() as $line) {
            if (preg_match('/^\S/', $line) === 1) {
                $inPaths = $line === 'paths:';
                $path = $current = null;

                continue;
            }

            if (! $inPaths) {
                continue;
            }

            if (preg_match('#^  (/\S+):$#', $line, $match) === 1) {
                $path = $match[1];
                $current = null;

                continue;
            }

            if (preg_match('/^    (get|put|post|patch|delete|head|options|trace):$/', $line, $match) === 1) {
                $this->assertNotNull($path, "Method [{$match[1]}] outside a path.");
                $operations[] = ['path' => $path, 'method' => $match[1], 'lines' => []];
                $current = array_key_last($operations);

                continue;
            }

            if ($current !== null) {
                $operations[$current]['lines'][] = $line;
            }
        }

        $this->assertNotEmpty($operations, 'No operations found under paths: — has the layout changed?');

        return $operations;
    }

    /** @return list<string> the values of a named schema's one-line enum */
    private function enumOf(string $schema): array
    {
        $lines = $this->lines();
        $start = array_search("    {$schema}:", $lines, true);
        $this->assertNotFalse($start, "Schema [{$schema}] is not in the spec.");

        for ($i = $start + 1; $i < count($lines) && preg_match('/^ {0,4}\S/', $lines[$i]) !== 1; $i++) {
            if (preg_match('/^      enum: \[(.*)\]$/', $lines[$i], $match) === 1) {
                return array_map(fn (string $value): string => trim($value, " '\""), explode(',', $match[1]));
            }
        }

        $this->fail("Schema [{$schema}] has no one-line enum.");
    }

    /** @return list<string> */
    private function lines(): array
    {
        $path = base_path(self::SPEC);
        $this->assertFileExists($path);

        return preg_split('/\R/', (string) file_get_contents($path));
    }
}
