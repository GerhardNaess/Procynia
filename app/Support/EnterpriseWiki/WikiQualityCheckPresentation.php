<?php

namespace App\Support\EnterpriseWiki;

use App\Models\EnterpriseWikiLintFinding;

/**
 * Purpose: Turn a technical lint code into the three things a Wiki reader actually needs — what is
 * wrong, what it means, and what they should do about it. The lint engine itself is untouched; this
 * is presentation only.
 *
 * The "what should I do" half is the reason this class exists. A recommended action is only useful
 * if it is true, so every code is mapped to one of a small set of REMEDIES that describe a real
 * capability of the current UI (re-generating from Kilder, reviewing a claim on the page) or state
 * plainly that the user cannot fix it. Adding an action that the product does not support would be
 * worse than saying nothing.
 *
 * The frontend mirror of the remedy map lives in resources/js/Pages/App/Wiki/wikiQualityChecks.js,
 * because page findings are rendered client-side from the lint_findings prop. A test asserts the
 * two stay identical.
 */
class WikiQualityCheckPresentation
{
    /** The generated Wiki content is wrong or incomplete; the fix runs through the source document. */
    public const REMEDY_SOURCE_DOCUMENT = 'source_document';

    /** The finding is about one claim and its source reference, handled in the claim cards on the page. */
    public const REMEDY_CLAIM_REVIEW = 'claim_review';

    /** The page text itself needs a human read-through against the source before anything is regenerated. */
    public const REMEDY_PAGE_CONTENT = 'page_content';

    /** Links are produced by generation and cannot be hand-edited on the page. */
    public const REMEDY_LINK_STRUCTURE = 'link_structure';

    /** Nothing the customer can do — an internal defect that needs technical follow-up. */
    public const REMEDY_SYSTEM = 'system';

    /** A code with no human presentation yet. Never shown as a bare technical code. */
    public const REMEDY_UNKNOWN = 'unknown';

    /**
     * Every lint code, mapped to the one honest remedy for it.
     *
     * @var array<string, string>
     */
    public const REMEDIES = [
        // Missing or defective generated content — regenerate from the source document.
        EnterpriseWikiLintFinding::CODE_MISSING_CURRENT_VERSION => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_EMPTY_PAGE_CONTENT => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_CLAIMS => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_BEST_PRACTICE_BLOCK_WITHOUT_CLAIM => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_MISSING => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_EMPTY => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_ONLY_LINKS => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_SECTION_BELOW_MINIMUM_SUBSTANCE => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_MISSING => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_SECTION => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_WRONG_PAGE => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_SOURCE_MISSING => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_PLANNED_FIGURE_DUPLICATE => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_DOCUMENT_INGEST_FAILED => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_PAGES => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_ARTICLE => self::REMEDY_SOURCE_DOCUMENT,
        EnterpriseWikiLintFinding::CODE_APPLIED_RUN_WITHOUT_SUMMARY => self::REMEDY_SOURCE_DOCUMENT,

        // One claim and its source reference — resolved in the claim cards on this very page.
        EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE => self::REMEDY_CLAIM_REVIEW,
        EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT => self::REMEDY_CLAIM_REVIEW,
        EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_WITHOUT_DOCUMENT => self::REMEDY_CLAIM_REVIEW,
        EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_CUSTOMER_MISMATCH => self::REMEDY_CLAIM_REVIEW,

        // The page says something that needs a human judgment before anything is regenerated.
        EnterpriseWikiLintFinding::CODE_STALE_CURRENT_ASSERTION => self::REMEDY_PAGE_CONTENT,
        EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CURRENT_CONFLICT => self::REMEDY_PAGE_CONTENT,
        EnterpriseWikiLintFinding::CODE_HISTORICAL_WORDING_IN_CURRENT_CANONICAL_CONTENT => self::REMEDY_PAGE_CONTENT,
        EnterpriseWikiLintFinding::CODE_CROSS_PAGE_CONSISTENCY_UNKNOWN => self::REMEDY_PAGE_CONTENT,

        // Links between pages are written by generation; there is no link editor on the page.
        EnterpriseWikiLintFinding::CODE_BROKEN_WIKILINK => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_MALFORMED_WIKILINK => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_SELF_WIKILINK => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_SUMMARY_LINK => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_SUMMARY_WITHOUT_ARTICLE_LINK => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_ARTICLE_WITHOUT_CONCEPT_OR_ENTITY_LINKS => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_ORPHAN_CONCEPT_PAGE => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_ORPHAN_ENTITY_PAGE => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_OUTGOING_LINKS => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_INCOMING_LINKS => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_CONCEPT_WITHOUT_INCOMING_WIKILINK => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_ENTITY_WITHOUT_INCOMING_WIKILINK => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_RUN_TARGETS_AVAILABLE_BUT_NOT_LINKED => self::REMEDY_LINK_STRUCTURE,
        EnterpriseWikiLintFinding::CODE_MISSING_REVERSE_LINK => self::REMEDY_LINK_STRUCTURE,

        // Internal bookkeeping defects: the customer has no lever for these at all.
        EnterpriseWikiLintFinding::CODE_CROSS_CUSTOMER_WIKILINK => self::REMEDY_SYSTEM,
        EnterpriseWikiLintFinding::CODE_UNMATERIALIZED_WIKILINK_MARKER => self::REMEDY_SYSTEM,
        EnterpriseWikiLintFinding::CODE_MISSING_WIKILINK_MATERIALIZATION => self::REMEDY_SYSTEM,
        EnterpriseWikiLintFinding::CODE_WIKILINK_PROJECTION_MISMATCH => self::REMEDY_SYSTEM,
        EnterpriseWikiLintFinding::CODE_STALE_WIKILINK_GRAPH_EDGE => self::REMEDY_SYSTEM,
    ];

    /**
     * Purpose: Resolve the human presentation for one lint code.
     * Inputs: The technical lint code.
     * Returns: array{label: string, description: string, action: string, remedy: string, unknown: bool}
     * Side effects: None.
     */
    public static function copy(string $code): array
    {
        $label = self::translated('quality_checks.'.$code.'.label');
        $description = self::translated('quality_checks.'.$code.'.description');
        $remedy = self::REMEDIES[$code] ?? self::REMEDY_UNKNOWN;
        $unknown = $label === null || $description === null;

        if ($unknown) {
            $remedy = self::REMEDY_UNKNOWN;
        }

        return [
            'label' => $label ?? __('procynia.wiki.quality_check_unknown_label'),
            'description' => $description ?? __('procynia.wiki.quality_check_unknown_description'),
            'action' => __('procynia.wiki.quality_check_actions.'.$remedy),
            'remedy' => $remedy,
            'unknown' => $unknown,
        ];
    }

    /**
     * Purpose: Read one translation, distinguishing "missing" from "translated to something".
     * Inputs: The key below procynia.wiki.
     * Returns: The translated string, or null when the key does not exist.
     * Side effects: None.
     */
    private static function translated(string $key): ?string
    {
        $full = 'procynia.wiki.'.$key;
        $value = __($full);

        return (is_string($value) && $value !== $full) ? $value : null;
    }
}
