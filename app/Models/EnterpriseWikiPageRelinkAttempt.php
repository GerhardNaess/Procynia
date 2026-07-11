<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiPageRelinkAttempt extends Model
{
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_SKIPPED,
        self::STATUS_APPLIED,
        self::STATUS_FAILED,
    ];

    public const REASON_NO_MENTION = 'no_mention';

    public const REASON_ALREADY_LINKED = 'already_linked';

    public const REASON_NO_SEMANTIC_IMPROVEMENT = 'no_semantic_improvement';

    public const REASON_AI_DECLINED_LINK = 'ai_declined_link';

    public const REASON_INVALID_REVISION = 'invalid_revision';

    public const REASON_CANDIDATE_CAP_REACHED = 'candidate_cap_reached';

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_ingest_run_id',
        'trigger_page_id',
        'candidate_page_id',
        'status',
        'reason',
        'created_page_version_id',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiIngestRun::class, 'enterprise_wiki_ingest_run_id');
    }

    public function triggerPage(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'trigger_page_id');
    }

    public function candidatePage(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'candidate_page_id');
    }

    public function createdPageVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'created_page_version_id');
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
