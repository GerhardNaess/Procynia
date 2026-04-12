<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementExtractionRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_MERGING = 'merging';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_MERGING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'I kø',
        self::STATUS_PROCESSING => 'Behandles',
        self::STATUS_MERGING => 'Slår sammen',
        self::STATUS_COMPLETED => 'Fullført',
        self::STATUS_FAILED => 'Feilet',
    ];

    public const STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION = 'phase_1_requirement_extraction';

    public const STRATEGY_FULL_DOCUMENT = self::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION;

    public const STRATEGY_LEGACY_FULL_DOCUMENT = 'full_document';

    public const STRATEGY_STRUCTURED_CHUNKED = 'structured_chunked';

    public const STRATEGY_TABLE_FOCUSED = 'table_focused';

    public const STRATEGIES = [
        self::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION,
        self::STRATEGY_LEGACY_FULL_DOCUMENT,
        self::STRATEGY_STRUCTURED_CHUNKED,
        self::STRATEGY_TABLE_FOCUSED,
    ];

    public const STRATEGY_LABELS = [
        self::STRATEGY_PHASE_1_REQUIREMENT_EXTRACTION => 'Phase 1 krav-ekstraksjon',
        self::STRATEGY_LEGACY_FULL_DOCUMENT => 'Full-dokument (legacy)',
        self::STRATEGY_STRUCTURED_CHUNKED => 'Strukturert chunking',
        self::STRATEGY_TABLE_FOCUSED => 'Tabellfokus',
    ];

    protected $fillable = [
        'uuid',
        'saved_notice_id',
        'saved_notice_ai_document_id',
        'status',
        'strategy',
        'prompt_version',
        'model',
        'failure_stage',
        'error_type',
        'error_message',
        'candidate_count',
        'persisted_requirement_count',
        'openai_call_count',
        'input_tokens_total',
        'output_tokens_total',
        'total_tokens_total',
        'queued_at',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'saved_notice_id' => 'integer',
            'saved_notice_ai_document_id' => 'integer',
            'candidate_count' => 'integer',
            'persisted_requirement_count' => 'integer',
            'openai_call_count' => 'integer',
            'input_tokens_total' => 'integer',
            'output_tokens_total' => 'integer',
            'total_tokens_total' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
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

    public function calls(): HasMany
    {
        return $this->hasMany(RequirementExtractionCall::class, 'requirement_extraction_run_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ], true);
    }
}
