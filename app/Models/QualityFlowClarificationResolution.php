<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One optional clarification the user has settled, for one process — either way they settled it.
 *
 * The row is a memory, not a rule: QualityFlowClarificationService decides whether it still applies
 * by comparing `description` with the description being interpreted now. See the migration for why
 * it is kept that way round, and why both outcomes live in one table.
 */
class QualityFlowClarificationResolution extends Model
{
    /** "Avvis": the term is deliberately left to judgement, so stop suggesting it. */
    public const OUTCOME_DISMISSED = 'dismissed';

    /**
     * "Avklar": the user said what the term means and the answer was woven into the description.
     *
     * Recorded against the revised text, not the one they started from — that is the description
     * the answer is part of, and the one every later reading is compared with.
     */
    public const OUTCOME_ANSWERED = 'answered';

    /** @var list<string> */
    public const OUTCOMES = [
        self::OUTCOME_DISMISSED,
        self::OUTCOME_ANSWERED,
    ];

    protected $fillable = [
        'customer_id',
        'quality_item_id',
        'question',
        'question_key',
        'outcome',
        'description',
        'resolved_by_user_id',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QualityItem::class, 'quality_item_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
