import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * "I arbeid" should feel like the same help affordance as AI → Oversikt, not a near-identical
 * variant. These compare the two pages directly rather than asserting a shape in isolation.
 */
const show = readFileSync(fileURLToPath(new URL('./Show.jsx', import.meta.url)), 'utf8');
const overview = readFileSync(fileURLToPath(new URL('./Index.jsx', import.meta.url)), 'utf8');
const no = readFileSync(fileURLToPath(new URL('../../../../../lang/no/procynia.php', import.meta.url)), 'utf8');
const en = readFileSync(fileURLToPath(new URL('../../../../../lang/en/procynia.php', import.meta.url)), 'utf8');

describe('The help trigger reuses the shared PageHelp concept', () => {
    test('both pages import and use the same component', () => {
        for (const source of [overview, show]) {
            assert.match(source, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/);
            assert.match(source, /<PageHelpButton/);
        }
    });

    test('both use the same button label key, so the trigger reads identically', () => {
        for (const source of [overview, show]) {
            assert.match(source, /buttonLabel=\{tai\.help_button \?\? 'Hjelp'\}/);
        }
    });

    test('no parallel help implementation was added', () => {
        // The panel is the component's own; the page must not roll its own modal.
        assert.ok(!show.includes('PageHelpPanel'));
        assert.equal((show.match(/<PageHelpButton/g) ?? []).length, 1);
    });
});

describe('Placement matches Oversikt', () => {
    test('the trigger sits directly after the page heading', () => {
        for (const source of [overview, show]) {
            assert.match(source, /<\/h1>\s*(\{\/\*[\s\S]*?\*\/\}\s*)?<PageHelpButton/);
        }
    });

    test('it lives in the title row, not in the case-documents card', () => {
        const titleRow = show.slice(
            show.indexOf('<div className="flex flex-wrap items-center gap-3">'),
            show.indexOf('case_workspace_intro'),
        );

        assert.ok(titleRow.includes('<PageHelpButton'), 'the trigger belongs to the heading row');
        // The row already wraps, so narrow screens reflow instead of overlapping.
        assert.ok(titleRow.includes('flex-wrap') || show.includes('flex flex-wrap items-center gap-3'));
    });

    test('the existing status badge and back-link are left where they were', () => {
        assert.match(show, /aiStatusMeta\.className/);
        assert.match(show, /case_workspace_back_to_case/);
        assert.ok(show.indexOf('<PageHelpButton') < show.indexOf('case_workspace_back_to_case'));
    });
});

describe('Help content describes what the page actually does', () => {
    const sections = show.slice(show.indexOf('<PageHelpButton'), show.indexOf('aiStatusMeta.className'));

    test('all four sections are wired', () => {
        for (const key of [
            'workspace_help_section_documents',
            'workspace_help_section_extraction',
            'workspace_help_section_requirements',
            'workspace_help_section_next',
        ]) {
            assert.ok(sections.includes(key), `missing ${key}`);
        }
    });

    test('reject and delete are explained as different things', () => {
        assert.ok(sections.includes('workspace_help_item_requirements_reject_text'));
        assert.ok(sections.includes('workspace_help_item_requirements_delete_text'));
        assert.match(no, /Avvis tar kravet ut av det aktive arbeidet, men kravet beholdes/);
        assert.match(no, /Slett fjerner kravet for godt/);
    });

    test('case documents are described as case-scoped, not company knowledge', () => {
        assert.match(no, /hører til denne saken/);
        assert.match(no, /ikke en del av virksomhetens generelle kunnskapsgrunnlag/);
    });

    test('extraction is described without promising perfect results', () => {
        assert.match(no, /PDF, Word og Excel|PDF-, Word- og Excel/);
        // Honest about variation rather than guaranteeing a clean extraction.
        assert.match(no, /varierer med hvor tydelig kravene er uttrykt/);
    });

    test('both languages define every help key used on the page', () => {
        const used = [...sections.matchAll(/tai\.(workspace_help_[a-z_]+)/g)].map((match) => match[1]);

        assert.ok(used.length >= 12, `expected the full set, found ${used.length}`);

        for (const key of new Set(used)) {
            assert.ok(no.includes(`'${key}' =>`), `missing in no: ${key}`);
            assert.ok(en.includes(`'${key}' =>`), `missing in en: ${key}`);
        }
    });
});
