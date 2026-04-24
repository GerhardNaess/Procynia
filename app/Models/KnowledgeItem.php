<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItem extends Model
{
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
        'title',
        'content',
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'content_type',
        'document_type',
        'extracted_text',
        'summary',
        'extraction_status',
        'extraction_error',
        'uploaded_by_user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'is_active' => 'boolean',
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

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeItemChunk::class)
            ->orderBy('chunk_index');
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
