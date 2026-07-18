export const WIKI_QUALITY_CHECKS = {
    applied_run_without_article: 'applied_run_without_article',
    applied_run_without_pages: 'applied_run_without_pages',
    applied_run_without_summary: 'applied_run_without_summary',
    article_without_concept_or_entity_links: 'article_without_concept_or_entity_links',
    article_without_summary_link: 'article_without_summary_link',
    broken_wikilink: 'broken_wikilink',
    claim_missing_source: 'claim_missing_source',
    concept_without_incoming_wikilink: 'concept_without_incoming_wikilink',
    cross_customer_wikilink: 'cross_customer_wikilink',
    empty_page_content: 'empty_page_content',
    entity_without_incoming_wikilink: 'entity_without_incoming_wikilink',
    malformed_wikilink: 'malformed_wikilink',
    missing_current_version: 'missing_current_version',
    missing_reverse_link: 'missing_reverse_link',
    missing_wikilink_materialization: 'missing_wikilink_materialization',
    orphan_concept_page: 'orphan_concept_page',
    orphan_entity_page: 'orphan_entity_page',
    page_without_claims: 'page_without_claims',
    page_without_incoming_links: 'page_without_incoming_links',
    page_without_outgoing_links: 'page_without_outgoing_links',
    run_targets_available_but_not_linked: 'run_targets_available_but_not_linked',
    self_wikilink: 'self_wikilink',
    source_reference_customer_mismatch: 'source_reference_customer_mismatch',
    source_reference_missing_excerpt: 'source_reference_missing_excerpt',
    source_reference_without_document: 'source_reference_without_document',
    stale_wikilink_graph_edge: 'stale_wikilink_graph_edge',
    summary_without_article_link: 'summary_without_article_link',
    wikilink_projection_mismatch: 'wikilink_projection_mismatch',
};

const CLAIM_QUALITY_CHECK_CODES = new Set([
    'claim_missing_source',
    'source_reference_missing_excerpt',
]);

export function getNestedTranslation(source, path, fallback = null) {
    return path.split('.').reduce((value, key) => value?.[key], source) ?? fallback;
}

export function getWikiQualityCheckCopy(code, tw) {
    const key = WIKI_QUALITY_CHECKS[code];
    const translated = key ? {
        label: getNestedTranslation(tw, `quality_checks.${key}.label`),
        description: getNestedTranslation(tw, `quality_checks.${key}.description`),
    } : null;

    if (translated) {
        return {
            label: translated.label ?? code,
            description: translated.description ?? '',
            unknown: false,
        };
    }

    const unknownLabel = tw.quality_check_unknown_label ?? 'Ukjent sjekktype';
    const unknownDescription = tw.quality_check_unknown_description ?? 'Denne kvalitetssjekken er ikke oversatt ennå.';

    return {
        label: `${unknownLabel}: ${code}`,
        description: `${unknownDescription} (${code})`,
        unknown: true,
    };
}

export function isClaimRelatedWikiQualityCheck(code) {
    return CLAIM_QUALITY_CHECK_CODES.has(code);
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
