import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (file) => readFileSync(join(here, file), 'utf8');

const controlHint = read('ControlHint.jsx');
const shared = read('hintTooltip.jsx');
const infoHint = read('InfoHint.jsx');
const bell = read('NotificationBell.jsx');
const layout = readFileSync(join(here, '..', '..', 'Layouts', 'CustomerAppLayout.jsx'), 'utf8');
const langFile = (locale) => readFileSync(join(here, '..', '..', '..', '..', 'lang', locale, 'procynia.php'), 'utf8');

/**
 * The bell and "Oppfølging" sit next to each other, and both look like somewhere work might be
 * waiting. They are not the same thing: the bell holds messages about what has already happened,
 * and its badge counts the unread ones; Oppfølging holds what is still to be done. Nothing on
 * screen said so, and the two are easiest to confuse exactly when there is a number on one of them.
 *
 * Each now carries its own sentence. Source-level guards, the idiom used elsewhere in this suite;
 * hover, keyboard focus, the ARIA wiring and the unchanged click behaviour were driven in a
 * browser, and the readable-text standard is measured by the e2e tooltip specs.
 */
describe('an explanation attaches to a control that already exists', () => {
    test('the trigger keeps its own job and gains a description', () => {
        assert.match(controlHint, /const trigger = cloneElement\(children, \{/);
        assert.match(controlHint, /'aria-describedby': visible \? tooltipId : undefined,/);
    });

    test('it opens on hover and on keyboard focus alike', () => {
        assert.match(controlHint, /onMouseEnter=\{open\}/);
        assert.match(controlHint, /onMouseLeave=\{close\}/);
        assert.match(controlHint, /onFocus: \(event\) => \{[\s\S]*?open\(\);/);
        assert.match(controlHint, /onBlur: \(event\) => \{[\s\S]*?close\(\);/);
    });

    test('it does not swallow handlers the trigger already had', () => {
        assert.match(controlHint, /children\.props\.onFocus\?\.\(event\);/);
        assert.match(controlHint, /children\.props\.onBlur\?\.\(event\);/);
    });

    test('no title attribute, so the browser does not draw a second tooltip over this one', () => {
        assert.ok(! /title=/.test(controlHint));
        assert.ok(! /title=/.test(shared));
    });

    test('a trigger that opens a panel of its own can silence the hint', () => {
        assert.match(controlHint, /suppressed = false/);
        assert.match(controlHint, /const visible = isOpen && ! suppressed && Boolean\(text\);/);
    });

    test('with nothing to say it renders the trigger untouched', () => {
        assert.match(controlHint, /if \(! text\) \{\s*\n\s*return children;\s*\n\s*\}/);
    });
});

describe('both hint components share one tooltip', () => {
    test('the panel and its behaviour are defined once', () => {
        assert.match(shared, /export function useHintTooltip/);
        assert.match(shared, /export function HintTooltipPanel/);

        for (const source of [controlHint, infoHint]) {
            assert.match(source, /from '\.\/hintTooltip'/);
            assert.match(source, /<HintTooltipPanel/);
            assert.match(source, /useHintTooltip\(align\)/);
        }
    });

    test('InfoHint no longer carries its own copy of the machinery', () => {
        for (const gone of ['ALIGN_BASE_TRANSFORM', 'recalculatePosition', 'tooltipWidthClass', 'useLayoutEffect']) {
            assert.ok(! infoHint.includes(gone), `${gone} should live in the shared tooltip now`);
        }
    });

    test('the panel keeps the readable-text standard the e2e specs measure', () => {
        // 16px at leading-7 in slate-700. A one-line explanation is the easiest thing to set small.
        assert.match(shared, /text-base font-normal leading-7/);
        assert.match(shared, /bg-white text-slate-700/);
    });

    test('it is announced as a tooltip, and clamped inside the viewport', () => {
        assert.match(shared, /role="tooltip"/);
        assert.match(shared, /const viewportWidth = window\.innerWidth;/);
        assert.match(shared, /el\.style\.transform = `\$\{baseTransform\} translateX\(\$\{shift\}px\)`/);
    });

    test('Escape closes it and gives focus back', () => {
        assert.match(shared, /if \(event\.key === 'Escape'\) \{\s*\n\s*setIsOpen\(false\);\s*\n\s*triggerRef\.current\?\.focus\(\);/);
    });
});

describe('the bell says what its badge counts', () => {
    test('the trigger is wrapped, and quietens while its own panel is open', () => {
        assert.match(bell, /<ControlHint text=\{hint\} suppressed=\{isOpen\}>/);
        assert.match(bell, /<\/ControlHint>/);
    });

    test('the text is passed in from the header, so it is translated', () => {
        assert.match(layout, /hint=\{translations\.frontend\.notifications_hint\s*\n\s*\?\? 'Nye varsler du ikke har lest'\}/);
    });

    test('the count, the badge and the polling are untouched', () => {
        assert.match(bell, /const unreadCount = Number\(notifications\?\.unread_count \?\? 0\);/);
        assert.match(bell, /const badgeLabel = unreadCount > 99 \? '99\+' : String\(unreadCount\);/);
        assert.match(bell, /\{unreadCount > 0 \? \(/);
        assert.match(layout, /const NOTIFICATION_POLL_MS = 60000;/);
    });

    test('its click still opens the panel', () => {
        assert.match(bell, /onClick=\{onToggle\}/);
        assert.match(bell, /aria-controls="app-notifications-panel"/);
    });
});

describe('follow-up says it is the queue, not the history', () => {
    test('the link is wrapped and keeps its destination', () => {
        assert.match(layout, /<ControlHint text=\{followUpNavigation\.hint\}>/);
        assert.match(layout, /href=\{followUpNavigation\.href\}/);
        assert.match(layout, /data-testid="header-follow-up"/);
    });

    test('it still carries no badge', () => {
        const followUp = layout.slice(layout.indexOf('const followUpNavigation = {'), layout.indexOf('};', layout.indexOf('const followUpNavigation = {')));

        assert.ok(! /count/i.test(followUp), 'no count until one is actually shared');
        assert.ok(! followUp.includes('unread'), 'and never the bell\'s number, which means something else');
    });
});

describe('the two sentences say different things, in both languages', () => {
    test('each is a translation key with a Norwegian fallback', () => {
        for (const locale of ['no', 'en']) {
            const lang = langFile(locale);

            assert.match(lang, /'notifications_hint' => '/, `notifications_hint missing in ${locale}`);
            assert.match(lang, /'follow_up_hint' => '/, `follow_up_hint missing in ${locale}`);
        }
    });

    test('one is about what arrived, the other about what remains', () => {
        const no = langFile('no');
        const alerts = no.match(/'notifications_hint' => '([^']*)'/)?.[1] ?? '';
        const followUp = no.match(/'follow_up_hint' => '([^']*)'/)?.[1] ?? '';

        assert.match(alerts, /lest/, 'the bell explains reading');
        assert.match(followUp, /oppfølging/, 'follow-up explains work');
        assert.notEqual(alerts, followUp);

        // The point of the pair: neither borrows the other's idea.
        assert.ok(! /oppgave/i.test(alerts), 'the bell must not claim to hold tasks');
        assert.ok(! /varsel|ulest/i.test(followUp), 'follow-up must not claim to hold alerts');
    });
});
