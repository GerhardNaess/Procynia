<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeMetadataTermSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_MERGED = 'merged';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_MERGED,
    ];

    protected $fillable = [
        'customer_id',
        'source_chunk_id',
        'batch_id',
        'suggested_term',
        'suggested_canonical_name',
        'suggested_type',
        'suggested_synonyms',
        'suggested_description',
        'suggested_canonical_parent',
        'related_existing_term_id',
        'reason',
        'confidence_score',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'source_chunk_id' => 'integer',
            'batch_id' => 'integer',
            'related_existing_term_id' => 'integer',
            'suggested_synonyms' => 'array',
            'confidence_score' => 'float',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceChunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItemChunk::class, 'source_chunk_id');
    }

    public function analysisBatch(): BelongsTo
    {
        return $this->belongsTo(KnowledgeVocabularyAnalysisBatch::class, 'batch_id');
    }

    public function relatedExistingTerm(): BelongsTo
    {
        return $this->belongsTo(KnowledgeMetadataTerm::class, 'related_existing_term_id');
    }
}
