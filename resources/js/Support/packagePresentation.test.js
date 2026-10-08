import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { packageActionLabel, packageConfirmation, packageStatus, splitPackages } from './packagePresentation.js';

const here = dirname(fileURLToPath(import.meta.url));
const page = readFileSync(join(here, '..', 'Pages', 'App', 'Billing', 'Index.jsx'), 'utf8');

const names = { basis: 'Basis', risk: 'Risiko', objectives: 'Mål og KPI', compliance: 'Etterlevelse og revisjon', supplier: 'Leverandøroppfølging', tender: 'Anbud' };
const packageName = (key) => names[key];

const entry = (key, overrides = {}) => ({
    key,
    kind: key === 'basis' ? 'base' : 'option',
    status: 'available',
    action: 'order',
    ...overrides,
});

// What ModuleEntitlementService::overviewFor() hands a customer with Basis, Risiko and Leverandøroppfølging.
const overview = [
    entry('basis', { status: 'active', action: null }),
    entry('risk', { status: 'active', action: 'cancel' }),
    entry('objectives'),
    entry('compliance'),
    entry('supplier', { status: 'active', action: 'cancel' }),
    entry('tender'),
];

describe('Basis and five independent options', () => {
    test('Basis stands apart; the five options follow in order', () => {
        const { base, options } = splitPackages(overview);

        assert.equal(base.key, 'basis');
        assert.deepEqual(options.map((row) => row.key), ['risk', 'objectives', 'compliance', 'supplier', 'tender']);
    });

    test('each reads Aktiv or Ikke aktiv, and offers Avbestill or Bestill', () => {
        const { options } = splitPackages(overview);

        assert.deepEqual(options.map((row) => packageStatus(row).label), ['Aktiv', 'Ikke aktiv', 'Ikke aktiv', 'Aktiv', 'Ikke aktiv']);
        assert.deepEqual(options.map((row) => packageActionLabel(row)), ['Avbestill', 'Bestill', 'Bestill', 'Avbestill', 'Bestill']);
    });

    test('Basis is active and offers no action', () => {
        assert.equal(packageStatus(overview[0]).label, 'Aktiv');
        assert.equal(packageActionLabel(overview[0]), null);
    });
});

describe('the confirmation', () => {
    const text = {
        cancel_confirm_messages: {
            supplier: 'Modulen blir ikke lenger tilgjengelig. Registrerte leverandører, vurderinger, dokumentasjon og historikk slettes ikke.',
        },
    };

    test('cancelling names the option and says what is kept', () => {
        const confirmation = packageConfirmation(overview[4], text, packageName);

        assert.equal(confirmation.title, 'Avbestill Leverandøroppfølging?');
        assert.match(confirmation.message, /leverandører, vurderinger, dokumentasjon og historikk slettes ikke/);
        assert.equal(confirmation.confirmLabel, 'Avbestill');
        assert.equal(confirmation.warning, true);
    });

    test('an option without its own text falls back to saying no data is deleted', () => {
        assert.match(packageConfirmation(overview[1], {}, packageName).message, /^Risiko blir ikke lenger tilgjengelig\..*slettes ikke/);
    });

    test('ordering says access still follows roles', () => {
        const confirmation = packageConfirmation(overview[2], {}, packageName);

        assert.equal(confirmation.title, 'Bestill Mål og KPI?');
        assert.match(confirmation.message, /tilganger endres ikke automatisk/);
    });
});

test('the old ladder presentation is gone from the page', () => {
    for (const leftover of ['Inkludert i', 'Endre pakke', 'included_in', 'change_dialog', 'hovedpakke']) {
        assert.equal(page.includes(leftover), false, leftover);
    }
});
