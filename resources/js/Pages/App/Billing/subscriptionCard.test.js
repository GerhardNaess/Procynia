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

    test('modules and AI cases are not repeated in the card', () => {
        assert.doesNotMatch(card(), /modulePackages|basePackage|AI-saker|ai_quota/);
    });
});
