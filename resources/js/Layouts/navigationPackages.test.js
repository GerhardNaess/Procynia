import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { railEntries } from '../Support/appModules.js';

/**
 * The rail for the commercial packages Procynia sells, as the rail actually receives them: the
 * technical modules the backend resolved (`entitlements.modules`, already in config sort_order)
 * plus the person's permissions. Packages never reach the frontend, so this matrix is written in
 * the modules each package in config/procynia_modules.php resolves to; that resolution itself is
 * covered in PHP (tests/Feature/App/NavigationEntitlementMatrixTest.php) against the real config.
 *
 * Anbud is an add-on: it is in a module set only when the customer chose it, never because of the
 * governance package beside it.
 */
const BASIS = ['wiki', 'quality', 'improvements'];
const STYRING = ['wiki', 'quality', 'risk', 'objectives', 'improvements'];
const ISO = ['wiki', 'quality', 'risk', 'objectives', 'improvements', 'compliance'];
// GRC is ISO plus Leverandøroppfølging.
const GRC = [...ISO, 'supplier'];
const withTender = (modules) => [modules[0], 'tender', ...modules.slice(1)];

const VIEW_ALL = ['wiki.view', 'quality.view', 'risk.view', 'objective.view', 'improvement.view', 'compliance.view', 'supplier.view'];

function shape(activeModules, permissions = VIEW_ALL) {
    const { entries } = railEntries(activeModules, permissions);

    return entries.map((entry) => (entry.children
        ? { [entry.key]: entry.children.map((child) => (child.subAreas ? { [child.key]: child.subAreas.map((area) => area.key) } : child.key)) }
        : entry.key));
}

describe('what each package puts on the rail', () => {
    test('Basis: Wiki, and Styring with Kvalitet and Avvik og forbedringer — no Anbud', () => {
        assert.deepEqual(shape(BASIS), ['home', 'wiki', { governance: ['quality', 'improvements'] }]);
    });

    test('Basis + Anbud: the same, plus Anbud at the top level', () => {
        assert.deepEqual(shape(withTender(BASIS)), ['home', 'wiki', 'tenders', { governance: ['quality', 'improvements'] }]);
    });

    test('Styring: Kvalitet, Risiko, Mål og KPI, Avvik og forbedringer', () => {
        assert.deepEqual(shape(STYRING), ['home', 'wiki', { governance: ['quality', 'risk', 'objectives', 'improvements'] }]);
    });

    test('ISO: everything in Styring, plus Etterlevelse og revisjon with Krav and Revisjoner', () => {
        assert.deepEqual(shape(ISO), [
            'home',
            'wiki',
            { governance: ['quality', 'risk', 'objectives', 'improvements', { compliance: ['compliance-requirements', 'compliance-audits'] }] },
        ]);
    });

    test('ISO + Anbud: the same as ISO, plus Anbud', () => {
        assert.deepEqual(shape(withTender(ISO)), [
            'home',
            'wiki',
            'tenders',
            { governance: ['quality', 'risk', 'objectives', 'improvements', { compliance: ['compliance-requirements', 'compliance-audits'] }] },
        ]);
    });

    test('GRC: everything in ISO, plus Leverandører under Styring', () => {
        assert.deepEqual(shape(GRC), [
            'home',
            'wiki',
            { governance: ['quality', 'risk', 'objectives', 'improvements', { compliance: ['compliance-requirements', 'compliance-audits'] }, 'suppliers'] },
        ]);
        assert.ok(! railEntries(GRC, VIEW_ALL).planned.some((module) => module.key === 'suppliers'));
        // ISO does not carry it, however the person's permissions read.
        assert.ok(! JSON.stringify(shape(ISO)).includes('suppliers'));
    });

    test('GRC + Anbud: the same as GRC, plus Anbud — whose own `suppliers` area stays inside Anbud', () => {
        assert.deepEqual(shape(withTender(GRC)), [
            'home',
            'wiki',
            'tenders',
            { governance: ['quality', 'risk', 'objectives', 'improvements', { compliance: ['compliance-requirements', 'compliance-audits'] }, 'suppliers'] },
        ]);
    });

    test('no package names on the rail — only arbeidsområder and modules', () => {
        for (const modules of [BASIS, STYRING, ISO, withTender(ISO)]) {
            const keys = JSON.stringify(shape(modules));

            for (const packageName of ['basis', 'iso', 'grc', 'core', 'styring']) {
                assert.ok(! keys.includes(`"${packageName}"`), packageName);
            }
        }
    });
});

describe('a module the customer holds is still only shown with its view permission', () => {
    for (const [permission, key] of [
        ['quality.view', 'quality'],
        ['risk.view', 'risk'],
        ['objective.view', 'objectives'],
        ['improvement.view', 'improvements'],
        ['compliance.view', 'compliance'],
        ['wiki.view', 'wiki'],
    ]) {
        test(`without ${permission}, ${key} is not on the rail`, () => {
            const permissions = VIEW_ALL.filter((candidate) => candidate !== permission);

            assert.ok(! JSON.stringify(shape(withTender(ISO), permissions)).includes(`"${key}"`), key);
        });
    }

    test('with no Styring permission at all, Styring is not shown empty', () => {
        assert.deepEqual(shape(withTender(ISO), ['wiki.view']), ['home', 'wiki', 'tenders']);
    });

    test('with only Kvalitet, Styring holds Kvalitet alone', () => {
        assert.deepEqual(shape(ISO, ['wiki.view', 'quality.view']), ['home', 'wiki', { governance: ['quality'] }]);
    });

    test('Krav and Revisjoner are not entitlements of their own: they follow the module', () => {
        assert.ok(! JSON.stringify(shape(ISO, ['quality.view'])).includes('compliance-'));
        assert.ok(! JSON.stringify(shape(STYRING, VIEW_ALL)).includes('compliance-'));
    });

    test('Anbud has no view permission of its own today: the add-on alone decides it', () => {
        assert.ok(shape(withTender(BASIS), []).includes('tenders'));
        assert.ok(! shape(BASIS, VIEW_ALL).includes('tenders'));
    });
});
