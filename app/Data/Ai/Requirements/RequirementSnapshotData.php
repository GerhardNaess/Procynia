<?php

namespace App\Data\Ai\Requirements;

use App\Models\SavedNoticeAiRequirement;
use JsonSerializable;

final readonly class RequirementSnapshotData implements JsonSerializable
{
    public function __construct(
        public ?int $id,
        public int $savedNoticeId,
        public ?int $savedNoticeAiDocumentId,
        public ?int $savedNoticeAiDocumentChunkId,
        public ?array $sourceReference,
        public ?array $extractionMetadata,
        public ?string $sourceType,
        public ?string $approvalStatus,
        public ?string $reviewStatus,
        public ?string $workStatus,
        public ?int $assignedUserId,
        public ?string $requirementIdentifier,
        public ?string $originalRequirementIdentifier,
        public string $requirementText,
        public ?string $originalRequirementText,
        public ?string $requirementType,
        public ?string $extractionMethod,
        public ?string $approvedAt,
        public ?int $approvedByUserId,
        public ?string $rejectedAt,
        public ?int $rejectedByUserId,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }

    public static function fromRequirement(SavedNoticeAiRequirement $requirement): self
    {
        return new self(
            id: $requirement->id,
            savedNoticeId: (int) $requirement->saved_notice_id,
            savedNoticeAiDocumentId: $requirement->saved_notice_ai_document_id !== null
                ? (int) $requirement->saved_notice_ai_document_id
                : null,
            savedNoticeAiDocumentChunkId: $requirement->saved_notice_ai_document_chunk_id !== null
                ? (int) $requirement->saved_notice_ai_document_chunk_id
                : null,
            sourceReference: is_array($requirement->source_reference) ? $requirement->source_reference : null,
            extractionMetadata: is_array($requirement->extraction_metadata) ? $requirement->extraction_metadata : null,
            sourceType: $requirement->source_type,
            approvalStatus: $requirement->approval_status,
            reviewStatus: $requirement->review_status,
            workStatus: $requirement->work_status,
            assignedUserId: $requirement->assigned_user_id !== null
                ? (int) $requirement->assigned_user_id
                : null,
            requirementIdentifier: $requirement->requirement_identifier,
            originalRequirementIdentifier: $requirement->original_requirement_identifier,
            requirementText: (string) $requirement->requirement_text,
            originalRequirementText: $requirement->original_requirement_text !== null
                ? (string) $requirement->original_requirement_text
                : null,
            requirementType: $requirement->requirement_type,
            extractionMethod: $requirement->extraction_method,
            approvedAt: optional($requirement->approved_at)?->toIso8601String(),
            approvedByUserId: $requirement->approved_by_user_id !== null
                ? (int) $requirement->approved_by_user_id
                : null,
            rejectedAt: optional($requirement->rejected_at)?->toIso8601String(),
            rejectedByUserId: $requirement->rejected_by_user_id !== null
                ? (int) $requirement->rejected_by_user_id
                : null,
            createdAt: optional($requirement->created_at)?->toIso8601String(),
            updatedAt: optional($requirement->updated_at)?->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'saved_notice_id' => $this->savedNoticeId,
            'saved_notice_ai_document_id' => $this->savedNoticeAiDocumentId,
            'saved_notice_ai_document_chunk_id' => $this->savedNoticeAiDocumentChunkId,
            'source_reference' => $this->sourceReference,
            'extraction_metadata' => $this->extractionMetadata,
            'source_type' => $this->sourceType,
            'approval_status' => $this->approvalStatus,
            'review_status' => $this->reviewStatus,
            'work_status' => $this->workStatus,
            'assigned_user_id' => $this->assignedUserId,
            'requirement_identifier' => $this->requirementIdentifier,
            'original_requirement_identifier' => $this->originalRequirementIdentifier,
            'requirement_text' => $this->requirementText,
            'original_requirement_text' => $this->originalRequirementText,
            'requirement_type' => $this->requirementType,
            'extraction_method' => $this->extractionMethod,
            'approved_at' => $this->approvedAt,
            'approved_by_user_id' => $this->approvedByUserId,
            'rejected_at' => $this->rejectedAt,
            'rejected_by_user_id' => $this->rejectedByUserId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
