<?php

namespace App\Services\Api\Presenters;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceAudit;
use App\Models\ComplianceAuditFinding;
use App\Models\ComplianceFramework;
use App\Models\ComplianceFrameworkNode;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceSource;
use App\Models\User;
use App\Services\Compliance\ComplianceAssessmentOverview;
use App\Services\Compliance\ComplianceAttentionService;
use App\Services\Compliance\ComplianceAuditAttentionService;
use App\Services\Compliance\ComplianceAuditCheckService;
use App\Services\Compliance\ComplianceAuditFindingService;
use App\Services\Compliance\ComplianceAuditScopeService;
use App\Services\Compliance\ComplianceStatusResolver;
use App\Services\Compliance\Library\ComplianceFrameworkPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Etterlevelse og revisjon as the external API presents it: structured fields only (D8).
 *
 * In: requirements with their source, reference, title, status, relevance, ansvarlig, review
 * interval, compliance status and attention codes, and their assessment history (result and date);
 * the status per source; audits with type, status, ansvarlig, planned dates, scope, kontrollhandlinger
 * and findings (titles, types, links between them); and the requirement library. Out: requirement
 * texts, relevance rationales, assessment rationales and documentation, source descriptions,
 * audit purpose, scope description and conclusion, the auditor's name, observations, evidence,
 * finding descriptions, every status history and note, every person who assessed, recorded or
 * handed off, the Kvalitet context (processes and controls), the library's descriptions and
 * guidance, and web routes.
 *
 * Nothing is judged here. The compliance status is ComplianceStatusResolver's, the overview group
 * and the counts per source are ComplianceAssessmentOverview's (as on Etterlevelsesvurdering), the
 * attention codes are ComplianceAttentionService's and ComplianceAuditAttentionService's, the
 * audit's scope, kontrollhandlinger and findings are the audit page's own services, and the library
 * is ComplianceFrameworkPresenter's.
 */
class CompliancePresenter
{
    public function __construct(
        private readonly ComplianceStatusResolver $status,
        private readonly ComplianceAssessmentOverview $overview,
        private readonly ComplianceAttentionService $attention,
        private readonly ComplianceAuditAttentionService $auditAttention,
        private readonly ComplianceAuditScopeService $scope,
        private readonly ComplianceAuditCheckService $checks,
        private readonly ComplianceAuditFindingService $findings,
        private readonly ComplianceFrameworkPresenter $library,
    ) {}

