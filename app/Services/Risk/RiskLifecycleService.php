<?php

namespace App\Services\Risk;

use App\Exceptions\RiskHistoryProtected;
use App\Models\Risk;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\HumanDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a risk — for a risk registered by mistake, and nothing else.
 *
 * Deleting is a human decision (phase 0 step 9, docs/external-api-phase-0-plan.md §4.4): refused for
 * an integration and any request through the API (HumanDecisionGuard), and for a person without
 * risk.delete in the risk's fagområde (RiskAccessService::canDelete), here, for every entry point.
 *
 * A risk with history — an assessment, an acceptance or a tiltak — is never deleted (decision B3,
 * §4.6); it is closed (Risk::STATUS_CLOSED) instead. The rule is checked under the row lock, so an
 * assessment registered a moment ago is seen. The model and a PostgreSQL trigger refuse the same
 * deletion on their own.
 */
class RiskLifecycleService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly RiskAccessService $access,
    ) {}

    public function delete(User $actor, Risk $risk): void
    {
        $actor = $this->guard->assert($actor, HumanDecision::RISK_DELETE);

        DB::transaction(function () use ($actor, $risk): void {
            $current = Risk::query()->whereKey($risk->id)->lockForUpdate()->first();

            if ($current === null || ! $this->access->canDelete($actor, $current)) {
                throw new AuthorizationException('This user may not delete this risk.');
            }

            if (! $current->isDeletable()) {
                throw new RiskHistoryProtected;
            }

            $current->delete();
        });
    }
}
