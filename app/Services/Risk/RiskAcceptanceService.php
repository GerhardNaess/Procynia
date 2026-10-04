<?php

namespace App\Services\Risk;

use App\Models\Risk;
use App\Models\RiskAcceptance;
use App\Models\RiskAssessment;
use App\Models\User;
use Carbon\CarbonInterface;
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
        return DB::transaction(function () use ($actor, $risk, $assessmentId, $rationale, $validUntil): RiskAcceptance {
            // Serialises accepting against a new assessment or a second acceptance of the same risk.
            Risk::query()->whereKey($risk->id)->lockForUpdate()->first();

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
        if ($acceptance->isRevoked()) {
            $this->fail('already_revoked', 'acceptance');
        }

        $acceptance->fill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
        ])->save();
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
