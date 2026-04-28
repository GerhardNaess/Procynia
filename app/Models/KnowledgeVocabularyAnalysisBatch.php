<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeVocabularyAnalysisBatch extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PARSING = 'parsing';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_PARSING,
        self::STATUS_ANALYZING,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_UPLOADED => 'Lastet opp',
        self::STATUS_PARSING => 'Parser',
        self::STATUS_ANALYZING => 'Analyserer',
        self::STATUS_PENDING_REVIEW => 'Venter på review',
        self::STATUS_COMPLETED => 'Fullført',
        self::STATUS_FAILED => 'Feilet',
    ];

    protected $fillable = [
        'customer_id',
        'status',
        'source_document_ids',
        'summary',
        'error_message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'source_document_ids' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(KnowledgeMetadataTermSuggestion::class, 'batch_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ], true);
    }
}
