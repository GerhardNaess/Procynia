<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purpose: Store per-call AI token usage for internal cost tracking.
 * Inputs: Customer, optional user, operation key, model, token counts, and optional resource references.
 * Returns: Eloquent rows that can be aggregated for monthly cost reports per customer.
 * Side effects: Persists token usage metadata only — never prompt text or generated content.
 */
class AiTokenEvent extends Model
{
    protected $fillable = [
        'customer_id',
        'user_id',
        'operation_key',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'saved_notice_id',
        'saved_notice_ai_document_id',
        'requirement_extraction_run_id',
        'knowledge_item_id',
        'elapsed_ms',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'user_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'saved_notice_id' => 'integer',
            'saved_notice_ai_document_id' => 'integer',
            'requirement_extraction_run_id' => 'integer',
            'knowledge_item_id' => 'integer',
            'elapsed_ms' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }
}
