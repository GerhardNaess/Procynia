<?php

namespace App\Services\Api\Presenters;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Services\Risk\RiskAcceptanceService;
use App\Services\Risk\RiskAttentionService;
use App\Services\Risk\RiskReviewSchedule;
use App\Services\Risk\RiskScoringPolicy;
use App\Services\Risk\RiskTreatmentService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A risk as the external API presents it: structured fields only (D8).
 *
 * In: identifiers, title, status, behandlingsvalg, fagområde, eier, the latest assessment's
 * inherent and residual scoring, the periodic review and the reasons it needs attention, as codes.
 * The detail adds the assessment history (scoring and date), the tiltak (title, status, ansvarlig,
 * frist) and the acceptance of residual risk (dates and state). Out: årsak, hendelse, konsekvens,
 * utfyllende informasjon, begrunnelser, tiltak results and every other free text, who assessed,
 * accepted or revoked, the Kvalitet links (controls, process context), the supplier origin and web
 * routes.
 *
 * Nothing is computed here that the web does not compute the same way: the latest assessment is
 * RiskAssessment::latestForRisks(), the levels are RiskScoringPolicy, the review dates are
 * RiskReviewSchedule, the attention codes are RiskAttentionService's, the tiltak are
 * RiskTreatmentService::actionsFor() and the acceptance is RiskAcceptanceService::decisionFor() —
 * the same services the register and the risk page use.
 */
class RiskPresenter
{
    public function __construct(
        private readonly RiskScoringPolicy $scoring,
        private readonly RiskReviewSchedule $schedule,
        private readonly RiskAttentionService $attention,
        private readonly RiskTreatmentService $treatments,
        private readonly RiskAcceptanceService $acceptances,
    ) {}

    /**
     * @param  Collection<int, Risk>  $risks  already reached through RiskAccessService::visibleRisks()
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $risks, int $customerId, CarbonInterface $today): array
    {
        $latest = RiskAssessment::latestForRisks(
            $customerId,
            $risks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
        $findings = $this->attention->findingsForRisks($risks, $today);

        return $risks
            ->map(fn (Risk $risk): array => $this->row(
                $risk,
                $latest->get((int) $risk->id),
                array_keys($findings[(int) $risk->id] ?? []),
                $today,
            ))
            ->values()
            ->all();
    }

    /**
     * One risk with its history and follow-up.
     *
     * @param  Risk  $risk  already reached through RiskAccessService::findVisible(), with businessArea and owner loaded
     * @return array<string, mixed>
     */
    public function detail(Risk $risk, CarbonInterface $today): array
    {
        $acceptance = $this->acceptances->decisionFor($risk);

        return $this->rows(collect([$risk]), (int) $risk->customer_id, $today)[0] + [
            // Newest first, as on the risk page. Scoring and date only: no rationale, no assessor.
            'assessments' => $risk->assessments()
                ->where('customer_id', $risk->customer_id)
                ->get()
                ->map(fn (RiskAssessment $assessment): array => [
                    'id' => (int) $assessment->id,
                    'assessed_at' => $assessment->assessed_at?->utc()->toIso8601ZuluString(),
                    'inherent' => $this->scoring->evaluate($assessment->inherent_likelihood, $assessment->inherent_consequence, $assessment->criteria_key),
                    'residual' => $assessment->hasResidual()
                        ? $this->scoring->evaluate($assessment->residual_likelihood, $assessment->residual_consequence, $assessment->criteria_key)
                        : null,
                ])
                ->values()
                ->all(),
            // The risk page's order: open by frist, then completed, most recently completed first.
            'treatment_actions' => array_map(fn (array $action): array => [
                'id' => $action['id'],
                'title' => $action['title'],
                'status' => $action['status'],
                'owner' => $action['owner_user_id'] !== null ? ['id' => $action['owner_user_id'], 'name' => $action['owner_name']] : null,
                'due_at' => $action['due_at'],
                'completed_at' => $this->utc($action['completed_at']),
                'is_overdue' => $action['is_overdue'],
            ], $this->treatments->actionsFor($risk)),
            'acceptance' => [
                'current' => $acceptance['current'] !== null ? $this->acceptance($acceptance['current']) : null,
                'history' => array_map(fn (array $row): array => $this->acceptance($row), $acceptance['history']),
            ],
        ];
    }

    /**
     * @param  list<string>  $attention
     * @return array<string, mixed>
     */
    private function row(Risk $risk, ?RiskAssessment $latest, array $attention, CarbonInterface $today): array
    {
        $nextReview = $this->schedule->nextReviewOn($risk->review_interval_months, $latest?->assessed_at);

        return [
            'id' => (int) $risk->id,
            'title' => $risk->title,
            'status' => $risk->status,
            'treatment_strategy' => $risk->treatment_strategy,
            'business_area' => [
                'id' => (int) $risk->business_area_id,
                'name' => $risk->businessArea?->name,
            ],
            'owner' => $risk->owner_user_id !== null
                ? ['id' => (int) $risk->owner_user_id, 'name' => $risk->owner?->name]
                : null,
            'inherent' => $latest !== null
                ? $this->scoring->evaluate($latest->inherent_likelihood, $latest->inherent_consequence, $latest->criteria_key)
                : null,
            'residual' => $latest !== null && $latest->hasResidual()
                ? $this->scoring->evaluate($latest->residual_likelihood, $latest->residual_consequence, $latest->criteria_key)
                : null,
            'last_assessed_on' => $latest?->assessed_at?->toDateString(),
            'review_interval_months' => $risk->review_interval_months,
            'next_review_on' => $nextReview?->toDateString(),
            'is_review_overdue' => $this->schedule->isOverdue((string) $risk->status, $nextReview, $today),
            'attention' => $attention,
            'updated_at' => $risk->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /**
     * An acceptance of residual risk: when, for which assessment, until when, and its state. Not
     * who accepted or revoked it, and not the rationale.
     *
     * @param  array<string, mixed>  $row  a row of RiskAcceptanceService::decisionFor()
     * @return array<string, mixed>
     */
    private function acceptance(array $row): array
    {
        return [
            'id' => $row['id'],
            'assessment_id' => $row['assessment_id'],
            'state' => $row['state'],
            'accepted_at' => $this->utc($row['accepted_at']),
            'valid_until' => $row['valid_until'],
            'is_expired' => $row['is_expired'],
            'revoked_at' => $this->utc($row['revoked_at']),
        ];
    }

    /** The services' ISO 8601 times, in the API's form: UTC with a Z. */
    private function utc(?string $time): ?string
    {
        return $time !== null ? CarbonImmutable::parse($time)->utc()->toIso8601ZuluString() : null;
    }
}
