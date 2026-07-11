<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWikiPageLink extends Model
{
    // -------------------------------------------------------------------------
    // Link type constants — forward and reverse pair for each relationship type.
    // Both directions are stored as explicit rows so traversal never requires
    // a reverse lookup query.
    // -------------------------------------------------------------------------

    public const LINK_TYPE_ARTICLE_TO_SUMMARY = 'article_to_summary';

    public const LINK_TYPE_SUMMARY_TO_ARTICLE = 'summary_to_article';

    public const LINK_TYPE_ARTICLE_TO_CONCEPT = 'article_to_concept';

    public const LINK_TYPE_CONCEPT_TO_ARTICLE = 'concept_to_article';

    public const LINK_TYPE_ARTICLE_TO_ENTITY = 'article_to_entity';

    public const LINK_TYPE_ENTITY_TO_ARTICLE = 'entity_to_article';

    public const LINK_TYPE_SUMMARY_TO_CONCEPT = 'summary_to_concept';

    public const LINK_TYPE_CONCEPT_TO_SUMMARY = 'concept_to_summary';

    public const LINK_TYPE_SUMMARY_TO_ENTITY = 'summary_to_entity';

    public const LINK_TYPE_ENTITY_TO_SUMMARY = 'entity_to_summary';

    /**
     * Canonical link type for relations derived from an actual inline [[wikilink]] found
     * in a page's current content_markdown (see EnterpriseWikiLinkParser/Resolver, v0.6/8I-1).
     * Unlike the structural page-type pairs above, this type is directional and NOT built
     * from co-membership or page-type combinatorics — a row only exists because the source
     * page's text literally contains a link to the target page. Never reuse the structural
     * types above for this purpose: doing so would make the origin of a row ambiguous
     * (mechanical page-type pairing vs. an actual authored link).
     */
    public const LINK_TYPE_WIKILINK = 'wikilink';

    public const LINK_TYPES = [
        self::LINK_TYPE_ARTICLE_TO_SUMMARY,
        self::LINK_TYPE_SUMMARY_TO_ARTICLE,
        self::LINK_TYPE_ARTICLE_TO_CONCEPT,
        self::LINK_TYPE_CONCEPT_TO_ARTICLE,
        self::LINK_TYPE_ARTICLE_TO_ENTITY,
        self::LINK_TYPE_ENTITY_TO_ARTICLE,
        self::LINK_TYPE_SUMMARY_TO_CONCEPT,
        self::LINK_TYPE_CONCEPT_TO_SUMMARY,
        self::LINK_TYPE_SUMMARY_TO_ENTITY,
        self::LINK_TYPE_ENTITY_TO_SUMMARY,
        self::LINK_TYPE_WIKILINK,
    ];

    // -------------------------------------------------------------------------
    // Source constants — how the link was established.
    // -------------------------------------------------------------------------

    /**
     * Built algorithmically without AI: either from co-membership in a maintainer decision
     * run (structural page-type pairs), or from deterministically parsing and resolving
     * inline [[wikilinks]] in content_markdown (link_type = wikilink). Both are "no AI
     * involved, computed the same way every time" — the link_type column, not this column,
     * is what distinguishes a structural pairing from an authored content link.
     */
    public const SOURCE_DETERMINISTIC = 'deterministic';

    /** Explicitly recorded in maintainer decision JSON. */
    public const SOURCE_MAINTAINER_DECISION = 'maintainer_decision';

    public const SOURCES = [
        self::SOURCE_DETERMINISTIC,
        self::SOURCE_MAINTAINER_DECISION,
    ];

    // -------------------------------------------------------------------------
    // Confidence constants.
    // -------------------------------------------------------------------------

    public const CONFIDENCE_CERTAIN = 'certain';

    public const CONFIDENCE_INFERRED = 'inferred';

    // -------------------------------------------------------------------------
    // Eloquent
    // -------------------------------------------------------------------------

    protected $fillable = [
        'customer_id',
        'enterprise_wiki_ingest_run_id',
        'from_page_id',
        'to_page_id',
        'from_page_version_id',
        'to_page_version_id',
        'link_type',
        'source',
        'confidence',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'from_page_id');
    }

    public function toPage(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPage::class, 'to_page_id');
    }

    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'from_page_version_id');
    }

    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWikiPageVersion::class, 'to_page_version_id');
    }
}
