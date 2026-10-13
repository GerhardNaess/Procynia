<?php

namespace App\Services\EnterpriseWiki\Knowledge\Sources;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\Supplier;
use App\Models\SupplierAssessment;
use App\Models\SupplierRequirementEvaluation;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSource;
use App\Services\Suppliers\Assurance\SupplierRequirementStatus;
use App\Services\Suppliers\SupplierAccessService;
use Illuminate\Database\Eloquent\Model;

/**
 * Leverandøroppfølging → Wiki. What is reusable about a supplier is how it was assured: what kind
 * of supplier it is, which control requirements applied and how each was documented, and the
 * current leverandørvurdering with its reasoning. Gated on supplier.assure, the permission that owns
 * that work. Contact details and names of people are never offered.
 *
 * The retired kontrollbeslutning is not offered: it is history, not what holds now, and a draft
 * must not present it as current.
 */
class SupplierKnowledgeSource implements WikiKnowledgeSource
{
    public const MODEL = Supplier::class;

    public function __construct(private readonly SupplierAccessService $access) {}

    public function sourceType(): string
    {
        return 'supplier';
    }

    public function sourceModule(): string
    {
        return 'supplier';
    }

    public function modelClass(): string
    {
        return self::MODEL;
    }

    public function canOpenModule(User $user): bool
    {
        return $this->access->canOpenModule($user);
    }

    public function findForUser(User $user, int $sourceId): ?Model
    {
        return $this->canOpenModule($user) ? $this->access->findVisibleSupplier($user, $sourceId) : null;
    }

    public function canHandOff(User $user, Model $source): bool
    {
        return $source instanceof Supplier && $this->access->canAssure($user);
    }

    public function draft(User $user, Model $source): WikiKnowledgeDraft
    {
        /** @var Supplier $source */
        $t = 'procynia.knowledge_handoff.sources.supplier';

        $profile = array_values(array_filter([
            filled($source->category) ? __("{$t}.category").': '.__("procynia.supplier_management.categories.{$source->category}") : null,
            filled($source->deliverable_description) ? __("{$t}.deliverable").': '.$source->deliverable_description : null,
            $source->criticality !== null ? __("{$t}.criticality").': '.__("{$t}.criticalities.{$source->criticality}") : null,
        ]));

        // The control in force for each requirement — the same rule as the supplier page (latest
        // evaluated_on, then id), never the latest recorded — read from its own snapshot of the
        // requirement, so a backdated control does not replace the one in force.
        $requirements = $source->requirementEvaluations()
            ->reorder()
            ->orderBy('requirement_id')
            ->get()
            ->groupBy('requirement_id')
            ->map(fn ($evaluations): ?SupplierRequirementEvaluation => SupplierRequirementStatus::current($evaluations))
            ->filter()
            ->map(fn (SupplierRequirementEvaluation $evaluation): string => $evaluation->requirement_title
                .': '.__("{$t}.evaluation_statuses.{$evaluation->status}")
                .(filled($evaluation->rationale) ? ' — '.$evaluation->rationale : ''))
            ->values()
            ->all();

        // The assessment in force: the newest by assessed_on, then id — as on the supplier page.
        $assessment = $source->assessments()->reorder()->latest('assessed_on')->latest('id')->first();

        $assessmentLines = $assessment instanceof SupplierAssessment
            ? array_values(array_filter([
                __("{$t}.assessment").': '.__("{$t}.results.{$assessment->overall_result}").' ('.$assessment->assessed_on?->toDateString().')',
                filled($assessment->rationale) ? __("{$t}.rationale").': '.$assessment->rationale : null,
            ]))
            : [];

        return new WikiKnowledgeDraft($source->name, [
            new WikiKnowledgeDraftSection('profile', __("{$t}.profile_heading"), $profile),
            new WikiKnowledgeDraftSection('requirements', __("{$t}.requirements_heading"), $requirements),
            new WikiKnowledgeDraftSection('assessment', __("{$t}.assessment_heading"), $assessmentLines),
        ]);
    }

    public function storeUrl(Model $source): string
    {
        return route('app.supplier-management.knowledge-handoff.store', ['sourceId' => $source->getKey()], false);
    }
}
