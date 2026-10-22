<?php

namespace App\Services\EnterpriseWiki;

use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimDecision;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one writer of a claim's approval status and blocking after the claim exists (A4,
 * docs/external-api-phase-0-plan.md §2a). Every change is written together with its event in
 * enterprise_wiki_claim_decisions, in one transaction under a lock on the claim row: either both
 * are there or neither is, and the history always ends where the current state is.
 *
 * Status is quality metadata and changes in place, on the published version too; the published
 * text never does (F1c/F1d). That a change happened, and who or what made it, is kept here.
 * ClaimStatusHistoryContractTest fails on any other code that writes these columns.
 */
class EnterpriseWikiClaimDecisionRecorder
{
    /** The columns each decision type owns; the first is the state the event is about. */
    private const AXES = [
        EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS => ['approval_status', 'approved_by_user_id', 'approved_at', 'approval_comment'],
        EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE => ['blocking_override', 'blocking_override_by_user_id', 'blocking_override_at'],
    ];

    /**
     * Change the claim's approval status or blocking and record it.
     *
     * The event's previous state is read from the locked row, not from the caller's copy, so it is
     * what the claim really held. A system event also keeps everything it cleared (who approved,
     * when, the comment), since no person's decision row will say it.
     *
     * @param  array<string, mixed>  $changes  columns of this decision type only
     * @param  array<string, mixed>  $previousExtra  more pre-change state worth keeping (an edited claim's wording)
     * @param  array<string, mixed>  $newExtra
     */
    public function change(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiClaimActor $actor,
        string $decisionType,
        array $changes,
        ?string $comment = null,
        array $previousExtra = [],
        array $newExtra = [],
    ): void {
        $axis = self::AXES[$decisionType] ?? throw new InvalidArgumentException("No claim state is recorded as {$decisionType}.");
        $stateColumn = $axis[0];

        if (! array_key_exists($stateColumn, $changes) || array_diff(array_keys($changes), $axis) !== []) {
            throw new InvalidArgumentException("A {$decisionType} change sets {$stateColumn} and nothing outside ".implode(', ', $axis).'.');
        }

        DB::transaction(function () use ($claim, $actor, $decisionType, $changes, $comment, $previousExtra, $newExtra, $axis, $stateColumn): void {
            $locked = EnterpriseWikiClaim::query()->lockForUpdate()->findOrFail($claim->id);

            $previous = $actor->isSystem()
                ? $this->stateOf($locked, $axis)
                : [$stateColumn => $locked->getAttribute($stateColumn)];

            $this->insert($locked, $actor, $decisionType, array_merge($previous, $previousExtra), array_merge([$stateColumn => $changes[$stateColumn]], $newExtra), $comment);

            $locked->update($changes);
            $claim->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * An event that changes no state column: a source a person linked, or the state a copied claim
     * took over. A single insert, so atomic on its own; callers that create something together
     * with it hold the transaction.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $new
     */
    public function note(
        EnterpriseWikiClaim $claim,
        EnterpriseWikiClaimActor $actor,
        string $decisionType,
        ?array $previous,
        array $new,
        ?string $comment = null,
    ): void {
        $this->insert($claim, $actor, $decisionType, $previous, $new, $comment);
    }

    /**
     * A claim copied into a new version arrives with its original's decision and blocking. Says so
     * on the copy, so its own history explains its state instead of starting already decided.
     */
    public function noteCarriedOverState(EnterpriseWikiClaim $copy, EnterpriseWikiClaim $original): void
    {
        $actor = EnterpriseWikiClaimActor::system(EnterpriseWikiClaimDecision::PROCESS_COPIED_TO_NEW_VERSION);

        if ($copy->approval_status !== EnterpriseWikiClaim::APPROVAL_STATUS_PENDING) {
            $this->insert($copy, $actor, EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS, null, array_merge(
                $this->stateOf($copy, self::AXES[EnterpriseWikiClaimDecision::TYPE_APPROVAL_STATUS]),
                ['copied_from_claim_id' => (int) $original->id],
            ), null);
        }

        if ($copy->blocking_override !== null) {
            $this->insert($copy, $actor, EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE, null, array_merge(
                $this->stateOf($copy, self::AXES[EnterpriseWikiClaimDecision::TYPE_BLOCKING_OVERRIDE]),
                ['copied_from_claim_id' => (int) $original->id],
            ), null);
        }
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function stateOf(EnterpriseWikiClaim $claim, array $columns): array
    {
        $state = [];

        foreach ($columns as $column) {
            $value = $claim->getAttribute($column);
            $state[$column] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $new
     */
    private function insert(EnterpriseWikiClaim $claim, EnterpriseWikiClaimActor $actor, string $decisionType, ?array $previous, array $new, ?string $comment): void
    {
        EnterpriseWikiClaimDecision::query()->create(array_merge($actor->columns(), [
            'enterprise_wiki_claim_id' => $claim->id,
            'decision_type' => $decisionType,
            'previous_state' => $previous,
            'new_state' => $new,
            'comment' => $comment,
        ]));
    }
}
