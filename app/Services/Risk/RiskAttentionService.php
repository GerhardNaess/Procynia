<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * «Trenger oppmerksomhet» — which of the user's risks need following up now.
 *
 * Each finding is one fixed rule, read straight off the rows that already exist and explainable in
 * one sentence. Nothing is stored: the picture is recomputed on every read, so fixing the risk is the
 * only way to clear a finding.
 *
 * ACCESS COMES FIRST. The risks are taken from RiskAccessService::visibleRisks() before anything is
 * counted, and every other row read here is fetched by the ids of those risks only. A risk outside
 * the user's fagområder never enters the set, so it cannot move a total, a category count or a link.
 *
 * Closed risks are left out: they are no longer followed up.
 *
 * A risk can have several findings at once. Category counts may therefore overlap; the total counts
 * each risk once.
 *
 * Deliberately not here: "risk without a control". Nothing says every risk must have one, and
 * Procynia does not guess.
 */
class RiskAttentionService
{
    public const NOT_ASSESSED = 'not_assessed';

    public const RESIDUAL_NOT_ASSESSED = 'residual_not_assessed';

    public const HIGH_RESIDUAL = 'high_residual';

    public const REVIEW_OVERDUE = 'review_overdue';

    public const ACCEPTANCE_EXPIRED = 'acceptance_expired';

    public const ACTIONS_OVERDUE = 'actions_overdue';

    /** Display order. */
    public const CATEGORIES = [
        self::HIGH_RESIDUAL,
        self::NOT_ASSESSED,
        self::RESIDUAL_NOT_ASSESSED,
        self::REVIEW_OVERDUE,
        self::ACCEPTANCE_EXPIRED,
        self::ACTIONS_OVERDUE,
    ];

    public function __construct(
        private readonly RiskAccessService $access,
        private readonly RiskScoringPolicy $scoring,
        private readonly RiskReviewSchedule $reviewSchedule,
    ) {}

    /**
     * Only categories with at least one risk are returned, in a fixed order.
     *
     * @return array{
     *     total: int,
     *     categories: list<array{key: string, count: int, risks: list<array<string, mixed>>}>
     * }
     */
    public function overview(User $user, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        $risks = $this->access->visibleRisks($user)
            ->where('risks.status', '!=', Risk::STATUS_CLOSED)
            ->with('businessArea:id,name')
            ->orderBy('risks.title')
            ->orderBy('risks.id')
            ->get(['risks.*']);

        if ($risks->isEmpty()) {
            return ['total' => 0, 'categories' => []];
        }

        $customerId = (int) $user->customer_id;
        $riskIds = $risks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $latest = RiskAssessment::latestForRisks($customerId, $riskIds);
        $expired = $this->expiredAcceptances($customerId, $latest, $today);
        $overdueActions = $this->overdueActions($customerId, $riskIds, $today);

        $byCategory = array_fill_keys(self::CATEGORIES, []);
        $flagged = [];

        foreach ($risks as $risk) {
            $id = (int) $risk->id;

            foreach ($this->findingsFor($risk, $latest->get($id), $expired->get($id), $overdueActions->get($id), $today) as $key => $detail) {
                $byCategory[$key][] = $this->row($risk) + ['detail' => $detail];
                $flagged[$id] = true;
            }
        }

        return [
            'total' => count($flagged),
            'categories' => collect($byCategory)
                ->filter(fn (array $rows): bool => $rows !== [])
                ->map(fn (array $rows, string $key): array => ['key' => $key, 'count' => count($rows), 'risks' => $rows])
                ->values()
                ->all(),
        ];
    }

    /**
     * The findings for one risk, keyed by category, each with what the page needs to say why.
     *
     * @return array<string, array<string, mixed>>
     */
    private function findingsFor(Risk $risk, ?RiskAssessment $latest, ?RiskAcceptance $expired, ?object $actions, CarbonImmutable $today): array
    {
        $findings = [];

        if ($latest === null) {
            $findings[self::NOT_ASSESSED] = [];
        } elseif (! $latest->hasResidual()) {
            $findings[self::RESIDUAL_NOT_ASSESSED] = ['assessed_on' => $latest->assessed_at?->toDateString()];
        } else {
            $residual = $this->scoring->evaluate($latest->residual_likelihood, $latest->residual_consequence, $latest->criteria_key);

            if (in_array($residual['level'], [RiskScoringPolicy::LEVEL_HIGH, RiskScoringPolicy::LEVEL_VERY_HIGH], true)) {
                $findings[self::HIGH_RESIDUAL] = ['level' => $residual['level'], 'score' => $residual['score']];
            }
        }

        $next = $this->reviewSchedule->nextReviewOn($risk->review_interval_months, $latest?->assessed_at);

        if ($this->reviewSchedule->isOverdue($risk->status, $next, $today)) {
            $findings[self::REVIEW_OVERDUE] = ['next_review_on' => $next?->toDateString()];
        }

        if ($expired !== null) {
            $findings[self::ACCEPTANCE_EXPIRED] = ['valid_until' => $expired->valid_until?->toDateString()];
        }

        if ($actions !== null) {
            $findings[self::ACTIONS_OVERDUE] = [
                'count' => (int) $actions->overdue_count,
                'earliest_due_on' => CarbonImmutable::parse($actions->earliest_due_at)->toDateString(),
            ];
        }

        return $findings;
    }

    /**
     * The current acceptance — non-revoked, of the latest assessment — where it is past valid_until.
     * Acceptances of older assessments are history and never count.
     *
     * @param  Collection<int, RiskAssessment>  $latest
     * @return Collection<int, RiskAcceptance>
     */
    private function expiredAcceptances(int $customerId, Collection $latest, CarbonImmutable $today): Collection
    {
        if ($latest->isEmpty()) {
            return collect();
        }

        return RiskAcceptance::query()
            ->where('customer_id', $customerId)
            ->whereIn('assessment_id', $latest->pluck('id')->all())
            ->whereNull('revoked_at')
            ->whereNotNull('valid_until')
            ->get()
            ->filter(fn (RiskAcceptance $acceptance): bool => $acceptance->isExpired($today))
            ->keyBy(fn (RiskAcceptance $acceptance): int => (int) $acceptance->risk_id);
    }

    /**
     * Per risk, how many open tiltak are past their deadline day, and the earliest such deadline.
     *
     * @param  list<int>  $riskIds
     * @return Collection<int, object>
     */
    private function overdueActions(int $customerId, array $riskIds, CarbonImmutable $today): Collection
    {
        return RiskTreatmentAction::query()
            ->where('customer_id', $customerId)
            ->whereIn('risk_id', $riskIds)
            ->where('status', RiskTreatmentAction::STATUS_OPEN)
            ->whereDate('due_at', '<', $today->toDateString())
            ->groupBy('risk_id')
            ->selectRaw('risk_id, count(*) as overdue_count, min(due_at) as earliest_due_at')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->risk_id);
    }

    /** @return array{id: int, title: string, area_name: ?string, url: string} */
    private function row(Risk $risk): array
    {
        return [
            'id' => (int) $risk->id,
            'title' => $risk->title,
            'area_name' => $risk->businessArea?->name,
            'url' => route('app.risk.show', ['riskId' => $risk->id]),
        ];
    }
}
