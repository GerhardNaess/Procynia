import test from 'node:test';
import assert from 'node:assert/strict';
import {
    getWikiQualityCheckCopy,
    groupWikiFindingsByCode,
    splitWikiVerificationFindings,
} from '../../resources/js/Pages/App/Wiki/wikiQualityChecks.js';

test('wiki verification basis splits claim findings from structural findings', () => {
    const findings = [
        { id: 1, code: 'claim_missing_source' },
        { id: 2, code: 'article_without_summary_link' },
        { id: 3, code: 'source_reference_missing_excerpt' },
        { id: 4, code: 'orphan_entity_page' },
    ];

    const { claimFindings, structuralFindings } = splitWikiVerificationFindings(findings);

    assert.deepStrictEqual(claimFindings.map((finding) => finding.id), [1, 3]);
    assert.deepStrictEqual(structuralFindings.map((finding) => finding.id), [2, 4]);
});

test('wiki verification basis groups structural findings by code', () => {
    const groups = groupWikiFindingsByCode([
        { id: 1, code: 'article_without_summary_link' },
        { id: 2, code: 'article_without_summary_link' },
        { id: 3, code: 'orphan_entity_page' },
    ]);

    assert.strictEqual(groups.length, 2);
    assert.deepStrictEqual(groups[0].findings.map((finding) => finding.id), [1, 2]);
    assert.deepStrictEqual(groups[1].findings.map((finding) => finding.id), [3]);
});

test('wiki verification basis translates known and unknown quality checks', () => {
    const tw = {
        quality_checks: {
            article_without_summary_link: {
                label: 'Artikkel mangler lenke til sammendrag',
                description: 'Artikkelen mangler en lenke til sammendragssiden.',
            },
        },
        quality_check_unknown_label: 'Ukjent sjekktype',
        quality_check_unknown_description: 'Denne kvalitetssjekken er ikke oversatt ennå.',
    };

    assert.deepStrictEqual(getWikiQualityCheckCopy('article_without_summary_link', tw), {
        label: 'Artikkel mangler lenke til sammendrag',
        description: 'Artikkelen mangler en lenke til sammendragssiden.',
        unknown: false,
    });

    assert.deepStrictEqual(getWikiQualityCheckCopy('future_unknown_check_type', tw), {
        label: 'Ukjent sjekktype: future_unknown_check_type',
        description: 'Denne kvalitetssjekken er ikke oversatt ennå. (future_unknown_check_type)',
        unknown: true,
    });
});
