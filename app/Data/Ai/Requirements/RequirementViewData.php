<?php

namespace App\Data\Ai\Requirements;

use App\Models\SavedNoticeAiRequirement;
use JsonSerializable;

final readonly class RequirementViewData implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $savedNoticeId,
        public ?int $savedNoticeAiDocumentId,
        public ?int $savedNoticeAiDocumentChunkId,
        public ?string $sourceType,
        public string $sourceTypeLabel,
        public ?string $approvalStatus,
        public string $approvalStatusLabel,
        public ?string $reviewStatus,
        public string $reviewStatusLabel,
        public ?string $requirementIdentifier,
        public ?string $originalRequirementIdentifier,
        public string $requirementText,
        public ?string $originalRequirementText,
        public string $requirementType,
        public string $requirementTypeLabel,
        public string $extractionMethod,
        public string $extractionMethodLabel,
        public bool $isManual,
        public bool $isEdited,
        public bool $isApproved,
        public bool $isDraft,
        public bool $isRejected,
        public string $editStateLabel,
        public ?string $workStatus,
        public string $workStatusLabel,
        public ?int $assignedUserId,
        public ?string $documentFilename,
        public ?int $chunkIndex,
        public ?array $assignedUser,
        public ?string $approvedAt,
        public ?int $approvedByUserId,
        public ?string $rejectedAt,
        public ?int $rejectedByUserId,
        public int $revisionCount,
        public ?string $sourceRowKey = null,
        public ?string $sourceElementKey = null,
        public ?string $sourceElementType = null,
        public ?array $sourceReference = null,
        public ?array $extractionMetadata = null,
        public ?string $reviewStatusUpdateUrl = null,
        public ?string $editUrl = null,
        public ?string $workUpdateUrl = null,
        public ?string $assignedUserUpdateUrl = null,
    ) {}

    public static function fromRequirement(SavedNoticeAiRequirement $requirement, array $urls = []): self
    {
        $approvalStatus = $requirement->approval_status;
        $reviewStatus = $requirement->review_status;
        $editStateLabel = $requirement->isEdited() ? 'Redigert' : 'Original';

        return new self(
            id: $requirement->id,
            savedNoticeId: (int) $requirement->saved_notice_id,
            savedNoticeAiDocumentId: $requirement->saved_notice_ai_document_id !== null ? (int) $requirement->saved_notice_ai_document_id : null,
            savedNoticeAiDocumentChunkId: $requirement->saved_notice_ai_document_chunk_id !== null ? (int) $requirement->saved_notice_ai_document_chunk_id : null,
            sourceType: $requirement->source_type,
            sourceTypeLabel: $requirement->source_type_label,
            approvalStatus: $approvalStatus,
            approvalStatusLabel: $requirement->approval_status_label,
            reviewStatus: $reviewStatus,
            reviewStatusLabel: SavedNoticeAiRequirement::REVIEW_STATUS_LABELS[$reviewStatus] ?? (string) $reviewStatus,
            requirementIdentifier: $requirement->requirement_identifier,
            originalRequirementIdentifier: $requirement->original_requirement_identifier,
            requirementText: (string) $requirement->requirement_text,
            originalRequirementText: $requirement->original_requirement_text !== null ? (string) $requirement->original_requirement_text : null,
            requirementType: (string) $requirement->requirement_type,
            requirementTypeLabel: SavedNoticeAiRequirement::REQUIREMENT_TYPE_LABELS[$requirement->requirement_type]
                ?? (string) $requirement->requirement_type,
            extractionMethod: (string) $requirement->extraction_method,
            extractionMethodLabel: SavedNoticeAiRequirement::EXTRACTION_METHOD_LABELS[$requirement->extraction_method]
                ?? (string) $requirement->extraction_method,
            isManual: $requirement->is_manual,
            isEdited: $requirement->is_edited,
            isApproved: $requirement->is_approved,
            isDraft: $requirement->is_draft,
            isRejected: $requirement->is_rejected,
            editStateLabel: $editStateLabel,
            workStatus: $requirement->work_status,
            workStatusLabel: $requirement->work_status_label,
            assignedUserId: $requirement->assigned_user_id !== null ? (int) $requirement->assigned_user_id : null,
            documentFilename: $requirement->document?->original_filename,
            chunkIndex: $requirement->chunk?->chunk_index,
            assignedUser: $requirement->assignedUser ? [
                'id' => $requirement->assignedUser->id,
                'name' => $requirement->assignedUser->name,
                'email' => $requirement->assignedUser->email,
            ] : null,
            approvedAt: optional($requirement->approved_at)?->toIso8601String(),
            approvedByUserId: $requirement->approved_by_user_id !== null ? (int) $requirement->approved_by_user_id : null,
            rejectedAt: optional($requirement->rejected_at)?->toIso8601String(),
            rejectedByUserId: $requirement->rejected_by_user_id !== null ? (int) $requirement->rejected_by_user_id : null,
            revisionCount: (int) ($requirement->revisions_count ?? $requirement->revisions->count() ?? 0),
            sourceRowKey: $requirement->source_row_key,
            sourceElementKey: $requirement->source_element_key,
            sourceElementType: $requirement->source_element_type,
            sourceReference: is_array($requirement->source_reference) ? $requirement->source_reference : null,
            extractionMetadata: is_array($requirement->extraction_metadata) ? $requirement->extraction_metadata : null,
            reviewStatusUpdateUrl: $urls['review_status_update_url'] ?? null,
            editUrl: $urls['edit_url'] ?? null,
            workUpdateUrl: $urls['work_update_url'] ?? null,
            assignedUserUpdateUrl: $urls['assigned_user_update_url'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'saved_notice_id' => $this->savedNoticeId,
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'source_type' => $this->sourceType,
            'source_type_label' => $this->sourceTypeLabel,
            'approval_status' => $this->approvalStatus,
            'approval_status_label' => $this->approvalStatusLabel,
            'review_status' => $this->reviewStatus,
            'review_status_label' => $this->reviewStatusLabel,
            'requirement_identifier' => $this->requirementIdentifier,
            'current_requirement_identifier' => $this->requirementIdentifier,
            'original_requirement_identifier' => $this->originalRequirementIdentifier,
            'requirement_text' => $this->requirementText,
            'current_requirement_text' => $this->requirementText,
            'original_requirement_text' => $this->originalRequirementText,
            'requirement_type' => $this->requirementType,
            'requirement_type_label' => $this->requirementTypeLabel,
            'extraction_method' => $this->extractionMethod,
            'extraction_method_label' => $this->extractionMethodLabel,
            'is_manual' => $this->isManual,
            'is_edited' => $this->isEdited,
            'is_approved' => $this->isApproved,
            'is_draft' => $this->isDraft,
            'is_rejected' => $this->isRejected,
            'edit_state_label' => $this->editStateLabel,
            'work_status' => $this->workStatus,
            'work_status_label' => $this->workStatusLabel,
            'assigned_user_id' => $this->assignedUserId,
            'document_filename' => $this->documentFilename,
            'chunk_index' => $this->chunkIndex,
            'assigned_user' => $this->assignedUser,
            'approved_at' => $this->approvedAt,
            'approved_by_user_id' => $this->approvedByUserId,
            'rejected_at' => $this->rejectedAt,
            'rejected_by_user_id' => $this->rejectedByUserId,
            'revision_count' => $this->revisionCount,
            'source_row_key' => $this->sourceRowKey,
            'source_element_key' => $this->sourceElementKey,
            'source_element_type' => $this->sourceElementType,
            'source_reference' => $this->sourceReference,
            'extraction_metadata' => $this->extractionMetadata,
            'review_status_update_url' => $this->reviewStatusUpdateUrl,
            'edit_url' => $this->editUrl,
            'work_update_url' => $this->workUpdateUrl,
            'assigned_user_update_url' => $this->assignedUserUpdateUrl,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
