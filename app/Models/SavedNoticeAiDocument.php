<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedNoticeAiDocument extends Model
{
    public const PROCESSING_STATUS_UPLOADED = 'uploaded';

    public const PROCESSING_STATUS_TEXT_EXTRACTED = 'text_extracted';

    public const PROCESSING_STATUS_QUEUED = 'queued';

    public const PROCESSING_STATUS_PROCESSING = 'processing';

    public const PROCESSING_STATUS_MERGING = 'merging';

    public const PROCESSING_STATUS_COMPLETED = 'completed';

    public const PROCESSING_STATUS_FAILED = 'failed';

    public const PROCESSING_STATUSES = [
        self::PROCESSING_STATUS_UPLOADED,
        self::PROCESSING_STATUS_TEXT_EXTRACTED,
        self::PROCESSING_STATUS_QUEUED,
        self::PROCESSING_STATUS_PROCESSING,
        self::PROCESSING_STATUS_MERGING,
        self::PROCESSING_STATUS_COMPLETED,
        self::PROCESSING_STATUS_FAILED,
    ];

    public const PROCESSING_STATUS_LABELS = [
        self::PROCESSING_STATUS_UPLOADED => 'Lastet opp',
        self::PROCESSING_STATUS_TEXT_EXTRACTED => 'Tekst ekstrahert',
        self::PROCESSING_STATUS_QUEUED => 'I kø',
        self::PROCESSING_STATUS_PROCESSING => 'Behandles',
        self::PROCESSING_STATUS_MERGING => 'Slår sammen',
        self::PROCESSING_STATUS_COMPLETED => 'Fullført',
        self::PROCESSING_STATUS_FAILED => 'Feilet',
    ];

    protected $fillable = [
        'saved_notice_id',
        'uploaded_by_user_id',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size_bytes',
        'processing_status',
        'extracted_text',
        'text_extracted_at',
        'queued_at',
        'processing_started_at',
        'processing_finished_at',
        'processing_error_type',
        'processing_error_message',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'text_extracted_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_finished_at' => 'datetime',
        ];
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Purpose: Resolve the ordered text chunks extracted from this AI document.
     * Inputs: None.
     * Returns: The related chunk collection query.
     * Side effects: None.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(SavedNoticeAiDocumentChunk::class)
            ->orderBy('chunk_index');
    }

    /**
     * Purpose: Resolve the requirement candidates extracted from this AI document.
     * Inputs: None.
     * Returns: The related requirement collection query.
     * Side effects: None.
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(SavedNoticeAiRequirement::class, 'saved_notice_ai_document_id')
            ->orderBy('saved_notice_ai_document_chunk_id')
            ->orderBy('id');
    }

    /**
     * Purpose: Resolve the extraction runs created for this AI document.
     * Inputs: None.
     * Returns: The related extraction run collection query.
     * Side effects: None.
     */
    public function extractionRuns(): HasMany
    {
        return $this->hasMany(RequirementExtractionRun::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Purpose: Resolve the latest extraction run for this AI document.
     * Inputs: None.
     * Returns: The most recent extraction run relation.
     * Side effects: None.
     */
    public function latestExtractionRun(): HasOne
    {
        return $this->hasOne(RequirementExtractionRun::class)->latestOfMany();
    }
}
