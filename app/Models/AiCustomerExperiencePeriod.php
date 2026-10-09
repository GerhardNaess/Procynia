<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer's AI experience in one billing period: the customer's shape in the period and its
 * aggregated, trusted AI usage. Written only by AiExperienceSnapshotService; read only by
 * AiExperienceAnalysisService. Derived from the ledger — never a source of cost of its own.
 */
class AiCustomerExperiencePeriod extends Model
{
    /** Bump when what a snapshot row promises changes; older rows are then rebuilt on refresh. */
    public const SNAPSHOT_VERSION = 1;

    public const STATUS_OPEN = 'open';

    public const STATUS_FINAL = 'final';

    public const COVERAGE_FULL = 'full';

    public const COVERAGE_PARTIAL = 'partial';

    /** The usage figures a revision of a final period compares. */
    public const USAGE_FIELDS = [
        'calls', 'successful_calls', 'failed_calls', 'settled_calls', 'pending_calls', 'unresolved_calls',
        'legacy_calls', 'total_tokens', 'settled_cost_nok', 'pending_reserved_cost_nok',
        'unresolved_reserved_cost_nok', 'settled_units', 'reserved_units', 'used_percent',
        'committed_percent', 'verdict_allow', 'verdict_warn', 'verdict_exhausted',
        'verdict_insufficient', 'verdict_unmetered', 'would_have_blocked', 'coverage',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'period_months' => 'integer',
            'finalized_at' => 'immutable_datetime',
            'context_captured_at' => 'immutable_datetime',
            'active_users_count' => 'integer',
            'active_packages' => 'array',
            'tier_multiplier' => 'float',
            'base_units_per_month' => 'integer',
            'calculated_base_capacity' => 'integer',
            'calculated_total_capacity' => 'integer',
            'override_units' => 'integer',
            'included_units' => 'integer',
            'nok_per_unit' => 'float',
            'calls' => 'integer',
            'successful_calls' => 'integer',
            'failed_calls' => 'integer',
            'settled_calls' => 'integer',
            'pending_calls' => 'integer',
            'unresolved_calls' => 'integer',
            'legacy_calls' => 'integer',
            'total_tokens' => 'integer',
            'settled_cost_nok' => 'float',
            'pending_reserved_cost_nok' => 'float',
            'unresolved_reserved_cost_nok' => 'float',
            'settled_units' => 'float',
            'reserved_units' => 'float',
            'used_percent' => 'float',
            'committed_percent' => 'float',
            'verdict_allow' => 'integer',
            'verdict_warn' => 'integer',
            'verdict_exhausted' => 'integer',
            'verdict_insufficient' => 'integer',
            'verdict_unmetered' => 'integer',
            'would_have_blocked' => 'integer',
            'snapshot_version' => 'integer',
            'ledger_version' => 'integer',
            'revision' => 'integer',
            'generated_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(AiCustomerExperiencePeriodFeature::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AiCustomerExperiencePeriodRevision::class);
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    /** Final and fully covered by trusted data: the rows a decision may rest on. */
    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FINAL)->where('coverage', self::COVERAGE_FULL);
    }
}
