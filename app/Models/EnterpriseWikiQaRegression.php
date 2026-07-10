<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiQaRegression extends Model
{
    public const ANALYSIS_STATUS_BASELINE = 'baseline';

    public const ANALYSIS_STATUS_WITHIN_TOLERANCE = 'within_tolerance';

    public const ANALYSIS_STATUS_REGRESSION = 'regression';

    public const ANALYSIS_STATUS_REPAIRING = 'repairing';

    public const ANALYSIS_STATUS_COMPLETED = 'completed';

    public const ANALYSIS_STATUS_FAILED = 'failed';

    public const ANALYSIS_STATUSES = [
        self::ANALYSIS_STATUS_BASELINE,
        self::ANALYSIS_STATUS_WITHIN_TOLERANCE,
        self::ANALYSIS_STATUS_REGRESSION,
        self::ANALYSIS_STATUS_REPAIRING,
        self::ANALYSIS_STATUS_COMPLETED,
        self::ANALYSIS_STATUS_FAILED,
    ];

    public const CLASSIFICATION_BASELINE = 'baseline';

    public const CLASSIFICATION_WITHIN_TOLERANCE = 'within_tolerance';

    public const CLASSIFICATION_SEMANTIC = 'semantic_regression';

    public const CLASSIFICATION_STRUCTURAL = 'structural_regression';

    public const CLASSIFICATION_MIXED = 'mixed_regression';

    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_BASELINE,
        self::CLASSIFICATION_WITHIN_TOLERANCE,
        self::CLASSIFICATION_SEMANTIC,
        self::CLASSIFICATION_STRUCTURAL,
        self::CLASSIFICATION_MIXED,
    ];

    public const MAINTENANCE_ACTION_NONE = 'none';

    public const MAINTENANCE_ACTION_SEMANTIC_REPAIR = 'semantic_repair';

    public const MAINTENANCE_ACTION_DEEP_REPAIR = 'deep_repair';

    public const MAINTENANCE_ACTION_ESCALATE = 'escalate';

    public const MAINTENANCE_ACTIONS = [
        self::MAINTENANCE_ACTION_NONE,
        self::MAINTENANCE_ACTION_SEMANTIC_REPAIR,
        self::MAINTENANCE_ACTION_DEEP_REPAIR,
        self::MAINTENANCE_ACTION_ESCALATE,
    ];

    protected $fillable = [
        'enterprise_wiki_qa_snapshot_id',
        'baseline_enterprise_wiki_qa_snapshot_id',
        'enterprise_wiki_ingest_run_id',
        'customer_id',
        'source_type',
        'source_id',
        'source_hash',
        'page_type_signature',
        'comparison_context',
        'current_metrics',
        'baseline_metrics',
        'metric_deltas',
        'thresholds',
        'regression_signals',
        'regression_classification',
        'maintenance_action',
        'analysis_status',
        'analysis_started_at',
        'analysis_completed_at',
        'repair_attempted_at',
        'repair_result',
        'final_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'enterprise_wiki_qa_snapshot_id' => 'integer',
            'baseline_enterprise_wiki_qa_snapshot_id' => 'integer',
            'enterprise_wiki_ingest_run_id' => 'integer',
            'customer_id' => 'integer',
            'source_id' => 'integer',
            'comparison_context' => 'array',
            'current_metrics' => 'array',
            'baseline_metrics' => 'array',
            'metric_deltas' => 'array',
            'thresholds' => 'array',
            'regression_signals' => 'array',
            'analysis_started_at' => 'datetime',
            'analysis_completed_at' => 'datetime',
            'repair_attempted_at' => 'datetime',
            'repair_result' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiQaSnapshot::class, 'enterprise_wiki_qa_snapshot_id');
    }

    public function baselineSnapshot(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiQaSnapshot::class, 'baseline_enterprise_wiki_qa_snapshot_id');
    }

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiIngestRun::class, 'enterprise_wiki_ingest_run_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
