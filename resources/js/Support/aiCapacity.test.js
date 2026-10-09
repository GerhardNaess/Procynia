import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    barClass,
    canChangeLevel,
    exhaustedNotice,
    formatUnits,
    headline,
    isConfigured,
    levelDescription,
    levelUnitsLabel,
    percentage,
    periodLabel,
    reservationNotice,
    statusLabel,
    statusTone,
} from './aiCapacity.js';

const here = dirname(fileURLToPath(import.meta.url));
const card = readFileSync(join(here, '..', 'Components', 'App', 'AiCapacityCard.jsx'), 'utf8');
const levelDialog = readFileSync(join(here, '..', 'Components', 'App', 'AiCapacityLevelDialog.jsx'), 'utf8');
const billing = readFileSync(join(here, '..', 'Pages', 'App', 'Billing', 'Index.jsx'), 'utf8');

const capacity = (overrides = {}) => ({
    is_configured: true,
    included: 5000,
    used: 3200,
    reserved: 0,
    remaining: 1800,
    percentage_used: 64,
    status: 'normal',
    shows_reservation: false,
    period_start: '2026-10-01',
    period_end: '2026-10-31',
    next_period_start: '2026-11-01',
    ...overrides,
});

describe('the shared AI capacity reads in a few seconds', () => {
    test('the headline is used of included, in grouped AI units', () => {
        // nb-NO groups with a narrow no-break space.
        assert.equal(headline(capacity()).replace(/\s/g, ' '), '3 200 av 5 000 AI-enheter brukt');
        assert.equal(formatUnits(1800).replace(/\s/g, ' '), '1 800');
    });

    test('the period is the first and last day as one range', () => {
        assert.equal(periodLabel(capacity()).replace(/\s/g, ' '), '1.–31. oktober 2026');
        assert.match(periodLabel(capacity({ period_start: '2026-10-15', period_end: '2026-11-14' })), /15\. okt.*14\. nov.*2026/);
    });

    test('the bar is clamped to 0–100', () => {
        assert.equal(percentage(capacity()), 64);
        assert.equal(percentage(capacity({ percentage_used: 140 })), 100);
        assert.equal(percentage(capacity({ percentage_used: null })), 0);
    });
});

describe('three plain statuses, carried by text first', () => {
    test('normal, warning and exhausted have their own words, tone and bar colour', () => {
        assert.equal(statusLabel(capacity()), 'God kapasitet');
        assert.equal(statusLabel(capacity({ status: 'warning' })), 'Nærmer seg grensen');
        assert.equal(statusLabel(capacity({ status: 'exhausted' })), 'Brukt opp');
        assert.equal(statusTone(capacity({ status: 'warning' })), 'amber');
        assert.equal(statusTone(capacity({ status: 'exhausted' })), 'rose');
        assert.equal(barClass(capacity()), 'bg-emerald-500');
    });

    test('translations win over the fallbacks', () => {
        assert.equal(statusLabel(capacity(), { statuses: { normal: 'Good capacity' } }), 'Good capacity');
    });

    test('exhausted says when new capacity arrives', () => {
        assert.equal(exhaustedNotice(capacity()), null);
        assert.equal(
            exhaustedNotice(capacity({ status: 'exhausted' })),
            'AI-kapasiteten for denne perioden er brukt opp. Ny kapasitet blir tilgjengelig 1. november 2026.',
        );
    });

    test('a reservation is mentioned only when the backend says it matters', () => {
        assert.equal(reservationNotice(capacity({ reserved: 3 })), null);
        assert.equal(
            reservationNotice(capacity({ reserved: 120, shows_reservation: true })),
            '120 enheter er midlertidig reservert av AI-arbeid som pågår.',
        );
    });

    test('a capacity that is not configured is never shown as zero of zero', () => {
        assert.equal(isConfigured({ is_configured: false, included: null }), false);
        assert.equal(statusTone({ is_configured: false }), 'slate');
    });
});

