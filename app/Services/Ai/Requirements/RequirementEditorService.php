<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\RequirementCandidateData;
use App\Data\Ai\Requirements\RequirementEditData;
use App\Data\Ai\Requirements\RequirementSnapshotData;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RequirementEditorService
{
    /**
     * Purpose: Persist one AI-generated requirement candidate as a traceable aggregate row.
     * Inputs: The source AI document and chunk plus the extracted candidate data.
     * Returns: The persisted canonical requirement row.
     * Side effects: Creates a requirement row and a matching revision row.
     */
    public function createAiCandidate(
        SavedNoticeAiDocument $document,
        ?SavedNoticeAiDocumentChunk $chunk,
        RequirementCandidateData $candidate,
        ?User $changedBy = null,
        array $publicationOverrides = [],
    ): SavedNoticeAiRequirement {
        return DB::transaction(function () use ($document, $chunk, $candidate, $changedBy, $publicationOverrides): SavedNoticeAiRequirement {
            $requirement = SavedNoticeAiRequirement::query()->create(array_merge(
                $candidate->toCreationAttributes(),
                [
                    'extraction_run_id' => null,
                    'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
                    'published_at' => now(),
                    'superseded_at' => null,
                ],
                $publicationOverrides,
            ));

            $snapshot = RequirementSnapshotData::fromRequirement($requirement)->toArray();

            $requirement->forceFill([
                'original_candidate_snapshot' => $candidate->toSnapshotArray(),
                'current_requirement_snapshot' => $snapshot,
            ])->save();

            $this->persistRevision(
                requirement: $requirement,
                beforeSnapshot: null,
                afterSnapshot: $snapshot,
                changedFields: [
                    'saved_notice_id',
                    'saved_notice_ai_document_id',
                    'saved_notice_ai_document_chunk_id',
                    'extraction_run_id',
                    'publication_status',
                    'published_at',
                    'superseded_at',
                    'source_type',
                    'approval_status',
                    'review_status',
                    'requirement_identifier',
                    'original_requirement_identifier',
                    'requirement_text',
                    'original_requirement_text',
                    'requirement_type',
                    'extraction_method',
                    'source_reference',
                    'extraction_metadata',
                ],
                changeType: SavedNoticeAiRequirementRevision::CHANGE_TYPE_CREATE_AI_CANDIDATE,
                changedBy: $changedBy,
            );

            return $this->loadRequirement($requirement->id);
        });
    }

    /**
     * Purpose: Persist one manually created requirement as the canonical aggregate row.
     * Inputs: The saved notice, the manual requirement data, and the actor who changed it.
     * Returns: The persisted canonical requirement row.
     * Side effects: Creates a requirement row and a matching revision row.
     */
    public function createManualRequirement(
        SavedNotice $savedNotice,
        RequirementEditData $data,
        ?User $changedBy = null,
    ): SavedNoticeAiRequirement {
        return DB::transaction(function () use ($savedNotice, $data, $changedBy): SavedNoticeAiRequirement {
            $requirement = SavedNoticeAiRequirement::query()->create([
                'saved_notice_id' => $savedNotice->id,
                'saved_notice_ai_document_id' => null,
                'saved_notice_ai_document_chunk_id' => null,
                'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
                'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
                'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
                'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
                'requirement_identifier' => $this->normalizeIdentifier($data->requirementIdentifier),
                'original_requirement_identifier' => $this->normalizeIdentifier($data->requirementIdentifier),
                'requirement_text' => $this->normalizeText($data->requirementText),
                'original_requirement_text' => $this->normalizeText($data->requirementText),
                'requirement_type' => $data->requirementType,
                'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_MANUAL,
                'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
                'assigned_user_id' => null,
                'approved_at' => null,
                'approved_by_user_id' => null,
                'rejected_at' => null,
                'rejected_by_user_id' => null,
                'published_at' => now(),
                'superseded_at' => null,
                'source_reference' => [
                    'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
                    'manual' => true,
                ],
                'extraction_metadata' => [
                    'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
                    'reason' => $data->reason,
                    'manual' => true,
                ],
                'original_candidate_snapshot' => null,
                'current_requirement_snapshot' => null,
            ]);

            $snapshot = RequirementSnapshotData::fromRequirement($requirement)->toArray();

            $requirement->forceFill([
                'original_candidate_snapshot' => $snapshot,
                'current_requirement_snapshot' => $snapshot,
            ])->save();

            $this->persistRevision(
                requirement: $requirement,
                beforeSnapshot: null,
                afterSnapshot: $snapshot,
                changedFields: [
                    'saved_notice_id',
                    'publication_status',
                    'source_type',
                    'approval_status',
                    'review_status',
                    'requirement_identifier',
                    'original_requirement_identifier',
                    'requirement_text',
                    'original_requirement_text',
                    'requirement_type',
                    'source_reference',
                    'extraction_metadata',
                ],
                changeType: SavedNoticeAiRequirementRevision::CHANGE_TYPE_MANUAL_CREATE,
                changedBy: $changedBy,
                reason: $data->reason,
            );

            return $this->loadRequirement($requirement->id);
        });
    }

    /**
     * Purpose: Update the canonical identifier/text/metadata for one requirement.
     * Inputs: The existing requirement row, the edit data, and the actor who changed it.
     * Returns: The persisted canonical requirement row.
     * Side effects: Writes a revision row and resets workflow state when necessary.
     */
    public function updateRequirement(
        SavedNoticeAiRequirement $requirement,
        RequirementEditData $data,
        ?User $changedBy = null,
    ): SavedNoticeAiRequirement {
        return DB::transaction(function () use ($requirement, $data, $changedBy): SavedNoticeAiRequirement {
            $beforeSnapshot = RequirementSnapshotData::fromRequirement($requirement)->toArray();
            $changedFields = [];
            $userChangedFields = [];
            $originalRequirementText = $requirement->original_requirement_text ?? $requirement->requirement_text;
            $originalRequirementIdentifier = $requirement->original_requirement_identifier ?? $requirement->requirement_identifier;

            $normalizedIdentifier = $this->normalizeIdentifier($data->requirementIdentifier);
            $normalizedText = $this->normalizeText($data->requirementText);

            if ($requirement->requirement_identifier !== $normalizedIdentifier) {
                $requirement->forceFill([
                    'requirement_identifier' => $normalizedIdentifier,
                ]);
                $changedFields[] = 'requirement_identifier';
                $userChangedFields[] = 'requirement_identifier';
            }

            if ($requirement->requirement_text !== $normalizedText) {
                $requirement->forceFill([
                    'requirement_text' => $normalizedText,
                ]);
                $changedFields[] = 'requirement_text';
                $userChangedFields[] = 'requirement_text';
            }

            if ($requirement->requirement_type !== $data->requirementType) {
                $requirement->forceFill([
                    'requirement_type' => $data->requirementType,
                ]);
                $changedFields[] = 'requirement_type';
                $userChangedFields[] = 'requirement_type';
            }

            $workflowReset = false;

            if (($requirement->isApproved() || $requirement->isRejected()) && $userChangedFields !== []) {
                $workflowReset = true;
                $changedFields[] = 'approval_status';
                $changedFields[] = 'review_status';
                $changedFields[] = 'approved_at';
                $changedFields[] = 'approved_by_user_id';
                $changedFields[] = 'rejected_at';
                $changedFields[] = 'rejected_by_user_id';
                $changedFields[] = 'work_status';
                $changedFields[] = 'assigned_user_id';

                $requirement->forceFill([
                    'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
                    'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
                    'approved_at' => null,
                    'approved_by_user_id' => null,
                    'rejected_at' => null,
                    'rejected_by_user_id' => null,
                    'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
                    'assigned_user_id' => null,
                ]);
            }

            if ($changedFields === []) {
                return $requirement;
            }

            $requirement->forceFill([
                'original_requirement_text' => $originalRequirementText,
                'original_requirement_identifier' => $originalRequirementIdentifier,
            ])->save();

            $afterSnapshot = RequirementSnapshotData::fromRequirement($requirement)->toArray();

            $requirement->forceFill([
                'current_requirement_snapshot' => $afterSnapshot,
            ])->save();

            $this->persistRevision(
                requirement: $requirement,
                beforeSnapshot: $beforeSnapshot,
                afterSnapshot: $afterSnapshot,
                changedFields: array_values(array_unique($changedFields)),
                changeType: $this->changeTypeForEdit($changedFields, $workflowReset),
                changedBy: $changedBy,
                reason: $data->reason,
            );

            return $this->loadRequirement($requirement->id);
        });
    }

    /**
     * Purpose: Move a requirement into the approved canonical state.
     * Inputs: The requirement row and the actor who approved it.
     * Returns: The persisted canonical requirement row.
     * Side effects: Writes a revision row and updates approval metadata.
     */
    public function approveRequirement(SavedNoticeAiRequirement $requirement, ?User $changedBy = null): SavedNoticeAiRequirement
    {
        return $this->transitionRequirementReviewStatus(
            requirement: $requirement,
            reviewStatus: SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            changedBy: $changedBy,
        );
    }

    /**
     * Purpose: Move a requirement out of the active set and into the rejected state.
     * Inputs: The requirement row and the actor who rejected it.
     * Returns: The persisted canonical requirement row.
     * Side effects: Writes a revision row and clears active work assignments.
     */
    public function rejectRequirement(SavedNoticeAiRequirement $requirement, ?User $changedBy = null): SavedNoticeAiRequirement
    {
        return $this->transitionRequirementReviewStatus(
            requirement: $requirement,
            reviewStatus: SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
            changedBy: $changedBy,
        );
    }

    /**
     * Purpose: Restore a requirement to draft status for further user editing.
     * Inputs: The requirement row and the actor who restored it.
     * Returns: The persisted canonical requirement row.
     * Side effects: Writes a revision row and clears approval metadata.
     */
    public function restoreRequirement(SavedNoticeAiRequirement $requirement, ?User $changedBy = null): SavedNoticeAiRequirement
    {
        return $this->transitionRequirementReviewStatus(
            requirement: $requirement,
            reviewStatus: SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            changedBy: $changedBy,
        );
    }

    /**
     * Purpose: Update the legacy review status API while keeping the canonical approval status in sync.
     * Inputs: The requirement row, a legacy review status, and the actor who changed it.
     * Returns: The persisted canonical requirement row.
     * Side effects: Writes a revision row and synchronizes approval metadata.
     */
    public function transitionRequirementReviewStatus(
        SavedNoticeAiRequirement $requirement,
        string $reviewStatus,
        ?User $changedBy = null,
    ): SavedNoticeAiRequirement {
        return DB::transaction(function () use ($requirement, $reviewStatus, $changedBy): SavedNoticeAiRequirement {
            $normalizedReviewStatus = in_array($reviewStatus, SavedNoticeAiRequirement::REVIEW_STATUSES, true)
                ? $reviewStatus
                : SavedNoticeAiRequirement::REVIEW_STATUS_PENDING;
            $normalizedApprovalStatus = SavedNoticeAiRequirement::approvalStatusForReviewStatus($normalizedReviewStatus);

            if (
                $requirement->review_status === $normalizedReviewStatus
                && $requirement->approval_status === $normalizedApprovalStatus
            ) {
                return $requirement;
            }

            $beforeSnapshot = RequirementSnapshotData::fromRequirement($requirement)->toArray();
            $changedFields = [];
            $rawApprovalStatusNeedsUpdate = $requirement->getRawOriginal('approval_status') !== $normalizedApprovalStatus;

            if ($requirement->review_status !== $normalizedReviewStatus) {
                $changedFields[] = 'review_status';
                $requirement->forceFill([
                    'review_status' => $normalizedReviewStatus,
                ]);
            }

            if ($rawApprovalStatusNeedsUpdate) {
                $changedFields[] = 'approval_status';
                $requirement->forceFill([
                    'approval_status' => $normalizedApprovalStatus,
                ]);
            }

            if ($normalizedApprovalStatus === SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED) {
                $changedFields[] = 'approved_at';
                $changedFields[] = 'approved_by_user_id';
                $changedFields[] = 'rejected_at';
                $changedFields[] = 'rejected_by_user_id';
                $requirement->forceFill([
                    'approved_at' => now(),
                    'approved_by_user_id' => $changedBy?->id,
                    'rejected_at' => null,
                    'rejected_by_user_id' => null,
                ]);
            } elseif ($normalizedApprovalStatus === SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED) {
                $changedFields[] = 'approved_at';
                $changedFields[] = 'approved_by_user_id';
                $changedFields[] = 'rejected_at';
                $changedFields[] = 'rejected_by_user_id';
                $changedFields[] = 'work_status';
                $changedFields[] = 'assigned_user_id';
                $requirement->forceFill([
                    'approved_at' => null,
                    'approved_by_user_id' => null,
                    'rejected_at' => now(),
                    'rejected_by_user_id' => $changedBy?->id,
                    'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
                    'assigned_user_id' => null,
                ]);
            } else {
                $changedFields[] = 'approved_at';
                $changedFields[] = 'approved_by_user_id';
                $changedFields[] = 'rejected_at';
                $changedFields[] = 'rejected_by_user_id';
                $changedFields[] = 'work_status';
                $changedFields[] = 'assigned_user_id';
                $requirement->forceFill([
                    'approved_at' => null,
                    'approved_by_user_id' => null,
                    'rejected_at' => null,
                    'rejected_by_user_id' => null,
                    'work_status' => SavedNoticeAiRequirement::WORK_STATUS_NOT_STARTED,
                    'assigned_user_id' => null,
                ]);
            }

            if ($changedFields === []) {
                return $requirement;
            }

            $requirement->save();

            $afterSnapshot = RequirementSnapshotData::fromRequirement($requirement)->toArray();
            $requirement->forceFill([
                'current_requirement_snapshot' => $afterSnapshot,
            ])->save();

            $this->persistRevision(
                requirement: $requirement,
                beforeSnapshot: $beforeSnapshot,
                afterSnapshot: $afterSnapshot,
                changedFields: array_values(array_unique($changedFields)),
                changeType: $this->changeTypeForWorkflowState($normalizedApprovalStatus),
                changedBy: $changedBy,
            );

            return $this->loadRequirement($requirement->id);
        });
    }

    private function changeTypeForEdit(array $changedFields, bool $workflowReset): string
    {
        $editedFields = array_values(array_filter($changedFields, static function (string $field): bool {
            return in_array($field, ['requirement_identifier', 'requirement_text', 'requirement_type'], true);
        }));

        if ($workflowReset) {
            return SavedNoticeAiRequirementRevision::CHANGE_TYPE_EDIT_METADATA;
        }

        if ($editedFields === ['requirement_identifier']) {
            return SavedNoticeAiRequirementRevision::CHANGE_TYPE_EDIT_IDENTIFIER;
        }

        if ($editedFields === ['requirement_text']) {
            return SavedNoticeAiRequirementRevision::CHANGE_TYPE_EDIT_TEXT;
        }

        return SavedNoticeAiRequirementRevision::CHANGE_TYPE_EDIT_METADATA;
    }

    private function changeTypeForWorkflowState(string $approvalStatus): string
    {
        return match ($approvalStatus) {
            SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED => SavedNoticeAiRequirementRevision::CHANGE_TYPE_APPROVE,
            SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED => SavedNoticeAiRequirementRevision::CHANGE_TYPE_REJECT,
            default => SavedNoticeAiRequirementRevision::CHANGE_TYPE_RESTORE,
        };
    }

    private function persistRevision(
        SavedNoticeAiRequirement $requirement,
        ?array $beforeSnapshot,
        array $afterSnapshot,
        array $changedFields,
        string $changeType,
        ?User $changedBy = null,
        ?string $reason = null,
    ): SavedNoticeAiRequirementRevision {
        return SavedNoticeAiRequirementRevision::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'saved_notice_id' => $requirement->saved_notice_id,
            'changed_by_user_id' => $changedBy?->id,
            'change_type' => $changeType,
            'before_snapshot' => $beforeSnapshot,
            'after_snapshot' => $afterSnapshot,
            'changed_fields' => array_values(array_unique($changedFields)),
            'reason' => $reason,
        ]);
    }

    private function loadRequirement(int $requirementId): SavedNoticeAiRequirement
    {
        return SavedNoticeAiRequirement::query()
            ->with([
                'document',
                'chunk',
                'assignedUser',
                'revisions',
            ])
            ->withCount('revisions')
            ->findOrFail($requirementId);
    }

    private function normalizeText(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $normalized;
    }

    private function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $normalized === '' ? null : $normalized;
    }
}
