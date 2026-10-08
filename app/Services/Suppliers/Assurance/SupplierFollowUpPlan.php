<?php

namespace App\Services\Suppliers\Assurance;

use App\Models\SupplierControlRequirement;
use App\Models\SupplierDocument;
use Carbon\CarbonInterface;

/**
 * Oppfølgingsplan — what falls due for one supplier, and when (docs/supplier-assurance-v2-plan.md
 * §14). Not a task system: a list computed on read, shown as «Neste kontroller», with nothing
 * stored — no next_* column, no reminder row. Passing a date writes nothing; it only changes what is
 * computed next time.
 *
 * Every date of a control requirement is SupplierRequirementStatus::followUp()'s, the same one the
 * visningsstatus is decided on, so the plan and «Må fornyes» never disagree. Overdue from the day
 * after the date, as RiskReviewSchedule.
 *
 *  - control: a requirement with a control interval — the control in force + the interval;
 *  - document_renewal: a documentation row a requirement's control rests on — its «Gyldig til», or
 *    due now once it is replaced. Kept apart from control: renewing the document and controlling the
 *    requirement again are two different things;
 *  - acceptance: a Midlertidig akseptert requirement — accepted_until;
 *  - document: a documentation row (v1) that is not replaced and has «Gyldig til», unless a
 *    requirement's control already rests on it (then it is listed there, with the requirement);
 *  - assessment: Neste leverandørvurdering (v1, SupplierReviewSchedule), when given;
 *  - due_diligence: Neste aktsomhetsvurdering — the one in force's assessed_on + its
 *    review_interval_months (SupplierDueDiligenceService::nextOn()), when given.
 *
 * A requirement controlled «ved endring» has no date and is listed apart under on_change. A
 * requirement with no control yet has no date either — Ikke vurdert is a state, raised by Trenger
 * oppmerksomhet, never a deadline invented here. Only requirements that apply now come in: a retired
 * one, an excluded one or one whose rule no longer holds plans nothing. An ended supplier has no plan
 * (the caller passes none).
 *
 * Pure: no queries, no access.
 */
class SupplierFollowUpPlan
{
    public const KIND_CONTROL = 'control';

    public const KIND_DOCUMENT_RENEWAL = 'document_renewal';

    public const KIND_ACCEPTANCE = 'acceptance';

    public const KIND_DOCUMENT = 'document';

    public const KIND_ASSESSMENT = 'assessment';

    public const KIND_DUE_DILIGENCE = 'due_diligence';

    /** Same date: this order. */
    public const KINDS = [self::KIND_ACCEPTANCE, self::KIND_CONTROL, self::KIND_DOCUMENT_RENEWAL, self::KIND_DOCUMENT, self::KIND_ASSESSMENT, self::KIND_DUE_DILIGENCE];

    /** How many entries «Neste kontroller» shows before «Vis alle». */
    public const PREVIEW = 5;

    /**
     * @param  iterable<array<string, mixed>>  $requirements  the requirements that apply now, each with id, title, level, control_point and follow_up
     * @param  iterable<SupplierDocument>  $documents  the supplier's documentation rows as they are now
     * @return array{entries: list<array{kind: string, date: string|null, overdue: bool, requirement: array{id: int, title: string, level: string}|null, document: array{id: int, title: string, document_type: string}|null}>, on_change: list<array{id: int, title: string}>}
     */
    public static function build(iterable $requirements, iterable $documents, ?CarbonInterface $nextReviewOn, CarbonInterface $today, ?CarbonInterface $nextDueDiligenceOn = null): array
    {
        $todayString = $today->toDateString();
        $entries = [];
        $onChange = [];
        $basis = [];

        $entry = fn (string $kind, ?string $date, bool $overdue, ?array $requirement = null, ?array $document = null): array => [
            'kind' => $kind,
            'date' => $date,
            'overdue' => $overdue,
            'requirement' => $requirement,
            'document' => $document,
        ];

        foreach ($requirements as $row) {
            $followUp = (array) ($row['follow_up'] ?? []);
            $requirement = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'level' => (string) $row['level']];

            foreach ((array) ($followUp['document_ids'] ?? []) as $id) {
                $basis[(int) $id] = true;
            }

            if (($row['control_point'] ?? null) === 'on_change') {
                $onChange[] = ['id' => $requirement['id'], 'title' => $requirement['title']];
            }

            if (($followUp['accepted_until'] ?? null) !== null) {
                $entries[] = $entry(self::KIND_ACCEPTANCE, $followUp['accepted_until'], (bool) $followUp['acceptance_expired'], $requirement);
            }

            if (($followUp['next_control_on'] ?? null) !== null) {
                $entries[] = $entry(self::KIND_CONTROL, $followUp['next_control_on'], (bool) $followUp['control_overdue'], $requirement);
            }

            if (($followUp['document'] ?? null) !== null) {
                $document = $followUp['document'];
                $entries[] = $entry(
                    self::KIND_DOCUMENT_RENEWAL,
                    $document['replaced'] ? null : $document['valid_until'],
                    (bool) $followUp['document_renewal_due'],
                    $requirement,
                    ['id' => (int) $document['id'], 'title' => (string) $document['title'], 'document_type' => (string) $document['document_type']],
                );
            }
        }

        foreach ($documents as $document) {
            if ($document->isReplaced() || $document->valid_until === null || isset($basis[(int) $document->id])) {
                continue;
            }

            $entries[] = $entry(
                self::KIND_DOCUMENT,
                $document->valid_until->toDateString(),
                $document->validityStatus($today) === SupplierDocument::STATUS_EXPIRED,
                null,
                ['id' => (int) $document->id, 'title' => (string) $document->title, 'document_type' => (string) $document->document_type],
            );
        }

        if ($nextReviewOn !== null) {
            $entries[] = $entry(self::KIND_ASSESSMENT, $nextReviewOn->toDateString(), $nextReviewOn->toDateString() < $todayString);
        }

        if ($nextDueDiligenceOn !== null) {
            $entries[] = $entry(self::KIND_DUE_DILIGENCE, $nextDueDiligenceOn->toDateString(), $nextDueDiligenceOn->toDateString() < $todayString);
        }

        // Overdue first, a date-less one (a replaced document) before any dated one; then by date,
        // kind and title.
        usort($entries, fn (array $a, array $b): int => self::sortKey($a) <=> self::sortKey($b));
        usort($onChange, fn (array $a, array $b): int => [mb_strtolower($a['title']), $a['id']] <=> [mb_strtolower($b['title']), $b['id']]);

        return ['entries' => $entries, 'on_change' => $onChange];
    }

    /** @param  array<string, mixed>  $entry */
    private static function sortKey(array $entry): array
    {
        return [
            $entry['overdue'] ? 0 : 1,
            $entry['date'] ?? '',
            array_search($entry['kind'], self::KINDS, true),
            mb_strtolower($entry['requirement']['title'] ?? $entry['document']['title'] ?? ''),
            array_search($entry['requirement']['level'] ?? SupplierControlRequirement::LEVEL_STANDARD, SupplierControlRequirement::LEVELS, true),
        ];
    }
}
