<?php

namespace App\Services\ManagementReview\Sections;

use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use App\Services\ManagementReview\ReviewScope;
use App\Services\ManagementReview\SectionBuilder;
use App\Services\ManagementReview\SectionPayload;
use App\Services\Risk\RiskAccessService;
use App\Services\Risk\RiskAttentionService;
use App\Services\Risk\RiskScoringPolicy;

/**
 * Risiko. Read through RiskAccessService, per fagområde.
 *
 * In the period, only what Risiko keeps dated history of: risks registered, assessments (each
 * compared with the risk's assessment before it — the score the management sees move), acceptances
 * given, and tiltak completed. Now: the residual picture from each open risk's latest assessment, and
 * what Trenger oppmerksomhet flags.
 *
 * Not reconstructed: a risk's status, owner and treatment strategy have no history, so the section
 * never claims what they were at the period's end (limitation risk_status_not_historical). A reopened
 * tiltak loses its completion date, so completed tiltak may be under-counted
 * (limitation risk_actions_reopen).
 */
final class RisksSection implements SectionBuilder
{
    private const HIGH_LEVELS = [RiskScoringPolicy::LEVEL_HIGH, RiskScoringPolicy::LEVEL_VERY_HIGH];

    public function __construct(
        private readonly RiskAccessService $access,
        private readonly RiskAttentionService $attention,
        private readonly RiskScoringPolicy $scoring,
    ) {}

