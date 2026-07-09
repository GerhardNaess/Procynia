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

    public const CODES = [
        self::CODE_CLAIM_MISSING_SOURCE,
        self::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
        self::CODE_DOCUMENT_INGEST_FAILED,
        self::CODE_MISSING_CURRENT_VERSION,
        self::CODE_EMPTY_PAGE_CONTENT,
        self::CODE_PAGE_WITHOUT_CLAIMS,
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
            'metadata'    => 'array',
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
}
