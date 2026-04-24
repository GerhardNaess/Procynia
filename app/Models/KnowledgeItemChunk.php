<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItemChunk extends Model
{
    public const REVIEW_STATUS_PENDING_REVIEW = 'pending_review';

    public const REVIEW_STATUS_APPROVED = 'approved';

    public const REVIEW_STATUS_REJECTED = 'rejected';

    public const REVIEW_STATUSES = [
        self::REVIEW_STATUS_PENDING_REVIEW,
        self::REVIEW_STATUS_APPROVED,
        self::REVIEW_STATUS_REJECTED,
    ];

    public const REVIEW_STATUS_LABELS = [
        self::REVIEW_STATUS_PENDING_REVIEW => 'Trenger review',
        self::REVIEW_STATUS_APPROVED => 'Godkjent',
        self::REVIEW_STATUS_REJECTED => 'Avvist',
    ];

    protected $fillable = [
        'knowledge_item_id',
        'chunk_index',
        'content',
        'start_offset',
        'end_offset',
        'review_status',
        'title',
        'ai_summary',
        'service_product_tag',
        'theme_tag',
        'embedding_vector',
        'embedding_model',
        'embedding_generated_at',
        'embedding_error',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'start_offset' => 'integer',
            'end_offset' => 'integer',
            'embedding_vector' => 'array',
            'embedding_generated_at' => 'datetime',
        ];
    }

    /**
     * Purpose: Resolve the parent knowledge item for this chunk.
     * Inputs: None.
     * Returns: The owning KnowledgeItem relation.
     * Side effects: None.
     */
    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }

    /**
     * Purpose: Resolve the persisted evidence rows grounded in this knowledge chunk.
     * Inputs: None.
     * Returns: The related evidence collection query.
     * Side effects: None.
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(SavedNoticeAiEvidence::class, 'knowledge_item_chunk_id')
            ->orderBy('match_rank')
            ->orderBy('id');
    }
}
