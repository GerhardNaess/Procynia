<?php

namespace App\Services\ManagementReview;

use App\Models\ManagementReview;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewSectionCatalog as Catalog;
use Carbon\CarbonImmutable;

/**
 * The review as one readable document — the print view and the PDF render this, and nothing else
 * (plan §11). It is built from ManagementReviewPresenter::show(), so it carries exactly what the
 * reader may see on screen: a section the reader may not read is left out of the document entirely,
 * with one sentence on the cover that some parts are only shown to people with access to them.
 *
 * Words are chosen here, in the reader's language, so the two renderings cannot drift apart.
 */
final class ManagementReviewReportBuilder
{
    private const T = 'procynia.management_review.';

    public function __construct(
        private readonly ManagementReviewPresenter $presenter,
        private readonly BasisFormatter $formatter,
    ) {}

    /** @return array<string, mixed> */
    public function document(User $reader, ManagementReview $review): array
    {
        $data = $this->presenter->show($reader, $review);
        $row = $data['review'];
        $draft = $row['status'] === ManagementReview::STATUS_DRAFT;
        $sectionTitles = [];
        $sections = [];
        $omitted = false;

        foreach ($data['sections'] as $section) {
            $sectionTitles[$section['key']] = __(self::T.'sections.'.$section['key'].'.title');

            if ($section['state'] === Catalog::STATE_NO_ACCESS) {
                $omitted = true;

                continue;
            }

            $sections[] = [
                'key' => $section['key'],
                'title' => $sectionTitles[$section['key']],
                'state_text' => match ($section['state']) {
                    Catalog::STATE_MODULE_UNAVAILABLE => __(self::T.'states.module_unavailable'),
                    Catalog::STATE_NOT_CAPTURED => __(self::T.'states.not_captured'),
                    default => $section['has_basis'] && ($section['basis']['empty'] ?? false) ? __(self::T.'states.empty') : null,
                },
                'coverage_text' => match ($section['coverage']) {
                    'partial' => __(self::T.'coverage.partial'),
                    'captured_partial' => __(self::T.'coverage.captured_partial'),
                    default => null,
                },
                'basis' => $section['basis'],
                'notes' => $section['notes'],
                'judgement' => $section['judgement'] !== null ? __(self::T.'judgements.'.$section['judgement']) : null,
                'judgement_key' => $section['judgement'],
                'comment' => $section['comment'],
            ];
        }

        $decisions = array_map(fn (array $decision): array => [
            'id' => $decision['id'],
            'kind' => __(self::T.'values.kind_'.$decision['kind']),
            'text' => $decision['text'],
            'section' => $decision['section_key'] !== null ? ($sectionTitles[$decision['section_key']] ?? null) : null,
            'owner' => $decision['owner_name'],
            'due_date' => $this->formatter->date($decision['due_date']),
            'follow_up' => $this->followUp($decision),
            'at_finalization' => $decision['at_finalization'],
        ], $data['decisions']);

        return [
            'title' => $row['title'],
            'is_draft' => $draft,
            'status' => __(self::T.'statuses.'.$row['status']),
            'generated_at' => CarbonImmutable::now()->format('d.m.Y H:i'),
            'basis_note' => $draft
                ? __(self::T.'report.basis_live', ['date' => CarbonImmutable::now()->format('d.m.Y H:i')])
                : __(self::T.'report.basis_snapshot', ['date' => CarbonImmutable::parse($row['finalized_at'])->format('d.m.Y H:i'), 'name' => (string) $row['finalized_by_name']]),
            'restricted_note' => $omitted ? __(self::T.'report.restricted') : null,
            'meta' => array_values(array_filter([
                [__(self::T.'fields.period'), $this->formatter->date($row['period_start']).' – '.$this->formatter->date($row['period_end'])],
                [__(self::T.'fields.meeting_date'), $this->formatter->date($row['meeting_date']) ?? '—'],
                [__(self::T.'fields.scope'), $row['all_business_areas'] ? __(self::T.'scope_all') : implode(', ', array_column($row['business_areas'], 'name'))],
                [__(self::T.'fields.owner'), $row['owner_name'] ?? '—'],
                $row['frameworks'] !== [] ? [__(self::T.'fields.frameworks'), implode(', ', $row['framework_labels'])] : null,
                ! $draft ? [__(self::T.'fields.finalized'), CarbonImmutable::parse($row['finalized_at'])->format('d.m.Y').' · '.$row['finalized_by_name']] : null,
                $row['next_review_due_on'] !== null ? [__(self::T.'fields.next_review_due_on'), $this->formatter->date($row['next_review_due_on'])] : null,
            ])),
            'purpose' => $row['purpose'],
            'conclusion' => $row['conclusion'],
            'participants' => $data['participants'],
            'sections' => $sections,
            'decisions' => $decisions,
            'frameworks' => array_map(fn (array $framework): array => [
                'name' => $framework['label'].($framework['version'] !== '' ? ' ('.$framework['version'].')' : ''),
                'coverage' => $framework['coverage'],
                'inputs' => array_map(fn (array $input): array => [
                    'clause' => $input['clause'],
                    'label' => __(self::T.'frameworks.'.$framework['key'].'.inputs.'.$input['key']),
                    'state' => __(self::T.'framework_states.'.$input['state']),
                ], $framework['inputs']),
            ], $data['frameworks']),
            'amendments' => array_map(fn (array $amendment): array => [
                'text' => $amendment['text'],
                'reason' => $amendment['reason'],
                'by' => $amendment['created_by_name'],
                'at' => CarbonImmutable::parse($amendment['created_at'])->format('d.m.Y H:i'),
            ], $data['amendments']),
            'history' => array_map(fn (array $event): array => [
                'event' => __(self::T.'events.'.$event['event']),
                'by' => $event['actor_name'],
                'at' => CarbonImmutable::parse($event['occurred_at'])->format('d.m.Y H:i'),
            ], $data['history']),
            'labels' => __(self::T.'report.labels'),
            'columns' => __(self::T.'columns'),
        ];
    }

    /** @param  array<string, mixed>  $decision */
    private function followUp(array $decision): ?string
    {
        if ($decision['kind'] !== 'action') {
            return null;
        }

        if ($decision['follow_up'] === 'own') {
            return __(self::T.'values.action_'.$decision['status']);
        }

        if (($decision['case']['hidden'] ?? false) || $decision['case'] === null) {
            return __(self::T.'decisions.case_hidden');
        }

        return __(self::T.'decisions.in_case', ['title' => $decision['case']['title']]).' · '.__(self::T.'values.case_'.$decision['case']['status']);
    }
}
