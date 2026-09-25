import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const infoCenter = readFileSync(join(here, 'Index.jsx'), 'utf8');

/**
 * Wiki work, on the surface that answers "what is still on me".
 *
 * The bell answers a different question — "has anything new happened" — and a read notification
 * answers it. Only doing the work answers this one, which is why a Wiki task is read live from the
 * assignment rather than mirrored into a row somebody would have to close.
 *
 * The behaviour itself is covered by the PHP feature tests, which can exercise both surfaces; the
 * project has no JSX renderer, so these are source-level guards in the idiom used elsewhere here.
 */
describe('Wiki work appears in the task list', () => {
    test('the list reads the aggregated Wiki tasks', () => {
        assert.match(infoCenter, /const wikiTasks = infoCenter\?\.wiki_tasks \?\? \[\];/);
        assert.match(infoCenter, /<WikiTaskCard key=\{task\.id\} task=\{task\} locale=\{locale\} \/>/);
    });

    test('an empty page means no ordinary tasks AND no Wiki tasks', () => {
        // Otherwise a person whose only work is a Wiki review is told they have nothing to do.
        assert.match(infoCenter, /items\.length === 0 && wikiTasks\.length === 0/);
    });

    test('review and quality assurance are told apart, not merged', () => {
        assert.match(infoCenter, /const isReview = task\.type === 'wiki_review';/);
        assert.match(infoCenter, /data-testid=\{isReview \? 'info-center-wiki-review-task' : 'info-center-wiki-qa-task'\}/);
        // Different work, so a different detail row: who handed it over versus how far the claims
        // have got.
        assert.match(infoCenter, /\{isReview \? 'Sendt av' : 'Påstander'\}/);
        assert.match(infoCenter, /\{isReview \? 'Sendt inn' : 'Tildelt'\}/);
    });

    test('the card goes straight to the page it is about', () => {
        assert.match(infoCenter, /const detailUrl = task\.action_url \?\? '#';/);
        assert.match(infoCenter, /Åpne Wiki-side/);
    });
});
