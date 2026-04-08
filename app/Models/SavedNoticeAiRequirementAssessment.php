<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeAiRequirementAssessment extends Model
{
    public const ASSESSMENT_STATUS_COMPLETED = 'completed';

    public const ASSESSMENT_STATUS_FAILED = 'failed';

    public const ASSESSMENT_STATUSES = [
        self::ASSESSMENT_STATUS_COMPLETED,
        self::ASSESSMENT_STATUS_FAILED,
    ];

    public const ASSESSMENT_STATUS_LABELS = [
        self::ASSESSMENT_STATUS_COMPLETED => 'Fullført',
        self::ASSESSMENT_STATUS_FAILED => 'Feilet',
    ];

    public const COVERAGE_STATUS_COVERED = 'covered';

    public const COVERAGE_STATUS_PARTIAL = 'partial';

    public const COVERAGE_STATUS_MISSING = 'missing';

    public const COVERAGE_STATUSES = [
        self::COVERAGE_STATUS_COVERED,
        self::COVERAGE_STATUS_PARTIAL,
        self::COVERAGE_STATUS_MISSING,
    ];

    public const COVERAGE_STATUS_LABELS = [
        self::COVERAGE_STATUS_COVERED => 'Dekket',
        self::COVERAGE_STATUS_PARTIAL => 'Delvis dekket',
        self::COVERAGE_STATUS_MISSING => 'Mangler grunnlag',
    ];

    public const RISK_LEVEL_LOW = 'low';

    public const RISK_LEVEL_MEDIUM = 'medium';

    public const RISK_LEVEL_HIGH = 'high';

    public const RISK_LEVELS = [
        self::RISK_LEVEL_LOW,
        self::RISK_LEVEL_MEDIUM,
        self::RISK_LEVEL_HIGH,
    ];

    public const RISK_LEVEL_LABELS = [
        self::RISK_LEVEL_LOW => 'Lav risiko',
        self::RISK_LEVEL_MEDIUM => 'Middels risiko',
        self::RISK_LEVEL_HIGH => 'Høy risiko',
    ];

    protected $fillable = [
        'saved_notice_ai_requirement_id',
        'assessment_status',
        'coverage_status',
        'risk_level',
        'requirement_summary',
        'coverage_rationale',
        'missing_information',
        'recommended_next_step',
        'source_evidence_snapshot',
        'assessed_at',
        'assessed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'source_evidence_snapshot' => 'array',
            'assessed_at' => 'datetime',
            'assessed_by_user_id' => 'integer',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiRequirement::class, 'saved_notice_ai_requirement_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }
}
