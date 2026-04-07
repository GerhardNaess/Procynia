<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedNoticeAiDocumentChunk extends Model
{
    protected $fillable = [
        'saved_notice_ai_document_id',
        'chunk_index',
        'content',
        'char_start',
        'char_end',
        'word_count',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'word_count' => 'integer',
        ];
    }

    /**
     * Purpose: Resolve the parent AI document for this chunk.
     * Inputs: None.
     * Returns: The owning SavedNotice AI document relation.
     * Side effects: None.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiDocument::class, 'saved_notice_ai_document_id');
    }

    /**
     * Purpose: Resolve the requirement candidates extracted from this chunk.
     * Inputs: None.
     * Returns: The related requirement collection query.
     * Side effects: None.
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(SavedNoticeAiRequirement::class, 'saved_notice_ai_document_chunk_id')
            ->orderBy('id');
    }
}
