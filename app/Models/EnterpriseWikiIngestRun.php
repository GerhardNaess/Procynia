<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseWikiIngestRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SECTIONS_PLANNED = 'sections_planned';

    public const STATUS_MAINTAINER_DECISION = 'maintainer_decision';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_GENERATING_PAGES = 'generating_pages';

    // Phase 2 of applied page generation: article/summary pages have all finished
    // successfully and concept/entity page jobs (which read their finished content as
    // context) have been dispatched.
    public const STATUS_GENERATING_CONCEPT_ENTITY_PAGES = 'generating_concept_entity_pages';

    public const STATUS_VERIFICATION_LINKING = 'verification_linking';

    public const STATUS_QA = 'qa';

    public const STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL = 'awaiting_document_owner_approval';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ESCALATED = 'escalated';

    // Used when the run stores only a maintainer decision — no pages are created.
    public const STATUS_DECISION_ONLY = 'decision_only';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_MAINTAINER_DECISION,
        self::STATUS_APPLYING,
        self::STATUS_GENERATING_PAGES,
        self::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        self::STATUS_VERIFICATION_LINKING,
        self::STATUS_QA,
        self::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_ESCALATED,
        self::STATUS_DECISION_ONLY,
    ];

    public const MAINTAINER_DECISION_STATUS_PENDING  = 'pending';
    public const MAINTAINER_DECISION_STATUS_APPLIED  = 'applied';

    public const QA_STATUS_PENDING         = 'pending';
    public const QA_STATUS_RUNNING         = 'running';
    public const QA_STATUS_PASSED          = 'passed';
    public const QA_STATUS_REPAIR_REQUIRED = 'repair_required';
    public const QA_STATUS_FAILED          = 'failed';
    public const QA_STATUS_ESCALATED       = 'escalated';

    public const QA_STATUSES = [
        self::QA_STATUS_PENDING,
        self::QA_STATUS_RUNNING,
        self::QA_STATUS_PASSED,
        self::QA_STATUS_REPAIR_REQUIRED,
        self::QA_STATUS_FAILED,
        self::QA_STATUS_ESCALATED,
    ];

    public const TRIGGER_TYPE_MANUAL = 'manual';

    public const TRIGGER_TYPE_SCHEDULE = 'schedule';

    public const TRIGGER_TYPE_SOURCE_CHANGE = 'source_change';

    public const TRIGGER_TYPES = [
        self::TRIGGER_TYPE_MANUAL,
        self::TRIGGER_TYPE_SCHEDULE,
        self::TRIGGER_TYPE_SOURCE_CHANGE,
    ];

    public const SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION = 'knowledge_item_version';

    public const SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT = 'enterprise_wiki_document';

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
        self::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_ESCALATED,
    ];

    public const NON_TERMINAL_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_MAINTAINER_DECISION,
        self::STATUS_APPLYING,
        self::STATUS_GENERATING_PAGES,
        self::STATUS_GENERATING_CONCEPT_ENTITY_PAGES,
        self::STATUS_VERIFICATION_LINKING,
        self::STATUS_QA,
        self::STATUS_AWAITING_DOCUMENT_OWNER_APPROVAL,
        self::STATUS_DECISION_ONLY,
    ];

    protected $fillable = [
        'uuid',
        'customer_id',
        'enterprise_wiki_page_id',
        'trigger_type',
        'source_type',
        'source_id',
        'source_hash',
        'status',
        'model_used',
        'input_tokens',
        'output_tokens',
        'cost_estimate_nok',
        'error_message',
        'started_at',
        'finished_at',
        'maintainer_decision_json',
        'maintainer_decision_status',
        'maintainer_decision_generated_at',
        'qa_status',
        'qa_started_at',
        'qa_completed_at',
        'qa_attempt_count',
        'qa_last_error',
        'qa_result',
        'maintenance_triggered_at',
        'maintenance_source_hash',
        'deep_repair_attempted_at',
        'deep_repair_source_hash',
        'deep_repair_result',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_estimate_nok' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'maintainer_decision_json' => 'array',
            'maintainer_decision_generated_at' => 'datetime',
            'qa_started_at'   => 'datetime',
            'qa_completed_at' => 'datetime',
            'qa_attempt_count' => 'integer',
            'qa_result'       => 'array',
            'maintenance_triggered_at' => 'datetime',
            'deep_repair_attempted_at' => 'datetime',
            'deep_repair_result'       => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(EnterpriseWikiIngestSection::class, 'enterprise_wiki_ingest_run_id');
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(
            EnterpriseWikiPage::class,
            'enterprise_wiki_ingest_run_pages',
            'enterprise_wiki_ingest_run_id',
            'enterprise_wiki_page_id',
        )->withPivot('action')->withTimestamps();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }
}
