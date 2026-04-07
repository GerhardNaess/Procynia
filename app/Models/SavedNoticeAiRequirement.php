<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeAiRequirement extends Model
{
    public const REQUIREMENT_TYPE_MANDATORY = 'mandatory';

    public const REQUIREMENT_TYPE_DOCUMENTATION = 'documentation';

    public const REQUIREMENT_TYPE_ADMINISTRATIVE = 'administrative';

    public const REQUIREMENT_TYPE_UNSPECIFIED = 'unspecified';

    public const REQUIREMENT_TYPES = [
        self::REQUIREMENT_TYPE_MANDATORY,
        self::REQUIREMENT_TYPE_DOCUMENTATION,
        self::REQUIREMENT_TYPE_ADMINISTRATIVE,
        self::REQUIREMENT_TYPE_UNSPECIFIED,
    ];

    public const REQUIREMENT_TYPE_LABELS = [
        self::REQUIREMENT_TYPE_MANDATORY => 'Obligatorisk',
        self::REQUIREMENT_TYPE_DOCUMENTATION => 'Dokumentasjon',
        self::REQUIREMENT_TYPE_ADMINISTRATIVE => 'Administrativ',
        self::REQUIREMENT_TYPE_UNSPECIFIED => 'Uspesifisert',
    ];

    public const EXTRACTION_METHOD_RULE_BASED = 'rule_based';

    public const EXTRACTION_METHOD_LABELS = [
        self::EXTRACTION_METHOD_RULE_BASED => 'Regelbasert',
    ];

    public const REVIEW_STATUS_PENDING = 'pending';

    public const REVIEW_STATUS_CONFIRMED = 'confirmed';

    public const REVIEW_STATUS_REJECTED = 'rejected';

    public const REVIEW_STATUSES = [
        self::REVIEW_STATUS_PENDING,
        self::REVIEW_STATUS_CONFIRMED,
        self::REVIEW_STATUS_REJECTED,
    ];

    public const REVIEW_STATUS_LABELS = [
        self::REVIEW_STATUS_PENDING => 'Til vurdering',
        self::REVIEW_STATUS_CONFIRMED => 'Bekreftet',
        self::REVIEW_STATUS_REJECTED => 'Avvist',
    ];

    public const WORK_STATUS_NOT_STARTED = 'not_started';

    public const WORK_STATUS_IN_PROGRESS = 'in_progress';

    public const WORK_STATUS_DONE = 'done';

    public const WORK_STATUSES = [
        self::WORK_STATUS_NOT_STARTED,
        self::WORK_STATUS_IN_PROGRESS,
        self::WORK_STATUS_DONE,
    ];

    public const WORK_STATUS_LABELS = [
        self::WORK_STATUS_NOT_STARTED => 'Ikke startet',
        self::WORK_STATUS_IN_PROGRESS => 'Under arbeid',
        self::WORK_STATUS_DONE => 'Ferdig',
    ];

    protected $fillable = [
        'saved_notice_id',
        'saved_notice_ai_document_id',
        'saved_notice_ai_document_chunk_id',
        'requirement_text',
        'requirement_type',
        'extraction_method',
        'review_status',
        'work_status',
        'assigned_user_id',
    ];

    protected function casts(): array
    {
        return [
            'assigned_user_id' => 'integer',
        ];
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiDocument::class, 'saved_notice_ai_document_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiDocumentChunk::class, 'saved_notice_ai_document_chunk_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
