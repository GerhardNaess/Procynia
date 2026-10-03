import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const panel = readFileSync(join(here, 'ProcessFlowPanel.jsx'), 'utf8');
const langFile = (locale) =>
    readFileSync(join(here, '..', '..', '..', '..', 'lang', locale, 'procynia.php'), 'utf8');

/**
 * "Avklar" is the one place in Prosessflyt where the user hands Procynia a sentence and expects
 * their own description to come back changed. Two things about that are easy to break by accident
 * and expensive to notice:
 *
 *  1. The browser must not compose the new description itself. It did once — question and answer
 *     glued to the end of the text — and the description became a transcript of its own review.
 *     The rewrite is the server's, and the panel's only job is to send what was typed.
 *  2. An answered suggestion has to leave the screen on the click. The round trip rewrites the
 *     description and reads it again, which is two provider calls; a suggestion that sits there
 *     through both of them is one the user answers twice.
 *
 * Neither is reachable from a pure function, so this reads the source — the same way
 * controlHint.test.js and cockpitLayout.test.js hold their invariants.
 */

const clarify = panel.slice(
    panel.indexOf('function clarify('),
    panel.indexOf('function adopt('),
);

describe('answering a flow clarification', () => {
    test('is sent to the server as question and answer, not as a composed description', () => {
        assert.ok(
            clarify.includes('/blueprint/clarifications/answer'),
            'The answer goes to the endpoint that rewrites the description.',
        );

        // The old shape: `${description} ... ${question} ${answer}`. If anything like it comes
        // back, the question is in the description again.
        assert.ok(
            ! /setDescription\s*\(/.test(clarify),
            'The panel must not write the description itself — the revised text comes back from the server.',
        );
        assert.ok(
            ! clarify.includes('${question}'),
            'The question must never be interpolated into text the panel sends as a description.',
        );

        for (const field of ['question,', 'answer:', 'description,']) {
            assert.ok(clarify.includes(field), `The request carries ${field}`);
        }
    });

    test('takes the suggestion off the screen before the round trip finishes', () => {
        assert.ok(
            clarify.indexOf('setAnswered') < clarify.indexOf('router.post'),
            'The suggestion is removed on the click, not when the response lands.',
        );

        assert.ok(
            panel.includes('! declined.includes(question) && ! answered.includes(question)'),
            'Both an answered and a dismissed suggestion are filtered out of what is rendered.',
        );
    });

    test('lets a new reading have the last word on what is still worth asking', () => {
        // Answering records nothing server-side, so the only thing keeping a suggestion hidden is
        // the local list — and it is cleared when a proposal arrives. A question the answer
        // actually covered is gone because the revised description defines the term; one it did
        // not cover comes back, which is the truth about it.
        const propsEffect = panel.slice(
            panel.indexOf('const source = proposal ?? blueprint;'),
            panel.indexOf('// Going back to the stored flow'),
        );

        assert.ok(propsEffect.includes('setAnswered([])'), 'A new proposal clears the answered list.');
        assert.ok(propsEffect.includes('setDeclined([])'), 'And the declined list, as before.');
    });

    test('the help text under the field promises what actually happens', () => {
        for (const locale of ['no', 'en']) {
            const lang = langFile(locale);

            assert.ok(
                lang.includes("'clarification_answer_help' =>"),
                `${locale}: the field explains what pressing the button does.`,
            );
            assert.ok(
                ! /'clarification_answer_help' => '[^']*legges til i beskrivelsen/.test(lang),
                `${locale}: the answer is woven into the description, not added to it.`,
            );
        }

        assert.ok(
            langFile('no').includes("'flow_clarification_failed' =>"),
            'A rewrite that could not be used says so, in the user\'s own language.',
        );
        assert.ok(
            langFile('en').includes("'flow_clarification_failed' =>"),
            'Both languages.',
        );
    });
});

/** With knowledge already listed on the activity, the button adds to it rather than starting it. */
describe('the article button reads against what the activity already has', () => {
    test('an activity with articles offers a new one, an empty one the first', () => {
        assert.match(panel, /articles\.length > 0\s*\n\s*\? \(tb\.articles_draft_another \?\? 'Opprett ny kunnskapsartikkel'\)\s*\n\s*: \(tb\.articles_draft \?\? 'Opprett kunnskapsartikkel'\)/);
    });

    test('both labels exist in both languages', () => {
        assert.match(langFile('no'), /'articles_draft_another' => 'Opprett ny kunnskapsartikkel'/);
        assert.match(langFile('en'), /'articles_draft_another' => 'Create new knowledge article'/);
    });
});
