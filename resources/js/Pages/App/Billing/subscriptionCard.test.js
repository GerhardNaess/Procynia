import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const billing = readFileSync(join(dirname(fileURLToPath(import.meta.url)), 'Index.jsx'), 'utf8');

/** The Abonnement card: from its section to the section's end. */
function card() {
    const start = billing.indexOf('data-testid="subscription-card"');
    assert.notEqual(start, -1, 'the subscription card is no longer recognisable');

    return billing.slice(start, billing.indexOf('</section>', start));
}

/**
 * Customers read Basis + options + AI capacity. The legacy tiers (Pro/Max/Ultra/Enterprise) still
 * set Stripe prices, the user limit and the Anbud AI-case quota under the hood, but the page never
 * names them and never lets a customer pick one.
 */
describe('the subscription card shows the product model, not the legacy plans', () => {
    test('the card names Basis and its billing facts', () => {
        assert.match(card(), /productLabel/);
        assert.match(billing, /cardText\.product \?\? 'Basis'/);
        assert.match(card(), /cardText\.billing_interval \?\? 'Fakturering'/);
        assert.match(card(), /cardText\.included_users \?\? 'Inkluderte brukere'/);
    });

    test('no legacy plan name or plan label reaches the page', () => {
        assert.doesNotMatch(billing, /\b(Pro|Max|Ultra|Enterprise)\b/);
        assert.doesNotMatch(billing, /plan_label|available_plans|customer_plan/);
    });

    test('there is no way to switch plan from the page', () => {
        assert.doesNotMatch(billing, /change-plan|planChange|Endre abonnement/);
    });

    test('cancelling and resuming are still offered', () => {
        assert.match(card(), /setConfirmCancel\(true\)/);
        assert.match(card(), /setConfirmResume\(true\)/);
        assert.match(billing, /router\.post\('\/app\/billing\/cancel'/);
    });

    test('options, AI capacity and AI cases are not repeated in the card', () => {
        assert.doesNotMatch(card(), /optionPackages|aiCapacity|AiCapacityCard|AI-enheter|AI-saker|ai_quota/);
    });

    test('the card is headed Basis and carries the only Basis status badge', () => {
        assert.match(card(), /<h2[^>]*>\{productLabel\}<\/h2>/);
        assert.doesNotMatch(card(), /cardText\.heading/);
        // What Basis contains is said here, once — not again among the options.
        assert.match(card(), /basisModules/);
        assert.doesNotMatch(options(), /basePackage|basisModules|base_heading|base_help/);
    });
});

/** The Opsjoner section. */
function options() {
    const start = billing.indexOf('data-testid="module-packages"');
    assert.notEqual(start, -1, 'the options section is no longer recognisable');

    return billing.slice(start, billing.indexOf('</section>', start));
}

describe('the page reads Basis → Opsjoner → AI-kapasitet → Fakturaer, without duplicates', () => {
    test('the sections come in the order of the product model', () => {
        const order = [
            billing.indexOf('data-testid="subscription-card"'),
            billing.indexOf('data-testid="module-packages"'),
            billing.indexOf('<AiCapacityCard'),
            billing.indexOf('invoicesText.heading'),
        ];

        assert.ok(order.every((at) => at !== -1));
        assert.deepEqual([...order].sort((a, b) => a - b), order);
    });

    test('the top summary cards that repeated Basis and the services count are gone', () => {
        assert.doesNotMatch(billing, /SummaryCard|summaryHints|subscriptionSummaryValue|procyniaServicesValue/);
    });

    test('options are called Opsjoner, never Tilleggstjenester or Moduler og pakker', () => {
        assert.match(options(), /modulesText\.options_heading \?\? 'Opsjoner'/);
        assert.doesNotMatch(billing, /Tilleggstjenester|tilleggstjenester|Moduler og pakker/);
    });

    test('other invoiced services appear only when there are any', () => {
        assert.match(billing, /\{hasProcyniaServices && \(\s*<section data-testid="other-services"/);
    });

    test('the intro names the new model', () => {
        assert.match(billing, /'Oversikt over Basis, opsjoner, AI-kapasitet og fakturaer\.'/);
    });

    test('buttons are real buttons with visible names', () => {
        assert.equal((card().match(/<button\s/g) ?? []).length, (card().match(/type="button"/g) ?? []).length);
    });
});

