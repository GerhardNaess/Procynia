<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeAiEvidence extends Model
{
    public const MATCH_TYPE_AUTO_MATCH = 'auto_match';

    public const MATCH_TYPE_MANUAL_ADD = 'manual_add';

    public const MATCH_TYPES = [
        self::MATCH_TYPE_AUTO_MATCH,
        self::MATCH_TYPE_MANUAL_ADD,
    ];

    public const MATCH_TYPE_LABELS = [
        self::MATCH_TYPE_AUTO_MATCH => 'Automatisk match',
        self::MATCH_TYPE_MANUAL_ADD => 'Manuelt lagt til',
    ];

    public const SELECTION_STATUS_SUGGESTED = 'suggested';

    public const SELECTION_STATUS_SELECTED = 'selected';

    public const SELECTION_STATUS_REJECTED = 'rejected';

    public const SELECTION_STATUSES = [
        self::SELECTION_STATUS_SUGGESTED,
        self::SELECTION_STATUS_SELECTED,
        self::SELECTION_STATUS_REJECTED,
    ];

    public const SELECTION_STATUS_LABELS = [
        self::SELECTION_STATUS_SUGGESTED => 'Forslag',
        self::SELECTION_STATUS_SELECTED => 'Valgt',
        self::SELECTION_STATUS_REJECTED => 'Avvist',
    ];

    protected $table = 'saved_notice_ai_evidence';

    protected $fillable = [
        'saved_notice_ai_requirement_id',
        'knowledge_item_id',
        'knowledge_item_chunk_id',
        'knowledge_item_version_id',
        'match_type',
        'match_score',
        'match_rank',
        'selection_status',
        'is_primary',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'match_score' => 'integer',
            'match_rank' => 'integer',
            'is_primary' => 'boolean',
            'created_by_user_id' => 'integer',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiRequirement::class, 'saved_notice_ai_requirement_id');
    }

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }

    public function knowledgeItemChunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItemChunk::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
