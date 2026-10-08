import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    barClass,
    exhaustedNotice,
    formatUnits,
    headline,
    isConfigured,
    percentage,
    periodLabel,
    reservationNotice,
    statusLabel,
    statusTone,
} from './aiCapacity.js';

const here = dirname(fileURLToPath(import.meta.url));
const card = readFileSync(join(here, '..', 'Components', 'App', 'AiCapacityCard.jsx'), 'utf8');
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

    test('the bar takes the full width beside its percentage and the facts stack on phones', () => {
        assert.match(card, /h-3 min-w-0 flex-1/);
        assert.match(card, /grid grid-cols-1 gap-x-8 gap-y-3 text-base sm:grid-cols-2 lg:grid-cols-4/);
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
        assert.match(card, /capacity\.tier_name && \(/);
        assert.ok(!/kr\/mnd|per AI-enhet|price|pris/i.test(card));
    });

    test('a provisional level is said to be phasing in, and never explains the conversion', () => {
        assert.match(card, /capacity\.is_provisional && \(/);
        assert.match(card, /under innfasing/);
        assert.ok(!/nok_per_unit|0[.,]10|øre/i.test(card));
    });
});
