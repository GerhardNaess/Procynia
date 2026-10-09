<?php

namespace App\Services\EnterpriseWiki\Knowledge\Sources;

use App\Data\EnterpriseWiki\WikiKnowledgeDraft;
use App\Data\EnterpriseWiki\WikiKnowledgeDraftSection;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\WikiKnowledgeSource;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskControlService;
use App\Services\Risk\RiskScoringPolicy;
use App\Services\Risk\RiskTreatmentService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Risiko → Wiki. A risk is sensitive, so nothing is preselected — not even a title: the person sees
 * what the risk can contribute and opts each part in. Controls come from Kvalitet and are offered only to someone who
 * may read Kvalitet — the same rule the risk page itself follows. Names of people are never offered.
 */
class RiskKnowledgeSource implements WikiKnowledgeSource
{
    public const MODEL = Risk::class;

    public function __construct(
        private readonly RiskAccessService $access,
        private readonly RiskControlService $controls,
        private readonly RiskTreatmentService $treatments,
        private readonly RiskAcceptanceService $acceptances,
        private readonly RiskScoringPolicy $scoring,
    ) {}

    public function sourceType(): string
    {
        return 'risk';
    }

    public function sourceModule(): string
    {
        return 'risk';
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
        return $this->canOpenModule($user) ? $this->access->findVisible($user, $sourceId) : null;
    }

    public function canHandOff(User $user, Model $source): bool
    {
        return $source instanceof Risk && $this->access->can($user, CustomerPermissionCatalog::RISK_EDIT, $source);
    }

    public function draft(User $user, Model $source): WikiKnowledgeDraft
    {
        /** @var Risk $source */
        $t = 'procynia.knowledge_handoff.sources.risk';
        $latest = $source->assessments()->reorder()->latest('assessed_at')->latest('id')->first();

        $description = array_values(array_filter([
            filled($source->cause) ? __("{$t}.cause").': '.$source->cause : null,
            filled($source->event) ? __("{$t}.event").': '.$source->event : null,
            filled($source->consequence) ? __("{$t}.consequence").': '.$source->consequence : null,
        ]));

        $treatment = array_values(array_filter([
            filled($source->treatment_strategy)
                ? __("{$t}.strategy").': '.__('procynia.risk.treatment_strategy.options.'.$source->treatment_strategy)
                : null,
            ...array_map(
                fn (array $action): string => $action['title']
                    .' ('.__($action['status'] === 'completed' ? 'procynia.risk.treatment.status_completed' : 'procynia.risk.treatment.status_open').')'
                    .(filled($action['outcome_note']) ? ' — '.$action['outcome_note'] : ''),
                $this->treatments->actionsFor($source),
            ),
        ]));

        $acceptance = $this->acceptances->decisionFor($source)['current'] ?? null;

        // No suggested title: even the risk's name is something the person opts into by typing it.
        return new WikiKnowledgeDraft('', [
            new WikiKnowledgeDraftSection('description', __("{$t}.description_heading"), $description),
            new WikiKnowledgeDraftSection('assessment', __("{$t}.assessment_heading"), $this->assessmentLines($latest)),
            new WikiKnowledgeDraftSection(
                'controls',
                __("{$t}.controls_heading"),
                $this->controls->canReadQuality($user)
                    ? array_map(static fn (array $control): string => $control['title'], $this->controls->linkedControls($source))
                    : [],
            ),
            new WikiKnowledgeDraftSection('treatment', __("{$t}.treatment_heading"), $treatment),
            new WikiKnowledgeDraftSection(
                'acceptance',
                __("{$t}.acceptance_heading"),
                $acceptance !== null && filled($acceptance['rationale']) ? [(string) $acceptance['rationale']] : [],
                asList: false,
            ),
        ]);
    }

    public function storeUrl(Model $source): string
    {
        return route('app.risk.knowledge-handoff.store', ['sourceId' => $source->getKey()], false);
    }

    /** @return list<string> */
    private function assessmentLines(?RiskAssessment $assessment): array
    {
        if (! $assessment instanceof RiskAssessment) {
            return [];
        }

        $t = 'procynia.knowledge_handoff.sources.risk';
        $lines = [];

        try {
            $inherent = $this->scoring->evaluate((int) $assessment->inherent_likelihood, (int) $assessment->inherent_consequence, $assessment->criteria_key);
            $lines[] = __("{$t}.inherent").': '.__('procynia.risk.assessment.levels.'.$inherent['level']);

            if ($assessment->hasResidual()) {
                $residual = $this->scoring->evaluate((int) $assessment->residual_likelihood, (int) $assessment->residual_consequence, $assessment->criteria_key);
                $lines[] = __("{$t}.residual").': '.__('procynia.risk.assessment.levels.'.$residual['level']);
            }
        } catch (Throwable) {
            // A legacy assessment outside today's scale contributes its rationale only.
        }

        if (filled($assessment->rationale)) {
            $lines[] = __("{$t}.rationale").': '.$assessment->rationale;
        }

        return $lines;
    }
}
