<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceRequirement;

/**
 * Etterlevelsesvurdering's figures: where every active requirement stands, counted once.
 *
 * Each requirement lands in exactly one group, so the figures add up across standards — a
 * requirement belongs to one source, and a source is one standard (or one kravkilde registered by
 * hand). Nothing is stored; the group is read off the requirement's relevance and its latest
 * assessment, through ComplianceStatusResolver, like everywhere else.
 *
 *  - Ikke relevant (not_applicable): a library requirement determined Ikke relevant, an Annex A
 *    control Utelatt, or a requirement registered by hand whose latest assessment is Ikke relevant.
 *    Outside the work — never a gap.
 *  - Relevans ikke avklart (relevance_open): a library requirement still waiting for its relevance
 *    judgement or Kontrollvalg. It cannot be assessed yet (ComplianceAssessmentService refuses it),
 *    so it is neither a gap nor work left in the assessment.
 *  - Otherwise the requirement is vurderbar: Oppfylt, Delvis oppfylt, Ikke oppfylt or Ikke vurdert.
 *
 * Vurderbare = Vurdert + Ikke vurdert; Vurdert = Oppfylt + Delvis oppfylt + Ikke oppfylt.
 * Revurdering forfalt is counted beside them, never instead of a result.
 */
class ComplianceAssessmentOverview
{
    public const NOT_APPLICABLE = 'not_applicable';

    public const RELEVANCE_OPEN = 'relevance_open';

    /** The groups a requirement that can be assessed falls into. */
    public const ASSESSABLE = [
        ComplianceAssessment::RESULT_COMPLIANT,
        ComplianceAssessment::RESULT_PARTIALLY_COMPLIANT,
        ComplianceAssessment::RESULT_NON_COMPLIANT,
        ComplianceStatusResolver::STATUS_NOT_ASSESSED,
    ];

    /** Delvis oppfylt and Ikke oppfylt: what «Mangler» means. */
    public const GAPS = [
        ComplianceAssessment::RESULT_PARTIALLY_COMPLIANT,
        ComplianceAssessment::RESULT_NON_COMPLIANT,
    ];

    /** The figures, in display order. */
    public const STAT_KEYS = [
        'assessable',
        'assessed',
        'compliant',
        'partially_compliant',
        'non_compliant',
        'not_assessed',
        'overdue',
        'not_applicable',
        'relevance_open',
    ];

    /** What the worklist can be narrowed to. '' is every requirement that can be assessed. */
    public const FILTERS = [
        'not_assessed',
        'gaps',
        'compliant',
        'partially_compliant',
        'non_compliant',
        'overdue',
        'relevance_open',
        'not_applicable',
    ];

    public function __construct(
        private readonly ComplianceStatusResolver $status,
    ) {}

    /** The one group an active requirement falls into. */
    public function group(ComplianceRequirement $requirement, ?ComplianceAssessment $latest): string
    {
        if ($requirement->isNotApplicable()) {
            return self::NOT_APPLICABLE;
        }

        if ($requirement->isFromLibrary() && $requirement->applicability === ComplianceRequirement::APPLICABILITY_NOT_ASSESSED) {
            return self::RELEVANCE_OPEN;
        }

        $status = $this->status->statusOf($latest);

        return $status === ComplianceAssessment::RESULT_NOT_APPLICABLE ? self::NOT_APPLICABLE : $status;
    }

    /** @return array<string, int> */
    public function emptyStats(): array
    {
        return array_fill_keys(self::STAT_KEYS, 0);
    }

    /** @param  array<string, int>  $stats */
    public function count(array &$stats, string $group, bool $overdue): void
    {
        if (! in_array($group, self::ASSESSABLE, true)) {
            $stats[$group]++;

            return;
        }

        $stats['assessable']++;
        $stats[$group]++;

        if ($group !== ComplianceStatusResolver::STATUS_NOT_ASSESSED) {
            $stats['assessed']++;
        }

        if ($overdue) {
            $stats['overdue']++;
        }
    }

    /** Whether a requirement in $group, overdue or not, passes the worklist filter. */
    public function matches(string $filter, string $group, bool $overdue): bool
    {
        return match ($filter) {
            '' => in_array($group, self::ASSESSABLE, true),
            'gaps' => in_array($group, self::GAPS, true),
            'overdue' => $overdue && in_array($group, self::ASSESSABLE, true),
            default => $group === $filter,
        };
    }
}
