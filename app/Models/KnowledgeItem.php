<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KnowledgeItem extends Model
{
    public const OWNERSHIP_TYPE_COMPANY = 'company';

    public const OWNERSHIP_TYPE_PERSONAL = 'personal';

    public const OWNERSHIP_TYPE_CASE = 'case';

    public const OWNERSHIP_TYPES = [
        self::OWNERSHIP_TYPE_COMPANY,
        self::OWNERSHIP_TYPE_PERSONAL,
        self::OWNERSHIP_TYPE_CASE,
    ];

    protected $attributes = [
        'ownership_type' => self::OWNERSHIP_TYPE_COMPANY,
        'ai_usage_enabled' => true,
        'document_status' => self::DOCUMENT_STATUS_ACTIVE,
    ];

    public const DOCUMENT_TYPE_COMPANY = 'company';

    public const DOCUMENT_TYPE_METHOD = 'method';

    public const DOCUMENT_TYPE_REFERENCE = 'reference';

    public const DOCUMENT_TYPE_CV = 'cv';

    public const DOCUMENT_TYPE_BOILERPLATE = 'boilerplate';

    public const DOCUMENT_TYPE_OTHER = 'other';

    public const DOCUMENT_TYPES = [
        self::DOCUMENT_TYPE_COMPANY,
        self::DOCUMENT_TYPE_METHOD,
        self::DOCUMENT_TYPE_REFERENCE,
        self::DOCUMENT_TYPE_CV,
        self::DOCUMENT_TYPE_BOILERPLATE,
        self::DOCUMENT_TYPE_OTHER,
    ];

    public const DOCUMENT_TYPE_LABELS = [
        self::DOCUMENT_TYPE_COMPANY => 'Bedrift',
        self::DOCUMENT_TYPE_METHOD => 'Metode',
        self::DOCUMENT_TYPE_REFERENCE => 'Referanse',
        self::DOCUMENT_TYPE_CV => 'CV',
        self::DOCUMENT_TYPE_BOILERPLATE => 'Standardtekst',
        self::DOCUMENT_TYPE_OTHER => 'Annet',
    ];

    public const CONTENT_TYPE_COMPANY = self::DOCUMENT_TYPE_COMPANY;

    public const CONTENT_TYPE_METHOD = self::DOCUMENT_TYPE_METHOD;

    public const CONTENT_TYPE_REFERENCE = self::DOCUMENT_TYPE_REFERENCE;

    public const CONTENT_TYPE_CV = self::DOCUMENT_TYPE_CV;

    public const CONTENT_TYPE_BOILERPLATE = self::DOCUMENT_TYPE_BOILERPLATE;

    public const CONTENT_TYPE_OTHER = self::DOCUMENT_TYPE_OTHER;

    public const CONTENT_TYPES = self::DOCUMENT_TYPES;

    public const CONTENT_TYPE_LABELS = self::DOCUMENT_TYPE_LABELS;

    public const DOCUMENT_STATUS_DRAFT = 'draft';

    public const DOCUMENT_STATUS_PENDING_REVIEW = 'pending_review';

    public const DOCUMENT_STATUS_ACTIVE = 'active';

    public const DOCUMENT_STATUS_EXPIRED = 'expired';

    public const DOCUMENT_STATUS_ARCHIVED = 'archived';

    public const DOCUMENT_STATUSES = [
        self::DOCUMENT_STATUS_DRAFT,
        self::DOCUMENT_STATUS_PENDING_REVIEW,
        self::DOCUMENT_STATUS_ACTIVE,
        self::DOCUMENT_STATUS_EXPIRED,
        self::DOCUMENT_STATUS_ARCHIVED,
    ];

    public const DOCUMENT_STATUS_LABELS = [
        self::DOCUMENT_STATUS_DRAFT => 'Utkast',
        self::DOCUMENT_STATUS_PENDING_REVIEW => 'Til vurdering',
        self::DOCUMENT_STATUS_ACTIVE => 'Aktiv',
        self::DOCUMENT_STATUS_EXPIRED => 'Utløpt',
        self::DOCUMENT_STATUS_ARCHIVED => 'Arkivert',
    ];

    public const EXTRACTION_STATUS_PENDING = 'pending';

    public const EXTRACTION_STATUS_COMPLETED = 'completed';

    public const EXTRACTION_STATUS_FAILED = 'failed';

    public const EXTRACTION_STATUSES = [
        self::EXTRACTION_STATUS_PENDING,
        self::EXTRACTION_STATUS_COMPLETED,
        self::EXTRACTION_STATUS_FAILED,
    ];

    public const EXTRACTION_STATUS_LABELS = [
        self::EXTRACTION_STATUS_PENDING => 'Venter',
        self::EXTRACTION_STATUS_COMPLETED => 'Tekst ekstrahert',
        self::EXTRACTION_STATUS_FAILED => 'Tekstuttrekk feilet',
    ];

    protected $fillable = [
        'customer_id',
        'document_category_id',
        'document_topic_id',
        'ownership_type',
        'document_theme_term_id',
        'title',
        'content',
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'document_type',
        'extracted_text',
        'summary',
        'extraction_status',
        'extraction_error',
        'owner_user_id',
        'owning_saved_notice_id',
        'uploaded_by_user_id',
        'ai_usage_enabled',
        'document_status',
        'last_reviewed_at',
        'review_due_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'document_category_id' => 'integer',
            'document_topic_id' => 'integer',
            'document_theme_term_id' => 'integer',
            'owner_user_id' => 'integer',
            'owning_saved_notice_id' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'ai_usage_enabled' => 'boolean',
            'last_reviewed_at' => 'date',
            'review_due_at' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Purpose: Resolve the user who owns this knowledge document.
     * Inputs: None.
     * Returns: The related owner user relation.
     * Side effects: None.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Purpose: Resolve the SavedNotice that owns this knowledge document when it is case-scoped.
     * Inputs: None.
     * Returns: The related saved notice relation.
     * Side effects: None.
     */
    public function owningSavedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class, 'owning_saved_notice_id');
    }

    public function documentCategory(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocumentCategory::class, 'document_category_id');
    }

    public function documentTopic(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocumentTopic::class, 'document_topic_id');
    }

    public function documentThemeTerm(): BelongsTo
    {
        return $this->belongsTo(KnowledgeMetadataTerm::class, 'document_theme_term_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeItemChunk::class)
            ->orderBy('chunk_index');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(KnowledgeItemRevision::class)
            ->orderBy('revision_no')
            ->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeItemVersion::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(KnowledgeItemVersion::class)
            ->where('is_current', true)
            ->latestOfMany('version_no');
    }

    /**
     * Resolve the storage path from the current version, falling back to the legacy item mirror.
     * Inputs: None.
     * Returns: The resolved storage path or null.
     * Side effects: None.
     */
    public function resolvedStoragePath(): ?string
    {
        return $this->currentVersion?->storage_path ?? $this->storage_path;
    }

    /**
     * Resolve the original filename from the current version, falling back to the legacy item mirror.
     * Inputs: None.
     * Returns: The resolved original filename or null.
     * Side effects: None.
     */
    public function resolvedOriginalFilename(): ?string
    {
        return $this->currentVersion?->original_filename ?? $this->original_filename;
    }

    /**
     * Resolve the MIME type from the current version, falling back to the legacy item mirror.
     * Inputs: None.
     * Returns: The resolved MIME type or null.
     * Side effects: None.
     */
    public function resolvedMimeType(): ?string
    {
        return $this->currentVersion?->mime_type ?? $this->mime_type;
    }

    /**
     * Resolve the file size from the current version, falling back to the legacy item mirror.
     * Inputs: None.
     * Returns: The resolved file size in bytes or null.
     * Side effects: None.
     */
    public function resolvedFileSizeBytes(): ?int
    {
        return $this->currentVersion?->file_size_bytes ?? $this->file_size_bytes;
    }

    /**
     * Resolve the extraction status from the current version, falling back to the legacy item mirror.
     * Inputs: None.
     * Returns: The resolved extraction status or null.
     * Side effects: None.
     */
    public function resolvedExtractionStatus(): ?string
    {
        return $this->currentVersion?->extraction_status ?? $this->extraction_status;
    }

    /**
     * Resolve the extraction error from the current version, falling back to the legacy item mirror only when no version exists.
     * Inputs: None.
     * Returns: The resolved extraction error or null.
     * Side effects: None.
     */
    public function resolvedExtractionError(): ?string
    {
        if ($this->currentVersion) {
            return $this->currentVersion->extraction_error;
        }

        return $this->extraction_error;
    }

    /**
     * Resolve extracted text from the current version, falling back to the legacy item mirror.
     * Inputs: None.
     * Returns: The resolved extracted text or null.
     * Side effects: None.
     */
    public function resolvedExtractedText(): ?string
    {
        $currentVersionExtractedText = trim((string) $this->currentVersion?->extracted_text);

        if ($currentVersionExtractedText !== '') {
            return $currentVersionExtractedText;
        }

        $legacyExtractedText = trim((string) $this->extracted_text);

        if ($legacyExtractedText !== '') {
            return $legacyExtractedText;
        }

        return null;
    }

    /**
     * Resolve the best available text body for knowledge processing (summarisation, vocabulary, metadata).
     * Priority: currentVersion.extracted_text → KnowledgeItem.extracted_text → KnowledgeItem.content → null.
     * Callers must eager-load currentVersion to avoid N+1 when iterating many documents.
     */
    public function textForKnowledgeProcessing(): ?string
    {
        $resolvedExtractedText = $this->resolvedExtractedText();

        if ($resolvedExtractedText !== null) {
            return $resolvedExtractedText;
        }

        $content = trim((string) $this->content);

        return $content !== '' ? $content : null;
    }

    public function isCompanyOwned(): bool
    {
        return $this->ownership_type === self::OWNERSHIP_TYPE_COMPANY;
    }

    public function isPersonalOwned(): bool
    {
        return $this->ownership_type === self::OWNERSHIP_TYPE_PERSONAL;
    }

    public function isCaseOwned(): bool
    {
        return $this->ownership_type === self::OWNERSHIP_TYPE_CASE;
    }

    public function hasDocumentTheme(): bool
    {
        return $this->document_theme_term_id !== null;
    }

    /**
     * Purpose: Resolve the persisted evidence rows grounded in this knowledge item.
     * Inputs: None.
     * Returns: The related evidence collection query.
     * Side effects: None.
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(SavedNoticeAiEvidence::class, 'knowledge_item_id')
            ->orderBy('match_rank')
            ->orderBy('id');
    }
}
