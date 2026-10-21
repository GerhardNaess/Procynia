<?php

namespace App\Models;

use App\Services\EnterpriseWiki\EnterpriseWikiPatchTargetResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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

    /**
     * Fase 8K-3: this run patched a bounded area of an EXISTING page's current version
     * (EnterpriseWikiPatchApplicationService), rather than generating the page from the source
     * document. Distinct from ACTION_UPDATED, which records the ordinary reuse-then-generate path.
     *
     * A patched row is always created with generated_page_version_id already set, so it is never
     * selected as pending generation work by EnterpriseWikiDocumentFlowService::beginGeneratingPages()
     * or FinalizeEnterpriseWikiPageGeneration — both filter on a null version id.
     */
    public const ACTION_PATCHED = 'patched';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_PATCHED,
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
        'link_assessment',
        'links_assessed_at',
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
            'link_assessment' => 'array',
            'links_assessed_at' => 'datetime',
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

    /**
     * A failed patch row for a page the run's decision only ever said to leave alone.
     *
     * Before 6419ccb3 the patch engine recorded a page in review as a deferred update even when
     * every target on it was `preserve` (run 963: page 69). Nothing was ever going to change on
     * that page, so the row is history, not work: it is not a failed page, not a reason to retry,
     * and not an incomplete step. The row itself stays as it was — only the reading changes.
     *
     * Decided from the stored plan alone, never from generation_error: a later wrong-job retry
     * rewrote that text on the very row this exists for. Every target on the page must be
     * `preserve`; one replace or amend, or an operation that cannot be read, keeps the row a real
     * failure.
     *
     * @param  array<string, mixed>  $decision  the run's maintainer_decision_json
     */
    public function isFailedNoChangePatch(array $decision): bool
    {
        if ($this->action !== self::ACTION_PATCHED
            || $this->generation_status !== self::GENERATION_STATUS_FAILED
            || $this->generated_page_version_id !== null) {
            return false;
        }

        $operations = EnterpriseWikiPatchTargetResolver::operationsForPage($decision, (int) $this->enterprise_wiki_page_id);

        return $operations !== null
            && $operations !== []
            && array_unique($operations) === ['preserve'];
    }

    /**
     * Failed for real: generation_status=failed, minus isFailedNoChangePatch(). The one reading of
     * "this run still has a page that did not happen" every reader shares — the retry service,
     * post-ingest QA, the follow-up rule and Kjøringer.
     *
     * @param  Collection<int, self>  $pivots
     * @param  array<string, mixed>  $decision  the run's maintainer_decision_json
     * @return Collection<int, self>
     */
    public static function outstandingFailures(Collection $pivots, array $decision): Collection
    {
        return $pivots
            ->filter(fn (self $pivot): bool => $pivot->generation_status === self::GENERATION_STATUS_FAILED
                && ! $pivot->isFailedNoChangePatch($decision))
            ->values();
    }
}
