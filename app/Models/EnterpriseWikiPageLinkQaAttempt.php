<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiPageLinkQaAttempt extends Model
{
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_SKIPPED,
        self::STATUS_APPLIED,
        self::STATUS_FAILED,
    ];

    public const REASON_NO_CHANGE_RECOMMENDED = 'no_change_recommended';

    public const REASON_INVALID_REVISION = 'invalid_revision';

    public const REASON_AI_UNAVAILABLE = 'ai_unavailable';

    /**
     * The revision was valid but could not be promoted without losing the page's content blocks
     * (image figures, source provenance, claim anchors) — see
     * EnterpriseWikiPageVersionWriter::writeNewCurrentVersionRestoringBlocks(). The page keeps its
     * previous current version; nothing is broken, the improvement is simply declined.
     */
    public const REASON_BLOCK_PROVENANCE_AT_RISK = 'block_provenance_at_risk';

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_ingest_run_id',
        'enterprise_wiki_page_id',
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

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function createdPageVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'created_page_version_id');
    }
}
