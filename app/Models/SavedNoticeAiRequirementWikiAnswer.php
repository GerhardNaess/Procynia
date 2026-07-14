<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Wiki-based answer for one SavedNoticeAiRequirement, generated exclusively from approved,
 * customer-scoped Enterprise Wiki content (see RequirementWikiAnswerService). Stored in its own
 * table, entirely separate from the requirement's existing answer_draft_* columns — generating or
 * regenerating a Wiki answer never reads from or writes to the existing answer-draft flow.
 */
class SavedNoticeAiRequirementWikiAnswer extends Model
{
    public const COVERAGE_FULL = 'full';

    public const COVERAGE_PARTIAL = 'partial';

    public const COVERAGE_NONE = 'none';

    public const COVERAGE_STATUSES = [
        self::COVERAGE_FULL,
        self::COVERAGE_PARTIAL,
        self::COVERAGE_NONE,
    ];

    public const COVERAGE_STATUS_LABELS = [
        self::COVERAGE_FULL => 'Full dekning',
        self::COVERAGE_PARTIAL => 'Delvis dekning',
        self::COVERAGE_NONE => 'Ingen dekning',
    ];

    protected $fillable = [
        'saved_notice_ai_requirement_id',
        'coverage_status',
        'answer_text',
        'missing_summary',
        'sources',
        'research_trace',
        'engine_version',
        'model',
        'generated_by_user_id',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'research_trace' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiRequirement::class, 'saved_notice_ai_requirement_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