    public function build(User $user, ReviewScope $scope, ?array $areaIds): array
    {
        $payload = (new SectionPayload(true, (int) config('management_review.list_limit', 50)))
            ->metrics('period', ['risks_registered', 'assessments', 'risks_increased', 'risks_decreased', 'acceptances_given', 'actions_completed'])
            ->metrics('status', ['risks_open', 'level_very_high', 'level_high', 'level_moderate', 'level_low', 'residual_not_assessed', 'not_assessed', 'review_overdue', 'acceptance_expired', 'actions_overdue'])
            ->list('high_risks')
            ->list('changed_risks')
            ->note('risk_status_not_historical')
            ->note('risk_actions_reopen');

        $risks = $this->access->visibleRisks($user)
            ->whereIn('risks.business_area_id', $areaIds === [] || $areaIds === null ? [0] : $areaIds)
            ->with(['businessArea:id,name', 'owner:id,name'])
            ->orderBy('risks.title')
            ->orderBy('risks.id')
            ->get(['risks.*']);

        foreach ($risks as $risk) {
            $payload->area((int) $risk->business_area_id, (string) $risk->businessArea?->name);
        }

        $byId = $risks->keyBy('id');
        $ids = $risks->modelKeys();
        $areaOf = fn (int $riskId): int => (int) $byId->get($riskId)?->business_area_id;

        foreach ($risks as $risk) {
            if ($scope->inPeriod($risk->created_at)) {
                $payload->count('period', 'risks_registered', 1, (int) $risk->business_area_id);
            }
        }

        // Every assessment up to the end of the period, oldest first, so each one in the period can be
        // compared with the one before it.
        $assessments = RiskAssessment::query()
            ->where('customer_id', $scope->customerId)
            ->whereIn('risk_id', $ids ?: [0])
            ->where('assessed_at', '<', $scope->until())
            ->orderBy('assessed_at')
            ->orderBy('id')
            ->get()
            ->groupBy('risk_id');

        $changed = [];

        foreach ($assessments as $riskId => $rows) {
            $before = null;
            $first = null;
            $last = null;

            foreach ($rows as $assessment) {
                if (! $scope->inPeriod($assessment->assessed_at)) {
                    $before = $assessment;

                    continue;
                }

                $payload->count('period', 'assessments', 1, $areaOf((int) $riskId));
                $first ??= $before;
                $last = $assessment;
            }

            if ($last === null || $first === null) {
                continue;
            }

            $from = $this->score($first);
            $to = $this->score($last);

            if ($from === null || $to === null || $from['score'] === $to['score']) {
                continue;
            }

            $payload->count('period', $to['score'] > $from['score'] ? 'risks_increased' : 'risks_decreased', 1, $areaOf((int) $riskId));
            $changed[] = ['risk' => $byId->get($riskId), 'from' => $from, 'to' => $to];
        }

        usort($changed, fn (array $a, array $b): int => ($b['to']['score'] - $b['from']['score']) <=> ($a['to']['score'] - $a['from']['score']));

        foreach ($changed as $row) {
            $payload->item('changed_risks', [
                'id' => (int) $row['risk']->id,
                'title' => $row['risk']->title,
                'url' => route('app.risk.show', ['riskId' => $row['risk']->id], false),
                'fields' => SectionPayload::fields([
                    'area' => ['text', $row['risk']->businessArea?->name],
                    'score_from' => ['number', $row['from']['score']],
                    'level_from' => ['enum', $row['from']['level']],
                    'score_to' => ['number', $row['to']['score']],
                    'level_to' => ['enum', $row['to']['level']],
                ]),
            ], (int) $row['risk']->business_area_id);
        }

        RiskAcceptance::query()
            ->where('customer_id', $scope->customerId)
            ->whereIn('risk_id', $ids ?: [0])
            ->where('accepted_at', '>=', $scope->from())
            ->where('accepted_at', '<', $scope->until())
            ->get(['risk_id'])
            ->each(fn (RiskAcceptance $acceptance) => $payload->count('period', 'acceptances_given', 1, $areaOf((int) $acceptance->risk_id)));

        RiskTreatmentAction::query()
            ->where('customer_id', $scope->customerId)
            ->whereIn('risk_id', $ids ?: [0])
            ->where('completed_at', '>=', $scope->from())
            ->where('completed_at', '<', $scope->until())
            ->get(['risk_id'])
            ->each(fn (RiskTreatmentAction $action) => $payload->count('period', 'actions_completed', 1, $areaOf((int) $action->risk_id)));

        $open = $risks->reject(fn (Risk $risk): bool => $risk->status === Risk::STATUS_CLOSED)->values();
        $latest = RiskAssessment::latestForRisks($scope->customerId, $open->modelKeys());
        $findings = $this->attention->findingsForRisks($open, $scope->today);
        $high = [];

        foreach ($open as $risk) {
            $areaId = (int) $risk->business_area_id;
            $payload->count('status', 'risks_open', 1, $areaId);
            $assessment = $latest->get((int) $risk->id);

            if ($assessment === null) {
                $payload->count('status', 'not_assessed', 1, $areaId);
            } elseif (! $assessment->hasResidual()) {
                $payload->count('status', 'residual_not_assessed', 1, $areaId);
            } else {
                $residual = $this->scoring->evaluate($assessment->residual_likelihood, $assessment->residual_consequence, $assessment->criteria_key);
                $payload->count('status', 'level_'.$residual['level'], 1, $areaId);

                if (in_array($residual['level'], self::HIGH_LEVELS, true)) {
                    $high[] = ['risk' => $risk, 'residual' => $residual];
                }
            }

            foreach ([RiskAttentionService::REVIEW_OVERDUE, RiskAttentionService::ACCEPTANCE_EXPIRED, RiskAttentionService::ACTIONS_OVERDUE] as $key) {
                if (isset($findings[(int) $risk->id][$key])) {
                    $payload->count('status', $key, 1, $areaId);
                }
            }
        }

        usort($high, fn (array $a, array $b): int => [$b['residual']['score'], $a['risk']->title] <=> [$a['residual']['score'], $b['risk']->title]);

        foreach ($high as $row) {
            $payload->item('high_risks', [
                'id' => (int) $row['risk']->id,
                'title' => $row['risk']->title,
                'url' => route('app.risk.show', ['riskId' => $row['risk']->id], false),
                'fields' => SectionPayload::fields([
                    'area' => ['text', $row['risk']->businessArea?->name],
                    'level' => ['enum', $row['residual']['level']],
                    'score' => ['number', $row['residual']['score']],
                    'owner' => ['text', $row['risk']->owner?->name],
                ]),
            ], (int) $row['risk']->business_area_id);
        }

        return $payload->toArray();
    }

    /**
     * The score management looks at: residual when assessed, inherent otherwise.
     *
     * @return array{score: int, level: string}|null
     */
    private function score(?RiskAssessment $assessment): ?array
    {
        if ($assessment === null) {
            return null;
        }

        $result = $assessment->hasResidual()
            ? $this->scoring->evaluate($assessment->residual_likelihood, $assessment->residual_consequence, $assessment->criteria_key)
            : $this->scoring->evaluate($assessment->inherent_likelihood, $assessment->inherent_consequence, $assessment->criteria_key);

        return ['score' => $result['score'], 'level' => $result['level']];
    }
}
