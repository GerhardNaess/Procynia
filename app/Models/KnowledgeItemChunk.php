<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeItemChunk extends Model
{
    protected $fillable = [
        'knowledge_item_id',
        'chunk_index',
        'content',
        'start_offset',
        'end_offset',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'start_offset' => 'integer',
            'end_offset' => 'integer',
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
}