    /**
     * @param  Collection<int, ComplianceRequirement>  $requirements  already reached through ComplianceAccessService::visibleRequirements(),
     *                                                                with source, owner and frameworkNode loaded
     * @return list<array<string, mixed>>
     */
    public function requirementRows(Collection $requirements, CarbonImmutable $today): array
    {
        if ($requirements->isEmpty()) {
            return [];
        }

        $latest = $this->status->latestForRequirements((int) $requirements->first()->customer_id, $requirements->modelKeys());
        $reasons = $this->attention->reasonsForRequirements($requirements, $latest, $today);

        return $requirements
            ->map(fn (ComplianceRequirement $requirement): array => $this->requirementRow($requirement, $latest->get((int) $requirement->id), $reasons[(int) $requirement->id] ?? [], $today))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> one requirement with its assessment history, newest first */
    public function requirementDetail(ComplianceRequirement $requirement, CarbonImmutable $today): array
    {
        return $this->requirementRows(new Collection([$requirement]), $today)[0] + [
            'assessments' => $requirement->assessments()
                ->where('customer_id', $requirement->customer_id)
                ->get()
                ->map(fn (ComplianceAssessment $assessment): array => [
                    'id' => (int) $assessment->id,
                    'result' => $assessment->result,
                    'assessed_at' => $assessment->assessed_at?->utc()->toIso8601ZuluString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The compliance status of the active requirements, in total and per source — Etterlevelsesvurdering's
     * counts, by ComplianceAssessmentOverview's groups.
     *
     * @param  Collection<int, ComplianceRequirement>  $requirements  the active visible requirements, with source and frameworkNode loaded
     * @param  Collection<int, ComplianceSource>  $sources  the visible sources
     * @return array{total: array<string, int>, per_source: list<array<string, mixed>>}
     */
    public function status(Collection $requirements, Collection $sources, CarbonImmutable $today): array
    {
        $latest = $requirements->isEmpty() ? collect() : $this->status->latestForRequirements((int) $requirements->first()->customer_id, $requirements->modelKeys());
        $total = $this->overview->emptyStats();
        $perSource = [];

        foreach ($requirements as $requirement) {
            $summary = $this->status->summarize($requirement, $latest->get((int) $requirement->id), $today);
            $group = $this->overview->group($requirement, $latest->get((int) $requirement->id));
            $perSource[(int) $requirement->source_id] ??= $this->overview->emptyStats();
            $this->overview->count($perSource[(int) $requirement->source_id], $group, (bool) $summary['is_overdue']);
            $this->overview->count($total, $group, (bool) $summary['is_overdue']);
        }

        return [
            'total' => $total,
            // One row per source that has active requirements, in the register's order of sources.
            'per_source' => $sources
                ->filter(fn (ComplianceSource $source): bool => isset($perSource[(int) $source->id]))
                ->map(fn (ComplianceSource $source): array => [
                    'source' => $this->source($source),
                    'stats' => $perSource[(int) $source->id],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, ComplianceAudit>  $audits  already reached through ComplianceAccessService::visibleAudits(),
     *                                                    with responsible and the scope counts loaded
     * @return list<array<string, mixed>>
     */
    public function auditRows(Collection $audits, CarbonImmutable $today): array
    {
        $reasons = $this->auditAttention->reasonsForAudits($audits, $today);

        return $audits
            ->map(fn (ComplianceAudit $audit): array => $this->auditRow($audit, $reasons[(int) $audit->id] ?? []))
            ->values()
            ->all();
    }

    /**
     * One audit with its scope, kontrollhandlinger and findings, as the audit page's services read
     * them for the identity — so a case in Avvik og forbedringer is named only when the identity may
     * read it there.
     *
     * @return array<string, mixed>
     */
    public function auditDetail(User $identity, ComplianceAudit $audit, CarbonImmutable $today): array
    {
        $findingRows = $this->findings->rows($identity, $audit, false);
        $caseIds = ComplianceAuditFinding::query()
            ->where('audit_id', $audit->id)
            ->where('customer_id', $audit->customer_id)
            ->pluck('improvement_case_id', 'id');

        return $this->auditRows(new Collection([$audit]), $today)[0] + [
            'requirements' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'reference' => $row['reference'],
                'title' => $row['title'],
                'status' => $row['status'],
                'relevance' => $row['relevance'],
                'is_control' => $row['is_control'],
                'source_id' => $row['source_id'],
                'check_count' => $row['check_count'],
                'finding_count' => $row['finding_count'],
            ], $this->scope->requirements($audit)),
            'checks' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'title' => $row['title'],
                'requirement_ids' => array_column($row['requirements'], 'id'),
                'finding_ids' => array_column($row['findings'], 'id'),
            ], $this->checks->rows($identity, $audit)),
            'findings' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'finding_type' => $row['finding_type'],
                'title' => $row['title'],
                'requirement_id' => $row['requirement']['id'] ?? null,
                'check_id' => $row['check']['id'] ?? null,
                'improvement_type' => $row['improvement_type'],
                'handed_off' => $row['handed_off'],
                'handed_off_at' => $row['handed_off_at'] !== null ? CarbonImmutable::parse($row['handed_off_at'])->utc()->toIso8601ZuluString() : null,
                // Only a case the identity can read today; the audit page gives it a link on the same rule.
                'improvement_case_id' => $row['case_link'] !== null ? (int) $caseIds[$row['id']] : null,
            ], $findingRows),
        ];
    }

    /**
     * The requirement library: every standard, and whether the customer has activated it.
     *
     * @param  Collection<int, ComplianceFramework>  $frameworks  with the requirement node counts loaded
     * @param  \Illuminate\Support\Collection<int, ComplianceSource>  $activated  the customer's activations, keyed by framework id
     * @return list<array<string, mixed>>
     */
    public function frameworks(Collection $frameworks, $activated): array
    {
        return $frameworks
            ->map(fn (ComplianceFramework $framework): array => $this->framework($framework, $activated->get((int) $framework->id)) + [
                'requirement_count' => (int) $framework->requirement_nodes_count,
                'has_controls' => (int) $framework->control_nodes_count > 0,
            ])
            ->values()
            ->all();
    }

    /**
     * One standard with its tree of chapters and requirements, and for each requirement the
     * customer's register entry when it has activated the standard.
     *
     * @return array<string, mixed>
     */
    public function frameworkDetail(User $identity, ComplianceFramework $framework, ?ComplianceSource $source): array
    {
        $nodes = $framework->nodes()->get();
        $register = $source !== null ? $this->library->register($source, $identity) : [];

        return $this->framework($framework, $source) + [
            'requirement_count' => $nodes->filter(fn (ComplianceFrameworkNode $node): bool => $node->isRequirement() && ! $node->isWithdrawn())->count(),
            'has_controls' => $nodes->contains(fn (ComplianceFrameworkNode $node): bool => $node->isControl() && ! $node->isWithdrawn()),
            'nodes' => array_map(fn (array $node): array => [
                'id' => $node['id'],
                'parent_id' => $node['parent_id'],
                'clause' => $node['clause'],
                'kind' => $node['kind'],
                'depth' => $node['depth'],
                'title' => $node['title'],
                'requirement_type' => $node['requirement_type'] ?? null,
                'applicability_rule' => $node['applicability_rule'] ?? null,
                'withdrawn' => $node['withdrawn'] ?? false,
                'requirement_id' => $node['requirement']['id'] ?? null,
            ], $this->library->nodes($nodes, $identity, $register)),
        ];
    }

    /**
     * @param  list<string>  $attention
     * @return array<string, mixed>
     */
    private function requirementRow(ComplianceRequirement $requirement, ?ComplianceAssessment $latest, array $attention, CarbonImmutable $today): array
    {
        $summary = $this->status->summarize($requirement, $latest, $today);

        return [
            'id' => (int) $requirement->id,
            'reference' => $requirement->reference,
            'title' => $requirement->title,
            'status' => $requirement->status,
            'source' => $requirement->source !== null ? $this->source($requirement->source) : null,
            'from_library' => $requirement->isFromLibrary(),
            'is_control' => (bool) $requirement->frameworkNode?->isControl(),
            // Relevans or Kontrollvalg for a library requirement; null for one registered by hand.
            'applicability' => $requirement->isFromLibrary() ? $requirement->applicability : null,
            'owner' => $requirement->owner_user_id !== null
                ? ['id' => (int) $requirement->owner_user_id, 'name' => $requirement->owner?->name]
                : null,
            'review_interval_months' => $requirement->review_interval_months,
            'compliance' => [
                'status' => $summary['status'],
                'group' => $this->overview->group($requirement, $latest),
                'last_assessed_at' => $latest?->assessed_at?->utc()->toIso8601ZuluString(),
                'next_review_on' => $summary['next_review_on'],
                'is_overdue' => (bool) $summary['is_overdue'],
                'changed_since_assessment' => (bool) $summary['changed_since_assessment'],
            ],
            'attention' => $attention,
            'created_at' => $requirement->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $requirement->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  list<string>  $attention
     * @return array<string, mixed>
     */
    private function auditRow(ComplianceAudit $audit, array $attention): array
    {
        return [
            'id' => (int) $audit->id,
            'title' => $audit->title,
            'audit_type' => $audit->audit_type,
            'status' => $audit->status,
            'responsible' => $audit->responsible_user_id !== null
                ? ['id' => (int) $audit->responsible_user_id, 'name' => $audit->responsible?->name]
                : null,
            'planned_start_date' => $audit->planned_start_date?->toDateString(),
            'planned_end_date' => $audit->planned_end_date?->toDateString(),
            'counts' => [
                'requirements' => (int) $audit->requirement_links_count,
                'checks' => (int) $audit->checks_count,
                'findings' => (int) $audit->findings_count,
            ],
            'attention' => $attention,
            'created_at' => $audit->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $audit->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /** @return array{id: int, label: string, kind: string, from_library: bool} */
    private function source(ComplianceSource $source): array
    {
        return [
            'id' => (int) $source->id,
            'label' => $source->originLabel(),
            'kind' => $source->kind,
            'from_library' => $source->isFromLibrary(),
        ];
    }

    /** @return array<string, mixed> */
    private function framework(ComplianceFramework $framework, ?ComplianceSource $source): array
    {
        $row = $this->library->framework($framework);

        return [
            'id' => $row['id'],
            'key' => $row['key'],
            'reference' => $row['reference'],
            'edition' => $row['edition'],
            'label' => $row['label'],
            'name' => $row['name'],
            'content_verified' => $row['content_verified'],
            // The customer's source for it, when activated.
            'source_id' => $source !== null ? (int) $source->id : null,
        ];
    }
}
