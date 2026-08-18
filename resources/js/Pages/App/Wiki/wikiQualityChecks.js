/**
 * One central presentation mapping for Enterprise Wiki quality (lint) findings.
 *
 * A finding is only useful when the reader can answer three questions: what is wrong, what does it
 * mean, and what should I do about it. The technical lint code answers none of them, so it is never
 * the headline — it is available as a discreet technical reference instead.
 *
 * WIKI_QUALITY_CHECK_REMEDIES mirrors App\Support\EnterpriseWiki\WikiQualityCheckPresentation::REMEDIES
 * (a PHP test asserts the two are identical). Each remedy names a capability the product actually
 * has — regenerating from Kilder, reviewing a claim on the page — or says plainly that the user
 * cannot fix it. Never add a remedy describing an action the UI does not support.
 */
export const WIKI_QUALITY_CHECK_REMEDIES = {
    // Missing or defective generated content — regenerate from the source document.
    missing_current_version: 'source_document',
    empty_page_content: 'source_document',
    page_without_claims: 'source_document',
    best_practice_block_without_claim: 'source_document',
    planned_section_missing: 'source_document',
    planned_section_empty: 'source_document',
    planned_section_only_links: 'source_document',
    planned_section_below_minimum_substance: 'source_document',
    planned_figure_missing: 'source_document',
    planned_figure_wrong_section: 'source_document',
    planned_figure_wrong_page: 'source_document',
    planned_figure_source_missing: 'source_document',
    planned_figure_duplicate: 'source_document',
    document_ingest_failed: 'source_document',
    applied_run_without_pages: 'source_document',
    applied_run_without_article: 'source_document',
    applied_run_without_summary: 'source_document',

    // One claim and its source reference — resolved in the claim cards on this page.
    claim_missing_source: 'claim_review',
    source_reference_missing_excerpt: 'claim_review',
    source_reference_without_document: 'claim_review',
    source_reference_customer_mismatch: 'claim_review',

    // The page text needs a human judgment before anything is regenerated.
    stale_current_assertion: 'page_content',
    cross_page_current_conflict: 'page_content',
    historical_wording_in_current_canonical_content: 'page_content',
    cross_page_consistency_unknown: 'page_content',

    // Links are written by generation; there is no link editor on the page.
    broken_wikilink: 'link_structure',
    malformed_wikilink: 'link_structure',
    self_wikilink: 'link_structure',
    article_without_summary_link: 'link_structure',
    summary_without_article_link: 'link_structure',
    article_without_concept_or_entity_links: 'link_structure',
    orphan_concept_page: 'link_structure',
    orphan_entity_page: 'link_structure',
    page_without_outgoing_links: 'link_structure',
    page_without_incoming_links: 'link_structure',
    concept_without_incoming_wikilink: 'link_structure',
    entity_without_incoming_wikilink: 'link_structure',
    run_targets_available_but_not_linked: 'link_structure',
    missing_reverse_link: 'link_structure',

    // Internal bookkeeping defects: the customer has no lever for these at all.
    cross_customer_wikilink: 'system',
    unmaterialized_wikilink_marker: 'system',
    missing_wikilink_materialization: 'system',
    wikilink_projection_mismatch: 'system',
    stale_wikilink_graph_edge: 'system',
};

export const WIKI_QUALITY_CHECKS = Object.keys(WIKI_QUALITY_CHECK_REMEDIES)
    .reduce((codes, code) => ({ ...codes, [code]: code }), {});

/**
 * Findings the lint engine anchors to a single claim (enterprise_wiki_claim_id). They are about one
 * assertion and its source reference, so they belong with the claim cards — not with findings about
 * the page as a whole. Kept in sync with the claim_review remedy above.
 */
const CLAIM_QUALITY_CHECK_CODES = new Set(
    Object.entries(WIKI_QUALITY_CHECK_REMEDIES)
        .filter(([, remedy]) => remedy === 'claim_review')
        .map(([code]) => code),
);

export function getNestedTranslation(source, path, fallback = null) {
    return path.split('.').reduce((value, key) => value?.[key], source) ?? fallback;
}

/**
 * Resolve the human presentation for one finding code: a plain title, a plain explanation, and the
 * one action that is actually available. An unrecognised or untranslated code never degrades into
 * a raw technical string — it gets a safe, still-actionable fallback, and the code itself is
 * returned separately so the caller can show it as a discreet technical reference.
 */
export function getWikiQualityCheckCopy(code, tw) {
    const label = getNestedTranslation(tw, `quality_checks.${code}.label`);
    const description = getNestedTranslation(tw, `quality_checks.${code}.description`);
    const unknown = !label || !description;
    const remedy = unknown ? 'unknown' : (WIKI_QUALITY_CHECK_REMEDIES[code] ?? 'unknown');

    return {
        code,
        remedy,
        unknown,
        label: label ?? tw.quality_check_unknown_label ?? 'Kvalitetsproblem på Wiki-siden',
        description: description
            ?? tw.quality_check_unknown_description
            ?? 'Procynia har funnet et problem med denne siden som ikke har en egen brukerforklaring ennå.',
        action: getNestedTranslation(tw, `quality_check_actions.${remedy}`)
            ?? getNestedTranslation(tw, 'quality_check_actions.unknown')
            ?? 'Kontroller siden og kildedokumentet. Er problemet fortsatt uklart, kontakt systemansvarlig.',
    };
}

export function isClaimRelatedWikiQualityCheck(code) {
    return CLAIM_QUALITY_CHECK_CODES.has(code);
}

function normalizeFindingText(value) {
    return typeof value === 'string' ? value.trim().replace(/\s+/g, ' ') : '';
}

/**
 * A best-practice finding carries both a title and a section text. The title is the section's
 * heading when one was detected, but falls back to the primary claim's own text when it was not —
 * and the section text of a single-claim section is that very same sentence, so the reader saw the
 * identical paragraph twice in a row. Returns the secondary text only when it genuinely adds
 * something beyond the title; the reason line is unaffected either way.
 */
export function resolveWikiFindingSecondaryText(title, sectionText) {
    if (normalizeFindingText(sectionText) === '') {
        return null;
    }

    return normalizeFindingText(sectionText) === normalizeFindingText(title) ? null : sectionText;
}

export function splitWikiVerificationFindings(findings) {
    if (!Array.isArray(findings)) {
        return {
            claimFindings: [],
            structuralFindings: [],
        };
    }

    const claimFindings = [];
    const structuralFindings = [];

    findings.forEach((finding) => {
        if (!finding || typeof finding !== 'object') {
            return;
        }

        if (isClaimRelatedWikiQualityCheck(finding.code)) {
            claimFindings.push(finding);
            return;
        }

        structuralFindings.push(finding);
    });

    return {
        claimFindings,
        structuralFindings,
    };
}

export function groupWikiFindingsByCode(findings) {
    if (!Array.isArray(findings)) {
        return [];
    }

    const grouped = [];
    const lookup = new Map();

    findings.forEach((finding) => {
        if (!finding || typeof finding !== 'object') {
            return;
        }

        const key = String(finding.code ?? finding.id ?? grouped.length);

        if (!lookup.has(key)) {
            const group = {
                code: finding.code,
                findings: [],
            };

            lookup.set(key, group);
            grouped.push(group);
        }

        lookup.get(key).findings.push(finding);
    });

    return grouped;
}
