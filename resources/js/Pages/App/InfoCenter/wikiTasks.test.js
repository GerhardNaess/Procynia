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
 * Since punkt 6 a Wiki task is one of several modules' tasks under «Mine oppgaver», delivered by
 * MyTasksService and drawn by the module's own card. The behaviour itself is covered by the PHP
 * feature tests, which can exercise both surfaces; the project has no JSX renderer, so these are
 * source-level guards in the idiom used elsewhere here.
 */
describe('Wiki work appears in the task list', () => {
    test('the list reads «Mine oppgaver» and draws a Wiki task with its own card', () => {
        assert.match(infoCenter, /const myTasks = infoCenter\?\.my_tasks \?\? \{ count: 0, groups: \[\] \};/);
        assert.match(infoCenter, /case 'wiki':\s*\n\s*return <WikiTaskCard task=\{task\} locale=\{locale\} \/>;/);
        assert.match(infoCenter, /<MyTaskGroups myTasks=\{myTasks\} t=\{mt\} renderTask=\{renderTask\} \/>/);
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
