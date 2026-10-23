<?php

namespace App\Services\Api\Presenters;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Services\Risk\RiskReviewSchedule;
use App\Services\Risk\RiskScoringPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A risk as the external API presents it: structured fields only (D8).
 *
 * In: identifiers, title, status, behandlingsvalg, fagområde, eier, the latest assessment's
 * inherent and residual scoring, and the periodic review. Out: årsak, hendelse, konsekvens,
 * utfyllende informasjon, begrunnelser and every other free text, and web routes.
 *
 * Nothing is computed here that the web does not compute the same way: the latest assessment is
 * RiskAssessment::latestForRisks(), the levels are RiskScoringPolicy, the review dates are
 * RiskReviewSchedule — the same three the register uses.
 */
class RiskPresenter
{
    public function __construct(
        private readonly RiskScoringPolicy $scoring,
        private readonly RiskReviewSchedule $schedule,
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

        return $risks
            ->map(fn (Risk $risk): array => $this->row($risk, $latest->get((int) $risk->id), $today))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(Risk $risk, ?RiskAssessment $latest, CarbonInterface $today): array
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
            'updated_at' => $risk->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
