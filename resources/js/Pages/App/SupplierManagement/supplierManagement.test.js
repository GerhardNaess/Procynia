import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { SUPPLIER_HELP_PAGES, supplierHelp } from './supplierHelp.js';
import { SUPPLIER_STATUS_TONES, categoryLabel, countLabel, describeHistoryEntry, describeRegistration, statusLabel } from './supplierManagement.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const PAGE_FILES = { index: './Index.jsx', supplier: './Show.jsx' };

describe('Every Leverandører page carries the shared PageHelp', () => {
    test('each help page is rendered by its page through PageHelpButton', () => {
        assert.deepEqual(Object.keys(PAGE_FILES), SUPPLIER_HELP_PAGES);

        for (const [page, file] of Object.entries(PAGE_FILES)) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.supplierHelp\\(\\w+, '${page}'\\)\\} />`), file);
        }
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = supplierHelp({}, 'supplier');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});

describe('No text below 16 px in the module', () => {
    test('no component uses text-xs or text-sm', () => {
        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(source(`./${file}`), /\btext-(xs|sm)\b/, file);
        }
    });
});

describe('Status is never a form field', () => {
    test('the shared form offers the in-use choice only when registering, and no status select', () => {
        const form = source('./SupplierForm.jsx');
        assert.doesNotMatch(form, /setData\('status'/);
        assert.match(form, /\{initialStatuses && \(/);
        assert.doesNotMatch(source('./Show.jsx'), /initialStatuses=/, 'Rediger must not offer a status choice');
    });
});

describe('supplierManagement', () => {
    test('every status has its own badge tone and a Norwegian fallback', () => {
        assert.deepEqual(Object.keys(SUPPLIER_STATUS_TONES), ['onboarding', 'active', 'ended']);
        assert.equal(new Set(Object.values(SUPPLIER_STATUS_TONES)).size, 3);
        assert.deepEqual(['onboarding', 'active', 'ended'].map((status) => statusLabel(status)), ['Under vurdering', 'Aktiv', 'Avsluttet']);
        assert.equal(statusLabel('active', { statuses: { active: 'Active' } }), 'Active');
        assert.equal(categoryLabel('it_cloud'), 'IT og skytjenester');
    });

    test('a history entry names the action, telling an activation from a reopening', () => {
        const entry = (from, to, name = 'Kari') => describeHistoryEntry({ from_status: from, to_status: to, changed_by_name: name });

        assert.equal(entry('onboarding', 'active'), 'Tatt i bruk av Kari');
        assert.equal(entry('ended', 'active'), 'Gjenåpnet av Kari');
        assert.equal(entry('active', 'ended'), 'Avsluttet av Kari');
        assert.equal(entry('onboarding', 'ended', null), 'Avsluttet av en tidligere bruker');
        assert.equal(describeRegistration({ status: 'onboarding', by_name: 'Ola' }), 'Registrert som Under vurdering av Ola');
    });

    test('the count reads in the singular for one', () => {
        assert.equal(countLabel(1), '1 leverandør');
        assert.equal(countLabel(3), '3 leverandører');
    });
});
