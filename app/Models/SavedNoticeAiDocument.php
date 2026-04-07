<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedNoticeAiDocument extends Model
{
    public const PROCESSING_STATUS_UPLOADED = 'uploaded';

    public const PROCESSING_STATUSES = [
        self::PROCESSING_STATUS_UPLOADED,
    ];

    public const PROCESSING_STATUS_LABELS = [
        self::PROCESSING_STATUS_UPLOADED => 'Lastet opp',
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
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'text_extracted_at' => 'datetime',
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
}
