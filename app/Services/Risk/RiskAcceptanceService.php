<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\HumanDecision;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit acceptance of residual risk: accept, revoke — and the decision the risk page shows.
 *
 * The caller has already reached the risk through RiskAccessService::visibleRisks() and checked
 * risk.accept in its area. Nothing here looks a risk up on its own, and an assessment or an
 * acceptance is only ever found under that risk, so neither can be the way to a hidden risk.
 *
 * Accepting and revoking are human decisions (phase 0 step 9, docs/external-api-phase-0-plan.md
 * §4.4), so both check again here, whatever the caller did: HumanDecisionGuard refuses an
 * integration and any request through the API, and RiskAccessService::canAccept() a person without
 * risk.accept in the risk's fagområde — judged on the risk as it stands under the row lock.
 *
 * WHICH ACCEPTANCE APPLIES.
 *
 * Computed on read, never stored: the non-revoked acceptance of the risk's latest assessment.
 * Every other acceptance — revoked, or given for an assessment that has since been followed by a
 * newer one — is history. Expiry does not change which acceptance applies; an expired acceptance is
 * still the current one, shown as expired, until it is revoked or a new assessment arrives.
 *
 * Accepting never changes the risk's status, its assessment or its score.
 */
class RiskAcceptanceService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly RiskAccessService $access,
    ) {}

    /**
     * @return array{
     *     latest_assessment_id: int|null,
     *     has_residual: bool,
     *     current: array<string, mixed>|null,
     *     history: list<array<string, mixed>>
     * }
     */
    public function decisionFor(Risk $risk): array
    {
        $latest = $this->latestAssessment($risk);
        $today = now();

        $acceptances = RiskAcceptance::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $risk->customer_id)
            ->with(['acceptedBy:id,name', 'revokedBy:id,name', 'assessment:id,assessed_at'])
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->get();

        $current = $latest === null ? null : $acceptances->first(
            fn (RiskAcceptance $acceptance): bool => (int) $acceptance->assessment_id === (int) $latest->id
                && ! $acceptance->isRevoked()
        );

        return [
            'latest_assessment_id' => $latest !== null ? (int) $latest->id : null,
            'has_residual' => $latest?->hasResidual() ?? false,
            'current' => $current !== null ? $this->row($current, $today, 'current') : null,
            'history' => $acceptances
                ->reject(fn (RiskAcceptance $acceptance): bool => $current !== null && $acceptance->is($current))
                ->map(fn (RiskAcceptance $acceptance): array => $this->row(
                    $acceptance,
                    $today,
                    $acceptance->isRevoked() ? 'revoked' : 'superseded',
                ))
                ->values()
                ->all(),
        ];
    }

    public function accept(User $actor, Risk $risk, int $assessmentId, string $rationale, ?string $validUntil): RiskAcceptance
    {
        $actor = $this->guard->assert($actor, HumanDecision::RISK_ACCEPT);

        return DB::transaction(function () use ($actor, $risk, $assessmentId, $rationale, $validUntil): RiskAcceptance {
            // Serialises accepting against a new assessment or a second acceptance of the same risk.
            $risk = $this->lockedRiskFor($actor, (int) $risk->id);

            $assessment = RiskAssessment::query()
                ->where('risk_id', $risk->id)
                ->where('customer_id', $risk->customer_id)
                ->find($assessmentId);

            // Another risk's assessment, another tenant's and a made-up id get the same answer.
            if ($assessment === null) {
                $this->fail('assessment_not_found');
            }

            if ((int) $assessment->id !== (int) $this->latestAssessment($risk)?->id) {
                $this->fail('assessment_not_latest');
            }

            if (! $assessment->hasResidual()) {
                $this->fail('residual_missing');
            }

            if ($this->activeFor($assessment)) {
                $this->fail('already_accepted');
            }

            try {
                return RiskAcceptance::query()->create([
                    'customer_id' => (int) $risk->customer_id,
                    'risk_id' => (int) $risk->id,
                    'assessment_id' => (int) $assessment->id,
                    'accepted_by_user_id' => $actor->id,
                    'rationale' => trim($rationale),
                    'accepted_at' => now(),
                    'valid_until' => $validUntil,
                ]);
            } catch (QueryException) {
                // The partial unique index: someone else accepted this assessment a moment ago.
                $this->fail('already_accepted');
            }
        });
    }

    public function revoke(User $actor, RiskAcceptance $acceptance): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::RISK_ACCEPT);

        DB::transaction(function () use ($actor, $acceptance): void {
            $risk = $this->lockedRiskFor($actor, (int) $acceptance->risk_id);

            // Read under the lock: two revocations at once leave one, and the second is told so.
            $locked = $this->findForRisk($risk, (int) $acceptance->id);

            if ($locked === null) {
                throw new AuthorizationException('This acceptance does not belong to a risk this user may decide on.');
            }

            if ($locked->isRevoked()) {
                $this->fail('already_revoked', 'acceptance');
            }

            $locked->fill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
            ])->save();

            $acceptance->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** The acceptance, if it belongs to this risk. Anything else is the caller's 404. */
    public function findForRisk(Risk $risk, int $acceptanceId): ?RiskAcceptance
    {
        return RiskAcceptance::query()
            ->where('risk_id', $risk->id)
            ->where('customer_id', $risk->customer_id)
            ->whereKey($acceptanceId)
            ->first();
    }

    /**
     * The risk, locked, if this person may accept on it as it stands now. Anything else — another
     * customer's risk, one outside their fagområder, one they may only read — is refused.
     */
    private function lockedRiskFor(User $actor, int $riskId): Risk
    {
        $risk = Risk::query()->whereKey($riskId)->lockForUpdate()->first();

        if ($risk === null || ! $this->access->canAccept($actor, $risk)) {
            throw new AuthorizationException('This user may not accept residual risk on this risk.');
        }

        return $risk;
    }

    private function latestAssessment(Risk $risk): ?RiskAssessment
    {
        return $risk->assessments()->where('customer_id', $risk->customer_id)->first();
    }

    private function activeFor(RiskAssessment $assessment): bool
    {
        return RiskAcceptance::query()
            ->where('assessment_id', $assessment->id)
            ->whereNull('revoked_at')
            ->exists();
    }

    /** @return array<string, mixed> */
    private function row(RiskAcceptance $acceptance, CarbonInterface $today, string $state): array
    {
        return [
            'id' => (int) $acceptance->id,
            'assessment_id' => (int) $acceptance->assessment_id,
            'assessment_assessed_at' => $acceptance->assessment?->assessed_at?->toIso8601String(),
            'state' => $state,
            'accepted_by_name' => $acceptance->acceptedBy?->name,
            'accepted_at' => $acceptance->accepted_at?->toIso8601String(),
            'rationale' => $acceptance->rationale,
            'valid_until' => $acceptance->valid_until?->toDateString(),
            'is_expired' => $acceptance->isExpired($today),
            'revoked_at' => $acceptance->revoked_at?->toIso8601String(),
            'revoked_by_name' => $acceptance->revokedBy?->name,
        ];
    }

    private function fail(string $key, string $field = 'assessment_id'): never
    {
        throw ValidationException::withMessages([
            $field => __('procynia.risk.validation.acceptance_'.$key),
        ]);
    }
}
