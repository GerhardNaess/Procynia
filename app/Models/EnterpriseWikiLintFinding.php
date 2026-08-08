<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiLintFinding extends Model
{
    // --- Pre-existing codes (wiki:lint) ---
    public const CODE_CLAIM_MISSING_SOURCE = 'claim_missing_source';

    public const CODE_SOURCE_REFERENCE_MISSING_EXCERPT = 'source_reference_missing_excerpt';

    public const CODE_DOCUMENT_INGEST_FAILED = 'document_ingest_failed';

    // --- 8E-17: applied run lint (wiki:lint-applied-run) ---

    // Page / version
    public const CODE_MISSING_CURRENT_VERSION = 'missing_current_version';

    public const CODE_EMPTY_PAGE_CONTENT = 'empty_page_content';

    public const CODE_PAGE_WITHOUT_CLAIMS = 'page_without_claims';

    /**
     * Wiki run-5: a stronger, block-precise integrity signal than CODE_PAGE_WITHOUT_CLAIMS above —
     * a specific best_practice content block (a persisted Procynia assertion) has no reviewable
     * claim anchored to it at all. Never fired for a page whose blocks are only source_based/
     * structural, since those never produce a claim by design.
     * EnterpriseWikiExtractPageClaimsService::persist() now guarantees a best_practice block
     * always gets a deterministic fallback claim if extraction itself returned none, so this
     * should only ever fire for a legacy page version generated before that fix.
     */
    public const CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM = 'best_practice_block_without_claim';

    // Source reference
    public const CODE_SOURCE_REFERENCE_WITHOUT_DOCUMENT = 'source_reference_without_document';

    public const CODE_SOURCE_REFERENCE_CUSTOMER_MISMATCH = 'source_reference_customer_mismatch';

    // Link / traversal
    public const CODE_PAGE_WITHOUT_OUTGOING_LINKS = 'page_without_outgoing_links';

    public const CODE_PAGE_WITHOUT_INCOMING_LINKS = 'page_without_incoming_links';

    public const CODE_ARTICLE_WITHOUT_SUMMARY_LINK = 'article_without_summary_link';

    public const CODE_SUMMARY_WITHOUT_ARTICLE_LINK = 'summary_without_article_link';

    public const CODE_ARTICLE_WITHOUT_CONCEPT_OR_ENTITY_LINKS = 'article_without_concept_or_entity_links';

    public const CODE_ORPHAN_CONCEPT_PAGE = 'orphan_concept_page';

    public const CODE_ORPHAN_ENTITY_PAGE = 'orphan_entity_page';

    public const CODE_MISSING_REVERSE_LINK = 'missing_reverse_link';

    // Run completeness
    public const CODE_APPLIED_RUN_WITHOUT_PAGES = 'applied_run_without_pages';

    public const CODE_APPLIED_RUN_WITHOUT_ARTICLE = 'applied_run_without_article';

    public const CODE_APPLIED_RUN_WITHOUT_SUMMARY = 'applied_run_without_summary';

    // --- 8I-6: canonical wikilink integrity (wiki:lint-applied-run) ---
    public const CODE_BROKEN_WIKILINK = 'broken_wikilink';

    public const CODE_MALFORMED_WIKILINK = 'malformed_wikilink';

    public const CODE_SELF_WIKILINK = 'self_wikilink';

    public const CODE_CROSS_CUSTOMER_WIKILINK = 'cross_customer_wikilink';

    public const CODE_CONCEPT_WITHOUT_INCOMING_WIKILINK = 'concept_without_incoming_wikilink';

    public const CODE_ENTITY_WITHOUT_INCOMING_WIKILINK = 'entity_without_incoming_wikilink';

    public const CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED = 'run_targets_available_but_not_linked';

    public const CODE_MISSING_WIKILINK_MATERIALIZATION = 'missing_wikilink_materialization';

    public const CODE_WIKILINK_PROJECTION_MISMATCH = 'wikilink_projection_mismatch';

    public const CODE_STALE_WIKILINK_GRAPH_EDGE = 'stale_wikilink_graph_edge';

    // --- Wiki run-586: planned section coverage (wiki:lint-applied-run) ---
    public const CODE_PLANNED_SECTION_MISSING = 'planned_section_missing';

    public const CODE_PLANNED_SECTION_EMPTY = 'planned_section_empty';

    public const CODE_PLANNED_SECTION_ONLY_LINKS = 'planned_section_only_links';

    public const CODE_PLANNED_SECTION_BELOW_MINIMUM_SUBSTANCE = 'planned_section_below_minimum_substance';

    // --- Wiki run-587: planned figure coverage (wiki:lint-applied-run) ---
    public const CODE_PLANNED_FIGURE_MISSING = 'planned_figure_missing';

    public const CODE_PLANNED_FIGURE_WRONG_SECTION = 'planned_figure_wrong_section';

    public const CODE_PLANNED_FIGURE_WRONG_PAGE = 'planned_figure_wrong_page';

    public const CODE_PLANNED_FIGURE_SOURCE_MISSING = 'planned_figure_source_missing';

    public const CODE_PLANNED_FIGURE_DUPLICATE = 'planned_figure_duplicate';

    public const CODES = [
        self::CODE_CLAIM_MISSING_SOURCE,
        self::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
        self::CODE_DOCUMENT_INGEST_FAILED,
        self::CODE_MISSING_CURRENT_VERSION,
        self::CODE_EMPTY_PAGE_CONTENT,
        self::CODE_PAGE_WITHOUT_CLAIMS,
        self::CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM,
        self::CODE_SOURCE_REFERENCE_WITHOUT_DOCUMENT,
        self::CODE_SOURCE_REFERENCE_CUSTOMER_MISMATCH,
        self::CODE_PAGE_WITHOUT_OUTGOING_LINKS,
        self::CODE_PAGE_WITHOUT_INCOMING_LINKS,
        self::CODE_ARTICLE_WITHOUT_SUMMARY_LINK,
        self::CODE_SUMMARY_WITHOUT_ARTICLE_LINK,
        self::CODE_ARTICLE_WITHOUT_CONCEPT_OR_ENTITY_LINKS,
        self::CODE_ORPHAN_CONCEPT_PAGE,
        self::CODE_ORPHAN_ENTITY_PAGE,
        self::CODE_MISSING_REVERSE_LINK,
        self::CODE_APPLIED_RUN_WITHOUT_PAGES,
        self::CODE_APPLIED_RUN_WITHOUT_ARTICLE,
        self::CODE_APPLIED_RUN_WITHOUT_SUMMARY,
        self::CODE_BROKEN_WIKILINK,
        self::CODE_MALFORMED_WIKILINK,
        self::CODE_SELF_WIKILINK,
        self::CODE_CROSS_CUSTOMER_WIKILINK,
        self::CODE_CONCEPT_WITHOUT_INCOMING_WIKILINK,
        self::CODE_ENTITY_WITHOUT_INCOMING_WIKILINK,
        self::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED,
        self::CODE_MISSING_WIKILINK_MATERIALIZATION,
        self::CODE_WIKILINK_PROJECTION_MISMATCH,
        self::CODE_STALE_WIKILINK_GRAPH_EDGE,
        self::CODE_PLANNED_SECTION_MISSING,
        self::CODE_PLANNED_SECTION_EMPTY,
        self::CODE_PLANNED_SECTION_ONLY_LINKS,
        self::CODE_PLANNED_SECTION_BELOW_MINIMUM_SUBSTANCE,
        self::CODE_PLANNED_FIGURE_MISSING,
        self::CODE_PLANNED_FIGURE_WRONG_SECTION,
        self::CODE_PLANNED_FIGURE_WRONG_PAGE,
        self::CODE_PLANNED_FIGURE_SOURCE_MISSING,
        self::CODE_PLANNED_FIGURE_DUPLICATE,
    ];

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_ERROR = 'error';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_ingest_run_id',
        'enterprise_wiki_page_id',
        'enterprise_wiki_page_version_id',
        'enterprise_wiki_claim_id',
        'enterprise_wiki_document_id',
        'code',
        'severity',
        'message',
        'metadata',
        'status',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function version(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'enterprise_wiki_page_version_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiClaim::class, 'enterprise_wiki_claim_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiDocument::class, 'enterprise_wiki_document_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * The single definition of "this finding, while open, prevents qa_status from reaching
     * passed" — error severity, or a broken wikilink specifically (a structural break regardless
     * of the severity it was logged with). EnterpriseWikiPostIngestQaService::findCriticalDefects()
     * and the Kjøringer "Funn" panel (EnterpriseWikiRunFindingsService) both read this same
     * definition; neither is allowed to grow its own copy of the predicate.
     */
    public function isBlocking(): bool
    {
        return $this->severity === self::SEVERITY_ERROR || $this->code === self::CODE_BROKEN_WIKILINK;
    }

    /**
     * Query-level equivalent of isBlocking(), for callers that need to filter/count in SQL
     * rather than load models — e.g. EnterpriseWikiPostIngestQaService::findCriticalDefects() and
     * WikiController::loadRunsTab()'s cheap per-run subqueries.
     */
    public function scopeBlocking($query)
    {
        return $query->where(function ($q): void {
            $q->where('severity', self::SEVERITY_ERROR)
                ->orWhere('code', self::CODE_BROKEN_WIKILINK);
        });
    }
}
