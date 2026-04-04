<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedNoticeInfoItem extends Model
{
    use HasFactory;

    public const TYPE_MESSAGE = 'message';

    public const TYPE_CLARIFICATION = 'clarification';

    public const TYPE_DECISION = 'decision';

    public const TYPE_NOTE = 'note';

    public const TYPES = [
        self::TYPE_MESSAGE,
        self::TYPE_CLARIFICATION,
        self::TYPE_DECISION,
        self::TYPE_NOTE,
    ];

    public const TYPE_LABELS = [
        self::TYPE_MESSAGE => 'Melding',
        self::TYPE_CLARIFICATION => 'Avklaring',
        self::TYPE_DECISION => 'Beslutning',
        self::TYPE_NOTE => 'Notat',
    ];

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INTERNAL = 'internal';

    public const DIRECTIONS = [
        self::DIRECTION_INBOUND,
        self::DIRECTION_OUTBOUND,
        self::DIRECTION_INTERNAL,
    ];

    public const DIRECTION_LABELS = [
        self::DIRECTION_INBOUND => 'Innkommende',
        self::DIRECTION_OUTBOUND => 'Utgående',
        self::DIRECTION_INTERNAL => 'Intern',
    ];

    public const CHANNEL_PROCYNIA = 'procynia';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_MANUAL = 'manual';

    public const CHANNELS = [
        self::CHANNEL_PROCYNIA,
        self::CHANNEL_EMAIL,
        self::CHANNEL_MANUAL,
    ];

    public const CHANNEL_LABELS = [
        self::CHANNEL_PROCYNIA => 'Procynia',
        self::CHANNEL_EMAIL => 'E-post',
        self::CHANNEL_MANUAL => 'Manuell',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_WAITING,
        self::STATUS_CLOSED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Åpen',
        self::STATUS_WAITING => 'Venter',
        self::STATUS_CLOSED => 'Lukket',
    ];

    protected $fillable = [
        'saved_notice_id',
        'type',
        'direction',
        'channel',
        'subject',
        'body',
        'status',
        'requires_response',
        'response_due_at',
        'owner_user_id',
        'created_by_user_id',
        'closed_at',
        'closure_comment',
    ];

    protected $casts = [
        'requires_response' => 'boolean',
        'response_due_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function savedNotice(): BelongsTo
    {
        return $this->belongsTo(SavedNotice::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? (string) $this->type;
    }

    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTION_LABELS[$this->direction] ?? (string) $this->direction;
    }

    public function getChannelLabelAttribute(): string
    {
        return self::CHANNEL_LABELS[$this->channel] ?? (string) $this->channel;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public static function typeOptions(): array
    {
        return self::TYPE_LABELS;
    }

    public static function directionOptions(): array
    {
        return self::DIRECTION_LABELS;
    }

    public static function channelOptions(): array
    {
        return self::CHANNEL_LABELS;
    }

    public static function statusOptions(): array
    {
        return self::STATUS_LABELS;
    }
}
