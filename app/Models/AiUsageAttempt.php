<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable operational record for one outbound AI provider attempt.  This is deliberately
 * separate from AiTokenEvent: an attempt can time out, fail before usage is available, or return
 * an invalid envelope while still being relevant to cost and incident investigation.
 */
class AiUsageAttempt extends Model
{
    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'customer_id', 'user_id', 'feature', 'operation_key', 'resource_type', 'resource_id',
        'enterprise_wiki_ingest_run_id', 'job_id', 'request_correlation_id', 'provider',
        'deployment_name', 'provider_region', 'endpoint', 'model', 'status', 'failure_type',
        'provider_request_id', 'input_tokens', 'output_tokens', 'total_tokens', 'elapsed_ms',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer', 'user_id' => 'integer', 'resource_id' => 'integer',
            'enterprise_wiki_ingest_run_id' => 'integer', 'input_tokens' => 'integer',
            'output_tokens' => 'integer', 'total_tokens' => 'integer', 'elapsed_ms' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }
}
