<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementStatusChange;
use App\Models\User;
use App\Services\Compliance\ComplianceAccessService;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;

/**
 * Endringer i interne og eksterne forhold — the supporting basis. The section itself is the
 * management's own text; where the customer runs Etterlevelse og revisjon, the requirements and
 * sources (laws, standards, contracts) that came or went in the period are shown beside it, because
 * a changed obligation is a changed condition. Gated by compliance.view like the module.
 */
final class ContextChangesSection implements SectionBuilder
{
    public function __construct(
        private readonly ComplianceAccessService $access,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $payload = (new SectionPayload(false, (int) config('management_review.list_limit', 50)))
            ->metrics('period', ['sources_added', 'requirements_added', 'requirements_retired'])
            ->list('requirement_changes');

        $payload->count('period', 'sources_added', $this->access->visibleSources($user)
            ->where('compliance_sources.created_at', '>=', $scope->from())
            ->where('compliance_sources.created_at', '<', $scope->until())
            ->count());

        $added = $this->access->visibleRequirements($user)
            ->where('compliance_requirements.created_at', '>=', $scope->from())
            ->where('compliance_requirements.created_at', '<', $scope->until())
            ->with('source:id,name,version')
            ->orderBy('compliance_requirements.created_at')
            ->get(['compliance_requirements.*']);

        $retired = ComplianceRequirementStatusChange::query()
            ->whereIn('requirement_id', $this->access->visibleRequirements($user)->select('compliance_requirements.id'))
            ->where('to_status', ComplianceRequirement::STATUS_RETIRED)
            ->where('changed_at', '>=', $scope->from())
            ->where('changed_at', '<', $scope->until())
            ->with('requirement.source:id,name,version')
            ->orderBy('changed_at')
            ->get();

        foreach ($added as $requirement) {
            $payload->count('period', 'requirements_added');
            $payload->item('requirement_changes', $this->row($requirement, 'requirement_added', $requirement->created_at?->toDateString()));
        }

        foreach ($retired as $change) {
            $payload->count('period', 'requirements_retired');
            $payload->item('requirement_changes', $this->row($change->requirement, 'requirement_retired', $change->changed_at?->toDateString()));
        }

        return $payload->toArray();
    }

    /** @return array<string, mixed> */
    private function row(ComplianceRequirement $requirement, string $change, ?string $on): array
    {
        return [
            'id' => (int) $requirement->id,
            'title' => trim(($requirement->reference ? $requirement->reference.' ' : '').$requirement->title),
            'url' => route('app.compliance.requirements.show', ['requirementId' => $requirement->id], false),
            'fields' => SectionPayload::fields([
                'source' => ['text', trim($requirement->source?->name.' '.$requirement->source?->version)],
                'change' => ['enum', $change],
                'changed_on' => ['date', $on],
            ]),
        ];
    }
}
