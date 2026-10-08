<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\SupplierDocument;
use App\Models\SupplierRequirementEvaluation;
use App\Services\Suppliers\SupplierReviewSchedule;
use Carbon\CarbonInterface;

/**
 * Visningsstatus — the one status a requirement that applies shows on a supplier
 * (docs/supplier-assurance-v2-plan.md §8.2): the stored status of the control in force, or one
 * computed on read:
 *
 *  - Ikke vurdert: no control;
 *  - Aksept utløpt: Midlertidig akseptert and accepted_until before today;
 *  - Må fornyes: Dokumentert or Delvis dokumentert, and either the requirement's control interval
 *    has run out since the control date (overdue from the day after, as SupplierReviewSchedule), or
 *    a document given as the basis is, as the row stands now, expired or replaced.
 *
 * Må fornyes and Aksept utløpt go before the stored status. The documents are the current rows,
 * never the control's snapshot: the snapshot says what was seen, the row says what holds today.
 *
 * Pure: no queries, no access. Nothing is stored. Whether the overall picture is in order is not
 * decided here (SupplierAssuranceResolver). The dates behind Må fornyes and Aksept utløpt are
 * followUp()'s, so the status and the oppfølgingsplan never disagree.
 */
class SupplierRequirementStatus
{
    public const NOT_EVALUATED = 'not_evaluated';

    public const RENEWAL_DUE = 'renewal_due';

    public const ACCEPTANCE_EXPIRED = 'acceptance_expired';

    /**
     * @param  iterable<SupplierDocument>  $documentsNow  the documentation rows the control names, as they are now
     */
    public static function display(?SupplierRequirementEvaluation $current, ?int $intervalMonths, iterable $documentsNow, CarbonInterface $today): string
    {
        if ($current === null) {
            return self::NOT_EVALUATED;
        }

        $followUp = self::followUp($current, $intervalMonths, $documentsNow, $today);

        if ($followUp['acceptance_expired']) {
            return self::ACCEPTANCE_EXPIRED;
        }

        if ($followUp['control_overdue'] || $followUp['document_renewal_due']) {
            return self::RENEWAL_DUE;
        }

        return $current->status;
    }

    /**
     * When the control in force next has to be looked at again, and why (plan §14) — the dates
     * display() decides on, kept apart because they mean different things:
     *
     *  - next_control_on: Dokumentert or Delvis dokumentert, and the requirement has a control
     *    interval — the control date + the interval in calendar months (SupplierReviewSchedule, no
     *    overflow). control_overdue from the day after. No interval, no date: time alone never makes
     *    such a requirement overdue. Mangler has no date — it is open already, not scheduled.
     *  - document: for the same two statuses, the documentation row given as basis that needs
     *    renewing first, as the row stands now — a replaced row (due now, no date), else the earliest
     *    «Gyldig til». document_renewal_due once it is replaced or expired (the last day still valid).
     *  - accepted_until: Midlertidig akseptert; acceptance_expired from the day after.
     *
     * No control: nothing is scheduled (Ikke vurdert is a state, not a date). Pure.
     *
     * @param  iterable<SupplierDocument>  $documentsNow
     * @return array{next_control_on: string|null, control_overdue: bool, document: array{id: int, title: string, document_type: string, valid_until: string|null, replaced: bool}|null, document_renewal_due: bool, accepted_until: string|null, acceptance_expired: bool, document_ids: list<int>}
     */
    public static function followUp(?SupplierRequirementEvaluation $current, ?int $intervalMonths, iterable $documentsNow, CarbonInterface $today): array
    {
        $result = [
            'next_control_on' => null,
            'control_overdue' => false,
            'document' => null,
            'document_renewal_due' => false,
            'accepted_until' => null,
            'acceptance_expired' => false,
            'document_ids' => [],
        ];

        if ($current === null) {
            return $result;
        }

        $todayString = $today->toDateString();

        if ($current->status === SupplierRequirementEvaluation::STATUS_TEMPORARILY_ACCEPTED) {
            $result['accepted_until'] = $current->accepted_until?->toDateString();
            $result['acceptance_expired'] = $result['accepted_until'] !== null && $result['accepted_until'] < $todayString;

            return $result;
        }

        if (! in_array($current->status, [SupplierRequirementEvaluation::STATUS_DOCUMENTED, SupplierRequirementEvaluation::STATUS_PARTIALLY_DOCUMENTED], true)) {
            return $result;
        }

        $schedule = new SupplierReviewSchedule;
        $next = $schedule->nextReviewOn($intervalMonths, $current->evaluated_on);
        $result['next_control_on'] = $next?->toDateString();
        $result['control_overdue'] = $schedule->isOverdue($next, $today);

        // A replaced row first (due now, whatever its date), then the earliest «Gyldig til»; a row
        // without a date never needs renewing.
        $candidates = [];

        foreach ($documentsNow as $document) {
            $result['document_ids'][] = (int) $document->id;

            if ($document->isReplaced() || $document->valid_until !== null) {
                $candidates[] = $document;
            }
        }

        usort($candidates, fn (SupplierDocument $a, SupplierDocument $b): int => [$a->isReplaced() ? 0 : 1, $a->valid_until?->toDateString() ?? '', (int) $a->id]
            <=> [$b->isReplaced() ? 0 : 1, $b->valid_until?->toDateString() ?? '', (int) $b->id]);
        $first = $candidates[0] ?? null;

        if ($first !== null) {
            $result['document'] = [
                'id' => (int) $first->id,
                'title' => (string) $first->title,
                'document_type' => (string) $first->document_type,
                'valid_until' => $first->valid_until?->toDateString(),
                'replaced' => $first->isReplaced(),
            ];
            $result['document_renewal_due'] = in_array($first->validityStatus($today), [SupplierDocument::STATUS_EXPIRED, SupplierDocument::STATUS_REPLACED], true);
        }

        return $result;
    }

    /**
     * The control in force among one requirement's controls: the latest control date, then the
     * highest id.
     *
     * @param  iterable<SupplierRequirementEvaluation>  $evaluations
     */
    public static function current(iterable $evaluations): ?SupplierRequirementEvaluation
    {
        return SupplierRequirementEvaluation::newestFirst($evaluations)[0] ?? null;
    }
}
