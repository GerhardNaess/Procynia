<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Registering a risk assessment — the one way an assessment comes into being. An assessment is
 * never edited or deleted; a correction is the next one.
 *
 * Assessing is a human decision (phase 0 step 9, docs/external-api-phase-0-plan.md §4.4): the
 * service refuses an integration and any request through the API (HumanDecisionGuard), and a
 * person without risk.assess in the risk's fagområde (RiskAccessService::canAssess), itself — so the
 * rule holds for every entry point, not only RiskAssessmentController. The check is made on the
 * risk as it stands under the row lock, so a risk moved to another area a moment ago is judged by
 * where it is now.
 */
class RiskAssessmentService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly RiskAccessService $access,
        private readonly RiskScoringPolicy $scoring,
    ) {}

    /**
     * @param  array{inherent_likelihood: int, inherent_consequence: int, residual_likelihood: ?int, residual_consequence: ?int}  $scores
     */
    public function record(User $actor, Risk $risk, array $scores, string $rationale): RiskAssessment
    {
        $actor = $this->guard->assert($actor, HumanDecision::RISK_ASSESS);

        return DB::transaction(function () use ($actor, $risk, $scores, $rationale): RiskAssessment {
            // Serialises against an acceptance of the latest assessment, and gives the risk as it is now.
            $current = Risk::query()->whereKey($risk->id)->lockForUpdate()->first();

            if ($current === null || ! $this->access->canAssess($actor, $current)) {
                throw new AuthorizationException('This user may not assess this risk.');
            }

            return RiskAssessment::query()->create([
                'customer_id' => (int) $current->customer_id,
                'risk_id' => (int) $current->id,
                'assessed_by' => $actor->id,
                'assessed_at' => now(),
                'rationale' => trim($rationale),
                // What was assessed, as the risk read at this moment. A later edit of the risk leaves it.
                'risk_cause' => $current->cause,
                'risk_event' => $current->event,
                'risk_consequence' => $current->consequence,
                'criteria_key' => $this->scoring->currentCriteriaKey(),
                'inherent_likelihood' => (int) $scores['inherent_likelihood'],
                'inherent_consequence' => (int) $scores['inherent_consequence'],
                'residual_likelihood' => isset($scores['residual_likelihood']) ? (int) $scores['residual_likelihood'] : null,
                'residual_consequence' => isset($scores['residual_consequence']) ? (int) $scores['residual_consequence'] : null,
            ]);
        });
    }
}
