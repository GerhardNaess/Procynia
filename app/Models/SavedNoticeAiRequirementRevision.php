<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeAiRequirementRevision extends Model
{
    public const CHANGE_TYPE_CREATE_AI_CANDIDATE = 'create_ai_candidate';

    public const CHANGE_TYPE_MANUAL_CREATE = 'manual_create';

    public const CHANGE_TYPE_EDIT_IDENTIFIER = 'edit_identifier';

    public const CHANGE_TYPE_EDIT_TEXT = 'edit_text';

    public const CHANGE_TYPE_EDIT_METADATA = 'edit_metadata';

    public const CHANGE_TYPE_APPROVE = 'approve';

    public const CHANGE_TYPE_REJECT = 'reject';

    public const CHANGE_TYPE_RESTORE = 'restore';

    public const CHANGE_TYPE_DELETE = 'delete';

    public const CHANGE_TYPES = [
        self::CHANGE_TYPE_CREATE_AI_CANDIDATE,
        self::CHANGE_TYPE_MANUAL_CREATE,
        self::CHANGE_TYPE_EDIT_IDENTIFIER,
        self::CHANGE_TYPE_EDIT_TEXT,
        self::CHANGE_TYPE_EDIT_METADATA,
        self::CHANGE_TYPE_APPROVE,
        self::CHANGE_TYPE_REJECT,
        self::CHANGE_TYPE_RESTORE,
        self::CHANGE_TYPE_DELETE,
    ];

    public const CHANGE_TYPE_LABELS = [
        self::CHANGE_TYPE_CREATE_AI_CANDIDATE => 'AI-kandidat opprettet',
        self::CHANGE_TYPE_MANUAL_CREATE => 'Manuelt krav opprettet',
        self::CHANGE_TYPE_EDIT_IDENTIFIER => 'Krav-ID endret',
        self::CHANGE_TYPE_EDIT_TEXT => 'Kravtekst endret',
        self::CHANGE_TYPE_EDIT_METADATA => 'Metadata endret',
        self::CHANGE_TYPE_APPROVE => 'Godkjent',
        self::CHANGE_TYPE_REJECT => 'Avvist',
        self::CHANGE_TYPE_RESTORE => 'Gjenopprettet',
        self::CHANGE_TYPE_DELETE => 'Slettet',
    ];

    protected $fillable = [
        'saved_notice_ai_requirement_id',
        'saved_notice_id',
        'changed_by_user_id',
        'change_type',
        'before_snapshot',
        'after_snapshot',
        'changed_fields',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'changed_by_user_id' => 'integer',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'changed_fields' => 'array',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiRequirement::class, 'saved_notice_ai_requirement_id');
    }

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
