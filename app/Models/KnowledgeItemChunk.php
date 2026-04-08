<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItemChunk extends Model
{
    protected $fillable = [
        'knowledge_item_id',
        'chunk_index',
        'content',
        'start_offset',
        'end_offset',
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