describe('the card stays simple and customer-safe', () => {
    test('no token, model or money vocabulary', () => {
        for (const word of ['token', 'model', 'NOK', 'kr', 'OpenAI', 'cost']) {
            assert.ok(!new RegExp(`\\b${word}\\b`, 'i').test(card), `the card mentions ${word}`);
        }
    });

    test('one progress bar, no chart', () => {
        assert.equal((card.match(/role="progressbar"/g) ?? []).length, 1);
        assert.ok(!/<svg|recharts|Chart/i.test(card));
    });

    test('every visible text is at least 16 px', () => {
        assert.ok(!/text-(xs|sm)\b/.test(card), 'text-xs/text-sm is below 16 px');
    });

    test('the bar takes the full width beside its percentage and the facts sit two by two on phones', () => {
        assert.match(card, /h-3 min-w-0 flex-1/);
        assert.match(card, /grid grid-cols-2 gap-x-6 gap-y-4 border-t border-slate-100 pt-4 lg:grid-cols-4/);
    });

    test('Abonnement no longer presents AI cases as its AI picture', () => {
        assert.ok(billing.includes('<AiCapacityCard'));
        assert.ok(!/AiQuotaCard|ai_quota|included_ai_credits|KI-tilbud/.test(billing));
    });

    test('no plan, Basis or option sizes the capacity on the page', () => {
        assert.ok(!/included_ai_units|includedAiUnits|AI-enheter per måned/.test(billing));
        assert.ok(!/Basis (gir|inkluderer)[^'.]*AI|inkludert i Basis|inkluderer en felles AI-kapasitet/i.test(billing + card));
    });

    test('Basis, the options and the AI capacity are three separate blocks, in that order', () => {
        const basis = billing.indexOf('data-testid="subscription-card"');
        const options = billing.indexOf('data-testid="module-packages"');
        const ai = billing.indexOf('<AiCapacityCard');

        assert.ok(basis !== -1 && options !== -1 && ai !== -1);
        assert.ok(basis < options && options < ai, 'Basis → Opsjoner → AI-kapasitet');
        // The AI card is outside both other sections, not nested in the Basis card.
        assert.ok(billing.lastIndexOf('</section>', ai) > options);
        assert.ok(!billing.slice(basis, options).includes('ai_capacity'), 'AI capacity is not mixed into the Basis card');
    });

    test('an unconfigured capacity is said so honestly, with usage, never as 0 of 0', () => {
        const unconfigured = card.slice(card.indexOf('if (!isConfigured(capacity))'), card.indexOf('const reserved ='));

        assert.match(unconfigured, /ikke konfigurert ennå/);
        assert.match(unconfigured, /ikke satt en kommersiell kapasitetsgrense/);
        assert.match(unconfigured, /ai-capacity-used/);
        assert.ok(!/headline\(|role="progressbar"|ai-capacity-remaining|ai-capacity-included/.test(unconfigured));
    });

    test('the provisional note is said once, quietly, without warning colours', () => {
        assert.equal((card.match(/provisional_note/g) ?? []).length, 1);
        const note = card.slice(card.indexOf('capacity.is_provisional && ('), card.indexOf('</p>', card.indexOf('capacity.is_provisional && (')));
        assert.ok(!/amber|rose|red|bg-/.test(note), 'the note is plain text, not a warning box');
    });

    test('an unconfigured capacity shows no status badge', () => {
        const unconfigured = card.slice(card.indexOf('if (!isConfigured(capacity))'), card.indexOf('const reserved ='));
        assert.ok(!/StatusBadge|statusLabel|God kapasitet|Brukt opp/.test(unconfigured));
    });

    test('the selected tier is named only when it sizes the capacity, and never with a price', () => {
        assert.match(card, /capacity\.tier_name \?/);
        assert.ok(!/kr\/mnd|per AI-enhet|price|pris/i.test(card));
    });

    test('an override says the capacity is specially configured and disables the level choice', () => {
        assert.match(card, /særskilt konfigurert for virksomheten/);
        assert.match(card, /disabled=\{!levelChangeable\}/);
        assert.match(card, /isOpen=\{dialogOpen && levelChangeable\}/);
    });

    test('the level dialog shows names and customer-specific units, never the formula or a price', () => {
        assert.match(levelDialog, /levelUnitsLabel\(level/);
        assert.match(levelDialog, /level\.name/);
        assert.ok(!/multiplier|weight|per_user|nok_per_unit|token|modell|kr\b|pris|price|×|%/i.test(levelDialog.replace(/\/\*\*[\s\S]*?\*\//g, '')));
        // No fixed amounts: every figure comes from the payload.
        assert.ok(!/1[ .]?000|2[ .]?500|5[ .]?000/.test(levelDialog));
    });

    test('a provisional level is said to be phasing in, and never explains the conversion', () => {
        assert.match(card, /capacity\.is_provisional && \(/);
        assert.match(card, /under innfasing/);
        assert.ok(!/nok_per_unit|0[.,]10|øre/i.test(card));
    });
});

describe('AI capacity levels', () => {
    const capacity = { is_configured: true, level_changeable: true };
    const levels = [
        { key: 'level_1', name: 'Nivå 1', included: 2400, is_current: true },
        { key: 'level_2', name: 'Nivå 2', included: 3600, is_current: false },
        { key: 'level_3', name: 'Nivå 3', included: 4800, is_current: false },
    ];

    test('the level can be changed only while the level sizes the capacity', () => {
        assert.equal(canChangeLevel(capacity, levels), true);
        assert.equal(canChangeLevel({ ...capacity, level_changeable: false }, levels), false, 'override');
        assert.equal(canChangeLevel({ is_configured: false, level_changeable: true }, levels), false);
        assert.equal(canChangeLevel(capacity, levels.slice(0, 1)), false, 'nothing to choose between');
        assert.equal(canChangeLevel(capacity, undefined), false);
    });

    test('each level reads as the units it would include for this customer', () => {
        assert.equal(levelUnitsLabel(levels[1], { level_units: ':units AI-enheter' }, 'nb-NO').replace(/\s/g, ' '), '3 600 AI-enheter');
        assert.equal(levelUnitsLabel(levels[2], {}, 'en-GB'), '4,800 AI-enheter');
    });

    test('a level has a short description only when one is configured', () => {
        const texts = { level_descriptions: { level_1: 'Standard', level_2: 'Mer kapasitet' } };
        assert.equal(levelDescription(levels[0], texts), 'Standard');
        assert.equal(levelDescription(levels[2], texts), null);
        assert.equal(levelDescription(levels[0], {}), null);
    });
});
