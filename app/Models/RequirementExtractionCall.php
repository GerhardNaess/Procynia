<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequirementExtractionCall extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'I kø',
        self::STATUS_RUNNING => 'Kjører',
        self::STATUS_COMPLETED => 'Fullført',
        self::STATUS_FAILED => 'Feilet',
    ];

    protected $fillable = [
        'requirement_extraction_run_id',
        'saved_notice_id',
        'saved_notice_ai_document_id',
        'saved_notice_ai_document_chunk_id',
        'status',
        'strategy',
        'prompt_version',
        'model',
        'request_id',
        'response_id',
        'status_code',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'elapsed_ms',
        'error_type',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'requirement_extraction_run_id' => 'integer',
            'saved_notice_id' => 'integer',
            'saved_notice_ai_document_id' => 'integer',
            'saved_notice_ai_document_chunk_id' => 'integer',
            'status_code' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'elapsed_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RequirementExtractionRun::class, 'requirement_extraction_run_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SavedNoticeAiDocument::class, 'saved_notice_ai_document_id');
    }
}
