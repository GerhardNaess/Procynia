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
 * decided here (phase 4).
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

        if ($current->status === SupplierRequirementEvaluation::STATUS_TEMPORARILY_ACCEPTED) {
            return $current->accepted_until !== null && $current->accepted_until->toDateString() < $today->toDateString()
                ? self::ACCEPTANCE_EXPIRED
                : $current->status;
        }

        if (in_array($current->status, [SupplierRequirementEvaluation::STATUS_DOCUMENTED, SupplierRequirementEvaluation::STATUS_PARTIALLY_DOCUMENTED], true)) {
            $schedule = new SupplierReviewSchedule;

            if ($schedule->isOverdue($schedule->nextReviewOn($intervalMonths, $current->evaluated_on), $today)) {
                return self::RENEWAL_DUE;
            }

            foreach ($documentsNow as $document) {
                if (in_array($document->validityStatus($today), [SupplierDocument::STATUS_EXPIRED, SupplierDocument::STATUS_REPLACED], true)) {
                    return self::RENEWAL_DUE;
                }
            }
        }

        return $current->status;
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
