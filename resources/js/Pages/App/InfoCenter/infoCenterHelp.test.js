import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { infoCenterPageHelp, myTasksPanelHelp } from './infoCenterHelp.js';

const here = dirname(fileURLToPath(import.meta.url));
const index = readFileSync(join(here, 'Index.jsx'), 'utf8');

/**
 * The Oppfølging help explains the page the person has. With Anbud: the aksjon views and counters,
 * as before, and «Mine oppgaver». Without: «Mine oppgaver» alone. Which strings are written in each
 * language — and that the no-Anbud ones never speak of Anbud — is checked against the language files
 * by the PHP suite (InfoCenterHelpTextTest); here, which keys each variant reads.
 */
const ANBUD_WORDS = /Venter på svar|Beslutning|Avklaring|aksjon|Anbud|Opprettet av meg|Innkommende|Frister innen/i;
const titlesAndTexts = (help) => [help.intro, ...help.sections.flatMap((section) => [section.title, ...section.items.flatMap((item) => [item.title, item.text])])];

describe('without Anbud', () => {
    const help = infoCenterPageHelp({}, false);

    test('it explains «Mine oppgaver» and nothing the page does not show', () => {
        assert.deepEqual(help.sections.map((section) => section.title), ['Mine oppgaver', 'Praktisk bruk']);
        assert.deepEqual(help.sections[0].items.map((item) => item.title), ['Mine oppgaver', 'Oppgaver fra flere moduler']);

        for (const text of titlesAndTexts(help)) {
            assert.doesNotMatch(text, ANBUD_WORDS, text);
        }
    });

    test('every line comes from translations when they are given', () => {
        const ic = {
            page_help_intro_my_tasks_only: 'INTRO',
            page_help_section_my_tasks: 'SECTION',
            page_help_item_my_tasks_title: 'MY',
            page_help_item_my_tasks_only_text: 'MY_TEXT',
            page_help_item_modules_title: 'MODULES',
            page_help_item_modules_text: 'MODULES_TEXT',
            page_help_section_practical: 'PRACTICAL',
            page_help_item_practical_title: 'DAILY',
            page_help_item_practical_my_tasks_only_text: 'DAILY_TEXT',
            // The Anbud-only strings must not be read.
            page_help_intro: 'ANBUD_INTRO',
            page_help_item_awaiting_title: 'ANBUD',
            page_help_item_practical_text: 'ANBUD_PRACTICAL',
        };
        const lines = titlesAndTexts(infoCenterPageHelp(ic, false));

        assert.deepEqual(lines, ['INTRO', 'SECTION', 'MY', 'MY_TEXT', 'MODULES', 'MODULES_TEXT', 'PRACTICAL', 'DAILY', 'DAILY_TEXT']);
    });

    test('the «Mine oppgaver» panel explains itself without aksjoner', () => {
        assert.equal(myTasksPanelHelp({ my_tasks: { neutral_panel_description: 'Tasks assigned to you that are still open.' } }, false), 'Tasks assigned to you that are still open.');
        assert.doesNotMatch(myTasksPanelHelp({}, false), ANBUD_WORDS);
    });
});

describe('with Anbud', () => {
    const help = infoCenterPageHelp({}, true);

    test('it keeps the existing explanation of the views and adds «Mine oppgaver»', () => {
        assert.deepEqual(
            help.sections[0].items.map((item) => item.title),
            ['Mine oppgaver', 'Oppgaver fra flere moduler', 'Venter på svar', 'Opprettet av meg', 'Innkommende', 'Frister innen 7 dager'],
        );
        assert.equal(help.sections[1].items[0].text, 'Bruk Oppfølging som din daglige personlige oppfølgingsliste. Bruk Arbeidsliste og sakssider til selve anbudssakene.');
    });

    test('the panel keeps the explanation it always had', () => {
        assert.equal(myTasksPanelHelp({}, true), null);
    });
});

describe('the page', () => {
    test('chooses by the backend\'s module status and passes the result to the help and the panel', () => {
        assert.match(index, /const tenderAvailable = infoCenter\?\.tender_available \?\? true;/);
        assert.match(index, /intro=\{pageHelp\.intro\}\s*\n\s*sections=\{pageHelp\.sections\}/);
        assert.match(index, /helpTextOverride=\{item\.key === 'my_tasks' \? myTasksHelp : null\}/);
    });
});
