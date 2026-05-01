<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SavedNoticeAiRequirement extends Model
{
    public const SOURCE_TYPE_AI_CANDIDATE = 'ai_candidate';

    public const SOURCE_TYPE_MANUAL = 'manual';

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_AI_CANDIDATE,
        self::SOURCE_TYPE_MANUAL,
    ];

    public const SOURCE_TYPE_LABELS = [
        self::SOURCE_TYPE_AI_CANDIDATE => 'AI-kandidat',
        self::SOURCE_TYPE_MANUAL => 'Manuelt',
    ];

    public const REQUIREMENT_TYPE_MANDATORY = 'mandatory';

    public const REQUIREMENT_TYPE_DOCUMENTATION = 'documentation';

    public const REQUIREMENT_TYPE_ADMINISTRATIVE = 'administrative';

    public const REQUIREMENT_TYPE_UNSPECIFIED = 'unspecified';

    public const REQUIREMENT_TYPES = [
        self::REQUIREMENT_TYPE_MANDATORY,
        self::REQUIREMENT_TYPE_DOCUMENTATION,
        self::REQUIREMENT_TYPE_ADMINISTRATIVE,
        self::REQUIREMENT_TYPE_UNSPECIFIED,
    ];

    public const REQUIREMENT_TYPE_LABELS = [
        self::REQUIREMENT_TYPE_MANDATORY => 'Obligatorisk',
        self::REQUIREMENT_TYPE_DOCUMENTATION => 'Dokumentasjon',
        self::REQUIREMENT_TYPE_ADMINISTRATIVE => 'Administrativ',
        self::REQUIREMENT_TYPE_UNSPECIFIED => 'Uspesifisert',
    ];

    public const EXTRACTION_METHOD_RULE_BASED = 'rule_based';

    public const EXTRACTION_METHOD_AI_STRUCTURED = 'ai_structured';

    public const EXTRACTION_METHOD_AI_SEGMENTED = 'ai_segmented';

    public const EXTRACTION_METHOD_AI_PHASE_1 = 'phase_1_requirement_extraction';

    public const EXTRACTION_METHOD_AI_FULL_DOCUMENT = self::EXTRACTION_METHOD_AI_PHASE_1;

    public const EXTRACTION_METHOD_MANUAL = 'manual';

    public const EXTRACTION_METHOD_LABELS = [
        self::EXTRACTION_METHOD_AI_SEGMENTED => 'AI segmentert',
        self::EXTRACTION_METHOD_AI_PHASE_1 => 'Phase 1 krav-ekstraksjon',
        self::EXTRACTION_METHOD_AI_STRUCTURED => 'AI-basert',
        self::EXTRACTION_METHOD_RULE_BASED => 'Regelbasert',
        self::EXTRACTION_METHOD_MANUAL => 'Manuelt',
    ];

    public const APPROVAL_STATUS_DRAFT = 'draft';

    public const APPROVAL_STATUS_APPROVED = 'approved';

    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public const APPROVAL_STATUSES = [
        self::APPROVAL_STATUS_DRAFT,
        self::APPROVAL_STATUS_APPROVED,
        self::APPROVAL_STATUS_REJECTED,
    ];

    public const APPROVAL_STATUS_LABELS = [
        self::APPROVAL_STATUS_DRAFT => 'Utkast',
        self::APPROVAL_STATUS_APPROVED => 'Godkjent',
        self::APPROVAL_STATUS_REJECTED => 'Avvist',
    ];

    public const REVIEW_STATUS_PENDING = 'pending';

    public const REVIEW_STATUS_CONFIRMED = 'confirmed';

    public const REVIEW_STATUS_REJECTED = 'rejected';

    public const REVIEW_STATUSES = [
        self::REVIEW_STATUS_PENDING,
        self::REVIEW_STATUS_CONFIRMED,
        self::REVIEW_STATUS_REJECTED,
    ];

    public const REVIEW_STATUS_LABELS = [
        self::REVIEW_STATUS_PENDING => 'Til vurdering',
        self::REVIEW_STATUS_CONFIRMED => 'Bekreftet',
        self::REVIEW_STATUS_REJECTED => 'Avvist',
    ];

    public const WORK_STATUS_NOT_STARTED = 'not_started';

    public const WORK_STATUS_IN_PROGRESS = 'in_progress';

    public const WORK_STATUS_DONE = 'done';

    public const WORK_STATUSES = [
        self::WORK_STATUS_NOT_STARTED,
        self::WORK_STATUS_IN_PROGRESS,
        self::WORK_STATUS_DONE,
    ];

    public const WORK_STATUS_LABELS = [
        self::WORK_STATUS_NOT_STARTED => 'Ikke startet',
        self::WORK_STATUS_IN_PROGRESS => 'Under arbeid',
        self::WORK_STATUS_DONE => 'Ferdig',
    ];

    public const PUBLICATION_STATUS_STAGED = 'staged';

    public const PUBLICATION_STATUS_PUBLISHED = 'published';

    public const PUBLICATION_STATUS_SUPERSEDED = 'superseded';

    public const PUBLICATION_STATUSES = [
        self::PUBLICATION_STATUS_STAGED,
        self::PUBLICATION_STATUS_PUBLISHED,
        self::PUBLICATION_STATUS_SUPERSEDED,
    ];

    public const PUBLICATION_STATUS_LABELS = [
        self::PUBLICATION_STATUS_STAGED => 'Kladd',
        self::PUBLICATION_STATUS_PUBLISHED => 'Publisert',
        self::PUBLICATION_STATUS_SUPERSEDED => 'Erstattet',
    ];

    protected $fillable = [
        'saved_notice_id',
        'saved_notice_ai_document_id',
        'saved_notice_ai_document_chunk_id',
        'extraction_run_id',
        'source_type',
        'approval_status',
        'publication_status',
        'requirement_identifier',
        'original_requirement_identifier',
        'requirement_text',
        'original_requirement_text',
        'original_candidate_snapshot',
        'current_requirement_snapshot',
        'answer_draft_text',
        'answer_draft_generated_at',
        'answer_draft_retrieval_sources',
        'requirement_type',
        'extraction_method',
        'source_reference',
        'extraction_metadata',
        'review_status',
        'work_status',
        'assigned_user_id',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'published_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'saved_notice_ai_document_id' => 'integer',
            'saved_notice_ai_document_chunk_id' => 'integer',
            'extraction_run_id' => 'integer',
            'assigned_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'rejected_by_user_id' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
            'original_candidate_snapshot' => 'array',
            'current_requirement_snapshot' => 'array',
            'answer_draft_generated_at' => 'datetime',
            'answer_draft_retrieval_sources' => 'array',
            'source_reference' => 'array',
            'extraction_metadata' => 'array',
        ];
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiDocument::class, 'saved_notice_ai_document_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiDocumentChunk::class, 'saved_notice_ai_document_chunk_id');
    }

    public function extractionRun(): BelongsTo
    {
        return $this->belongsTo(RequirementExtractionRun::class, 'extraction_run_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Purpose: Resolve the persisted AI assessment for this requirement candidate.
     * Inputs: None.
     * Returns: The single related assessment row.
     * Side effects: None.
     */
    public function assessment(): HasOne
    {
        return $this->hasOne(SavedNoticeAiRequirementAssessment::class, 'saved_notice_ai_requirement_id');
    }

    /**
     * Purpose: Resolve the persisted evidence rows for this requirement candidate.
     * Inputs: None.
     * Returns: The related evidence collection query.
     * Side effects: None.
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(SavedNoticeAiEvidence::class, 'saved_notice_ai_requirement_id')
            ->orderByRaw("
                CASE selection_status
                    WHEN 'selected' THEN 0
                    WHEN 'suggested' THEN 1
                    WHEN 'rejected' THEN 2
                    ELSE 3
                END
            ")
            ->orderByDesc('is_primary')
            ->orderBy('match_rank')
            ->orderBy('id');
    }

    /**
     * Purpose: Resolve the selected answer basis items for this requirement candidate.
     * Inputs: None.
     * Returns: The selected answer basis item relation.
     * Side effects: None.
     */
    public function answerBasisItems(): BelongsToMany
    {
        return $this->belongsToMany(
            SavedNoticeAiAnswerBasisItem::class,
            'saved_notice_ai_requirement_answer_basis_selections',
            'saved_notice_ai_requirement_id',
            'saved_notice_ai_answer_basis_item_id',
        )
            ->select('saved_notice_ai_answer_basis_items.*')
            ->withTimestamps()
            ->orderBy('saved_notice_ai_requirement_answer_basis_selections.created_at')
            ->orderBy('saved_notice_ai_requirement_answer_basis_selections.id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SavedNoticeAiRequirementRevision::class, 'saved_notice_ai_requirement_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_APPROVED
            || $this->review_status === self::REVIEW_STATUS_CONFIRMED;
    }

    public function isDraft(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_DRAFT
            || $this->review_status === self::REVIEW_STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::APPROVAL_STATUS_REJECTED
            || $this->review_status === self::REVIEW_STATUS_REJECTED;
    }

    public function isManual(): bool
    {
        return $this->source_type === self::SOURCE_TYPE_MANUAL;
    }

    public function isPublished(): bool
    {
        return $this->publication_status === self::PUBLICATION_STATUS_PUBLISHED;
    }

    public function isStaged(): bool
    {
        return $this->publication_status === self::PUBLICATION_STATUS_STAGED;
    }

    public function isSuperseded(): bool
    {
        return $this->publication_status === self::PUBLICATION_STATUS_SUPERSEDED;
    }

    public function isEdited(): bool
    {
        $currentText = trim((string) $this->requirement_text);
        $originalText = trim((string) ($this->original_requirement_text ?? $this->requirement_text));
        $currentIdentifier = trim((string) ($this->requirement_identifier ?? ''));
        $originalIdentifier = trim((string) ($this->original_requirement_identifier ?? ''));

        return $currentText !== $originalText || $currentIdentifier !== $originalIdentifier;
    }

    public static function approvalStatusForReviewStatus(?string $reviewStatus): string
    {
        return match ($reviewStatus) {
            self::REVIEW_STATUS_CONFIRMED => self::APPROVAL_STATUS_APPROVED,
            self::REVIEW_STATUS_REJECTED => self::APPROVAL_STATUS_REJECTED,
            default => self::APPROVAL_STATUS_DRAFT,
        };
    }

    public static function reviewStatusForApprovalStatus(?string $approvalStatus): string
    {
        return match ($approvalStatus) {
            self::APPROVAL_STATUS_APPROVED => self::REVIEW_STATUS_CONFIRMED,
            self::APPROVAL_STATUS_REJECTED => self::REVIEW_STATUS_REJECTED,
            default => self::REVIEW_STATUS_PENDING,
        };
    }

    public function getSourceTypeLabelAttribute(): string
    {
        $sourceType = (string) ($this->source_type ?: self::SOURCE_TYPE_AI_CANDIDATE);

        return self::SOURCE_TYPE_LABELS[$sourceType] ?? $sourceType;
    }

    public function getApprovalStatusAttribute(mixed $value): string
    {
        $approvalStatus = is_string($value) ? $value : '';

        if (in_array($approvalStatus, self::APPROVAL_STATUSES, true) && $approvalStatus !== self::APPROVAL_STATUS_DRAFT) {
            return $approvalStatus;
        }

        if ($this->review_status === self::REVIEW_STATUS_CONFIRMED) {
            return self::APPROVAL_STATUS_APPROVED;
        }

        if ($this->review_status === self::REVIEW_STATUS_REJECTED) {
            return self::APPROVAL_STATUS_REJECTED;
        }

        return self::APPROVAL_STATUS_DRAFT;
    }

    public function getApprovalStatusLabelAttribute(): string
    {
        $approvalStatus = $this->approval_status;

        return self::APPROVAL_STATUS_LABELS[$approvalStatus] ?? $approvalStatus;
    }

    public function getReviewStatusLabelAttribute(): string
    {
        $reviewStatus = (string) ($this->review_status ?: self::REVIEW_STATUS_PENDING);

        return self::REVIEW_STATUS_LABELS[$reviewStatus] ?? $reviewStatus;
    }

    public function getWorkStatusLabelAttribute(): string
    {
        $workStatus = (string) ($this->work_status ?: self::WORK_STATUS_NOT_STARTED);

        return self::WORK_STATUS_LABELS[$workStatus] ?? $workStatus;
    }

    public function getPublicationStatusLabelAttribute(): string
    {
        $publicationStatus = (string) ($this->publication_status ?: self::PUBLICATION_STATUS_STAGED);

        return self::PUBLICATION_STATUS_LABELS[$publicationStatus] ?? $publicationStatus;
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->isApproved();
    }

    public function getIsDraftAttribute(): bool
    {
        return $this->isDraft();
    }

    public function getIsRejectedAttribute(): bool
    {
        return $this->isRejected();
    }

    public function getIsManualAttribute(): bool
    {
        return $this->isManual();
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->isPublished();
    }

    public function getIsStagedAttribute(): bool
    {
        return $this->isStaged();
    }

    public function getIsSupersededAttribute(): bool
    {
        return $this->isSuperseded();
    }

    public function getIsEditedAttribute(): bool
    {
        return $this->isEdited();
    }
}
