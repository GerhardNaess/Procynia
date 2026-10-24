<?php

namespace App\Services\Api\Presenters;

use App\Models\SavedNotice;
use App\Models\SavedNoticeAiRequirement;
use App\Services\SavedNoticeWorkflowSignals;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * An anbudssak and its requirements as the external API presents them: structured fields only (D8,
 * D27).
 *
 * A case: identifiers, title, source type, bid status, department, tilbudsansvarlig and
 * mulighetseier, the deadlines, the workflow signals (SavedNoticeWorkflowSignals — active, deadlines
 * inside the threshold, last activity), the outcome of an archived case, and a fixed selection from
 * the register record (D25). The register record is never serialized as a model: the old internal
 * admin fields on notices (K16) cannot reach a response.
 *
 * A requirement: identifiers, type, review and work status, ansvarlig, the coverage of its Wiki
 * answer, whether an answer draft exists, and the source document's name.
 *
 * Out: requirement text and answer text, phase comments and info items, Go/No-go reasoning and
 * closure notes, notes and summary, contact persons, files, AI traces and web routes.
 */
class TenderPresenter
{
    /** The deadlines a case carries, as the API names them => the column. */
    public const DEADLINES = [
        'submission' => 'deadline',
        'questions' => 'questions_deadline_at',
        'questions_rfi' => 'questions_rfi_deadline_at',
        'rfi_submission' => 'rfi_submission_deadline_at',
        'questions_rfp' => 'questions_rfp_deadline_at',
    ];

    public function __construct(private readonly SavedNoticeWorkflowSignals $signals) {}

    /**
     * @param  Collection<int, SavedNotice>  $cases  already reached through SavedNoticeAccessService::integrationReadableQuery(),
     *                                               with the relations TenderController loads
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $cases): array
    {
        return $cases->map(fn (SavedNotice $case): array => $this->case($case))->values()->all();
    }

    /** @return array<string, mixed> */
    public function case(SavedNotice $case): array
    {
        $columns = array_flip(self::DEADLINES);

        return [
            'id' => (int) $case->id,
            'title' => $case->title,
            'source_type' => $case->source_type,
            'bid_status' => $case->bid_status,
            'department' => $this->named($case->organizationalDepartment),
            'bid_manager' => $this->named($case->bidManager),
            'opportunity_owner' => $this->named($case->opportunityOwner),
            'deadlines' => array_map(fn (string $column): ?string => $this->utc($case->{$column}), self::DEADLINES),
            'signals' => [
                'is_active' => $this->signals->isActive($case),
                // The dates someone has to act on inside the threshold, soonest first.
                'deadlines_soon' => $this->signals->isActive($case)
                    ? array_values(array_filter(array_map(
                        fn (array $entry): ?array => isset($columns[$entry['type']])
                            ? ['type' => $columns[$entry['type']], 'at' => $this->utc($entry['date'])]
                            : null,
                        $this->signals->actionableDeadlinesWithin($case),
                    )))
                    : [],
                'deadline_soon_days' => SavedNoticeWorkflowSignals::DEADLINE_SOON_DAYS,
                'latest_activity_at' => $this->utc($this->signals->latestActivityAt($case)),
            ],
            'archived_at' => $this->utc($case->archived_at),
            'outcome' => $case->archived_at !== null ? $case->history_type : null,
            // The register record, a fixed selection (D25). Null for a private request.
            'notice' => $case->source_type === SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST ? null : [
                'source' => $case->source,
                'external_id' => $case->external_id,
                'buyer_name' => $case->buyer_name,
                'cpv_code' => $case->cpv_code,
                'notice_type' => $case->notice?->notice_type,
                'published_at' => $this->utc($case->publication_date),
            ],
            'created_at' => $this->utc($case->created_at),
            'updated_at' => $this->utc($case->updated_at),
        ];
    }

    /**
     * @param  Collection<int, SavedNoticeAiRequirement>  $requirements  from RequirementLoader::loadForCase() for a case already found
     * @return list<array<string, mixed>>
     */
    public function requirements(Collection $requirements): array
    {
        return $requirements->map(fn (SavedNoticeAiRequirement $requirement): array => [
            'id' => (int) $requirement->id,
            'identifier' => $requirement->requirement_identifier,
            'requirement_type' => $requirement->requirement_type,
            'review_status' => $requirement->review_status,
            'work_status' => $requirement->work_status,
            'assignee' => $this->named($requirement->assignedUser),
            // The Wiki answer is the only answer engine; the old answer_draft_* columns belonged to
            // the decommissioned Knowledge Base and are not read.
            'coverage' => $requirement->wikiAnswer?->coverage_status,
            'has_answer_draft' => trim((string) ($requirement->wikiAnswer?->answer_text ?? '')) !== '',
            'source_document' => $requirement->document !== null ? ['name' => $requirement->document->original_filename] : null,
        ])->values()->all();
    }

    /** @return array{id: int, name: string}|null */
    private function named(mixed $model): ?array
    {
        return $model !== null ? ['id' => (int) $model->id, 'name' => (string) $model->name] : null;
    }

    private function utc(?CarbonInterface $at): ?string
    {
        return $at !== null ? CarbonImmutable::instance($at)->utc()->toIso8601ZuluString() : null;
    }
}
