<?php

namespace App\Models;

use App\Data\Ai\AiCallContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable operational record for one outbound AI provider attempt.  This is deliberately
 * separate from AiTokenEvent: an attempt can time out, fail before usage is available, or return
 * an invalid envelope while still being relevant to cost and incident investigation.
 *
 * The authoritative source for AI usage and cost from LEDGER_VERSION 1 on. Rows without a
 * ledger_version are legacy (see the 2026_10_08_000022 migration): readable, never trusted.
 */
class AiUsageAttempt extends Model
{
    /**
     * Stamped on every attempt the meter writes. Bump it when what a row promises changes, and
     * decide explicitly whether the trusted boundary moves with it.
     */
    public const LEDGER_VERSION = 1;

    /** The first ledger version whose rows may be used as an economic basis. */
    public const TRUSTED_SINCE_LEDGER_VERSION = 1;

    public const STATUS_STARTED = 'started';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'customer_id', 'user_id', 'attribution', 'ledger_version', 'feature', 'operation_key', 'resource_type', 'resource_id',
        'enterprise_wiki_ingest_run_id', 'job_id', 'request_correlation_id', 'provider',
        'deployment_name', 'provider_region', 'endpoint', 'model', 'status', 'failure_type',
        'provider_request_id', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens', 'elapsed_ms',
        'started_at', 'finished_at',
        'cost_status', 'cost_usd', 'cost_nok', 'reserved_cost_nok', 'ai_model_price_id',
        'price_currency', 'price_input_per_1m', 'price_cached_input_per_1m', 'price_output_per_1m', 'fx_rate', 'fx_rate_date',
        'price_state', 'fx_state',
    ];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer', 'user_id' => 'integer', 'resource_id' => 'integer', 'ledger_version' => 'integer',
            'enterprise_wiki_ingest_run_id' => 'integer', 'input_tokens' => 'integer',
            'output_tokens' => 'integer', 'total_tokens' => 'integer',
            'cached_input_tokens' => 'integer', 'reasoning_tokens' => 'integer',
            'price_cached_input_per_1m' => 'decimal:6', 'elapsed_ms' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
            'cost_usd' => 'decimal:6', 'cost_nok' => 'decimal:4', 'reserved_cost_nok' => 'decimal:4',
            'ai_model_price_id' => 'integer', 'price_input_per_1m' => 'decimal:6',
            'price_output_per_1m' => 'decimal:6', 'fx_rate' => 'decimal:8', 'fx_rate_date' => 'date',
        ];
    }

    /**
     * Rows that may be used as an economic basis: written under a trusted ledger version, and
     * owned — by a customer or by explicit system work. Legacy and unattributed rows are excluded.
     * Cost on a trusted row can still be unknown or uncertain; that is read off cost_status.
     */
    public function scopeTrusted(Builder $query): Builder
    {
        return $query
            ->where('ledger_version', '>=', self::TRUSTED_SINCE_LEDGER_VERSION)
            ->whereIn('attribution', [AiCallContext::ATTRIBUTION_CUSTOMER, AiCallContext::ATTRIBUTION_SYSTEM]);
    }

    /** Rows from before the usage-integrity boundary. */
    public function scopeLegacy(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('ledger_version')
            ->orWhere('ledger_version', '<', self::TRUSTED_SINCE_LEDGER_VERSION));
    }

    /** Post-boundary rows that reached the provider with no owner. Should be zero. */
    public function scopeUnattributed(Builder $query): Builder
    {
        return $query
            ->where('ledger_version', '>=', self::TRUSTED_SINCE_LEDGER_VERSION)
            ->where('attribution', AiCallContext::ATTRIBUTION_UNATTRIBUTED);
    }
}
