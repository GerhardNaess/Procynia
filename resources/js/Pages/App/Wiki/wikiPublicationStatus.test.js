import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (file) => readFileSync(join(here, file), 'utf8');
const lang = (locale) => readFileSync(join(here, '..', '..', '..', '..', '..', 'lang', locale, 'procynia.php'), 'utf8');

const index = read('Index.jsx');
const panel = read('WikiReviewPanel.jsx');

/**
 * The Wiki used to say what a page's status code was, and nothing about what that meant or what to
 * do about it. These are source-level guards, in the same style as the other Wiki tests: the project
 * has no JSX renderer, and what needs protecting is that the publication state, the next step and
 * the honest split between a blocker and a quality figure all keep reaching the page.
 */
describe('the Wiki list shows how far the knowledge base has come', () => {
    test('the summary strip renders the four counts', () => {
        assert.match(index, /data-testid="wiki-publication-summary"/);
        assert.match(index, /publication_summary_total \?\? 'sider'/);
        assert.match(index, /publication_summary_published \?\? 'publisert'/);
        assert.match(index, /publication_summary_in_review \?\? 'til gjennomgang'/);
        assert.match(index, /publication_summary_draft \?\? 'utkast'/);
    });

    /** A zero next to "endringer kreves" would read as a problem rather than an absence. */
    test('the optional counts only appear when they are not zero', () => {
        assert.match(index, /\(summary\.changes_requested \?\? 0\) > 0/);
        assert.match(index, /\(summary\.unpublished_changes \?\? 0\) > 0/);
    });

    test('nothing is shown for a customer with no pages at all', () => {
        assert.match(index, /if \(! summary \|\| \(summary\.total \?\? 0\) === 0\) \{\s*\n\s*return null;/);
    });
});

describe('the zero-published notice is about tender drafting, not Ask Wiki', () => {
    test('it appears exactly when nothing is published', () => {
        assert.match(index, /\(summary\.published \?\? 0\) === 0 && \(/);
        assert.match(index, /data-testid="wiki-none-published-notice"/);
    });

    /**
     * Ask Wiki grounds in the current working versions and is genuinely unaffected. Saying "the
     * Wiki cannot be used" would be false, so both languages have to keep the distinction.
     */
    test('both languages name tender drafting and exempt Ask Wiki', () => {
        assert.match(lang('no'), /'publication_none_published_body' => 'Tilbudsgenerering bruker publiserte Wiki-sider som kunnskapsgrunnlag\. Spør Wiki bruker gjeldende arbeidsversjoner og påvirkes ikke\.'/);
        assert.match(lang('en'), /'publication_none_published_body' => 'Tender drafting uses published Wiki pages as its knowledge basis\. Ask Wiki uses the current working versions and is unaffected\.'/);
    });

    test('the notice never claims Ask Wiki is unavailable', () => {
        const noBody = lang('no').match(/'publication_none_published_body' => '([^']+)'/)[1];
        assert.ok(! /Spør Wiki (er ikke|kan ikke|virker ikke)/.test(noBody), noBody);
    });
});

describe('every page says what happens to it next', () => {
    test('the list renders the next step and any blockers per row', () => {
        assert.match(index, /data-testid="wiki-page-next-step"/);
        assert.match(index, /\{publication\.next_step_label\}/);
        assert.match(index, /\(publication\.blocking_reasons \?\? \[\]\)\.map/);
    });

    test('a page with nothing outstanding shows no next-step line', () => {
        assert.match(index, /if \(! publication \|\| publication\.next_step === 'none'\) \{/);
    });

    /** Both the table row and the mobile card, or half the users never see it. */
    test('the next step reaches both layouts', () => {
        const occurrences = index.split('<PageNextStep publication={page.publication}').length - 1;
        assert.equal(occurrences, 2);
    });
});

describe('the page itself answers where it stands', () => {
    test('the publication block renders next step and blockers', () => {
        assert.match(panel, /data-testid="wiki-publication-block"/);
        assert.match(panel, /data-testid="wiki-publication-next-step"/);
        assert.match(panel, /data-testid="wiki-publication-blockers"/);
        // The state itself is stated once, by the badge beside the page title — this card is for
        // what happens next, not for repeating what the page is.
        assert.ok(!panel.includes('publication.state_label'));
    });

    /**
     * The distinction the whole change exists to make, now kept by placement rather than by a
     * label: claim progress lives in the quality block, and this card never mentions it. It never
     * belonged among the blockers either — the backend fills those only with gates approve()
     * actually enforces.
     */
    test('claims are nowhere in the publication card', () => {
        const start = panel.indexOf('function PublicationBlock');
        const card = panel.slice(start, panel.indexOf('\n}\n', start));

        assert.ok(! card.includes('claims'), 'not as a status, and not among the blockers');
        assert.ok(! panel.includes('publication_quality_heading'));
        assert.ok(! panel.includes('data-testid="wiki-publication-claims"'));
    });

    /** A published page with nothing outstanding used to render no panel at all. */
    test('the panel renders even when the only thing to say is the next step', () => {
        assert.match(panel, /\|\| publication !== null;/);
    });

    /**
     * The card no longer renders a badge of its own — the page title carries the state once — but
     * the backend still computes it, so every state it can return must still have a name.
     */
    test('every state the backend can return has a label', () => {
        for (const state of [
            'draft', 'in_review', 'published', 'published_with_changes',
            'changes_requested', 'archived', 'no_version',
        ]) {
            assert.match(lang('no'), new RegExp(`'publication_state_${state}' =>`), state);
            assert.match(lang('en'), new RegExp(`'publication_state_${state}' =>`), state);
        }
    });
});

describe('the user never reads a status code', () => {
    test('published and working version are named in words in both languages', () => {
        assert.match(lang('no'), /'publication_state_published_with_changes' => 'Publisert – ny arbeidsversjon finnes'/);
        assert.match(lang('no'), /'publication_state_in_review' => 'Til gjennomgang'/);
        assert.match(lang('en'), /'publication_state_in_review' => 'In review'/);
    });

    test('no next-step label leaks an internal key', () => {
        const labels = [...lang('no').matchAll(/'publication_next_[a-z_]+' => '([^']*)'/g)].map((m) => m[1]);
        assert.ok(labels.length >= 8, `expected the full set of next-step labels, got ${labels.length}`);

        for (const label of labels) {
            assert.ok(
                ! /pending_review|published_version_id|current_version_id|working current/.test(label),
                `internal vocabulary leaked into "${label}"`,
            );
        }
    });
});
