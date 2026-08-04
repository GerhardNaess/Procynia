<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiMaintainerDecisionBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['enterprise_wiki_ingest_run_id', 'batch_number', 'total_batches', 'input_payload', 'status', 'lease_token', 'leased_at', 'result_payload', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['input_payload' => 'array', 'result_payload' => 'array', 'leased_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiIngestRun::class, 'enterprise_wiki_ingest_run_id');
    }
}
