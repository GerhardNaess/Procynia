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

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // Used when the run stores only a maintainer decision — no pages are created.
    public const STATUS_DECISION_ONLY = 'decision_only';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_SECTIONS_PLANNED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_DECISION_ONLY,
    ];

    public const MAINTAINER_DECISION_STATUS_PENDING  = 'pending';
    public const MAINTAINER_DECISION_STATUS_APPLIED  = 'applied';

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
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }
}
