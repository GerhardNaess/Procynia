<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One stored generation or hand-edit of a requirement's Wiki answer, as it was (P5). Append-only:
 * written by RequirementWikiAnswerService in the same transaction as the answer row, never changed;
 * the database refuses the same (saved_notice_ai_requirement_wiki_answer_revisions_immutable).
 */
class SavedNoticeAiRequirementWikiAnswerRevision extends Model
{
    public const EVENT_GENERATED = 'generated';

    public const EVENT_EDITED = 'edited';

    public const UPDATED_AT = null;

    protected $fillable = [
        'saved_notice_ai_requirement_id',
        'saved_notice_id',
        'event',
        'answer_text',
        'coverage_status',
        'sources',
        'actor_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('A Wiki answer revision is history and is never changed.'));
        static::deleting(static fn (): never => throw new LogicException('A Wiki answer revision is history and is never deleted on its own.'));
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiRequirement::class, 'saved_notice_ai_requirement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
