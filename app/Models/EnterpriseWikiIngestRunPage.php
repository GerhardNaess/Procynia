<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `generated_page_version_id` is a historical, immutable pointer to the EnterpriseWikiPageVersion
 * this specific run actually produced (via ordinary generation) or last promoted (via a repair or
 * manual-edit flow acting on behalf of this run) — it is set once per generation/promotion event
 * and is never rewritten to follow later repairs made on behalf of a *different* run. Run-detail
 * and run-history views must read this field, never `EnterpriseWikiPageVersion::is_current`.
 * It must never be treated as a synonym for the page's current version — see
 * EnterpriseWikiPageVersion's docblock for that contract.
 */
class EnterpriseWikiIngestRunPage extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
    ];

    public const GENERATION_STATUS_PENDING = 'pending';

    /** Enqueued (a GenerateEnterpriseWikiAppliedPage job exists or was believed to exist) but not yet leased by a worker. */
    public const GENERATION_STATUS_DISPATCHED = 'dispatched';

    public const GENERATION_STATUS_RUNNING = 'running';

    public const GENERATION_STATUS_COMPLETED = 'completed';

    public const GENERATION_STATUS_FAILED = 'failed';

    public const GENERATION_STATUSES = [
        self::GENERATION_STATUS_PENDING,
        self::GENERATION_STATUS_DISPATCHED,
        self::GENERATION_STATUS_RUNNING,
        self::GENERATION_STATUS_COMPLETED,
        self::GENERATION_STATUS_FAILED,
    ];

    protected $fillable = [
        'enterprise_wiki_ingest_run_id',
        'enterprise_wiki_page_id',
        'action',
        'generated_page_version_id',
        'generation_status',
        'generation_dispatched_at',
        'generation_claimed_at',
        'generation_claim_token',
        'generation_started_at',
        'generation_completed_at',
        'generation_error',
        'claims_extracted_at',
        'claims_claimed_at',
        'claims_claim_token',
    ];

    protected function casts(): array
    {
        return [
            'generation_dispatched_at' => 'datetime',
            'generation_claimed_at' => 'datetime',
            'generation_started_at' => 'datetime',
            'generation_completed_at' => 'datetime',
            'claims_extracted_at' => 'datetime',
            'claims_claimed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiIngestRun::class, 'enterprise_wiki_ingest_run_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'enterprise_wiki_page_id');
    }

    public function generatedPageVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'generated_page_version_id');
    }

    public function isGenerationTerminal(): bool
    {
        return in_array($this->generation_status, [
            self::GENERATION_STATUS_COMPLETED,
            self::GENERATION_STATUS_FAILED,
        ], true);
    }
}
