import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { SUPPLIER_HELP_PAGES, supplierHelp } from './supplierHelp.js';
import {
    CRITICALITY_TONES,
    SUPPLIER_STATUS_TONES,
    categoryLabel,
    chooseCriticality,
    countLabel,
    criticalityLabel,
    describeCriticalityChange,
    describeCriticalityRegistration,
    describeHistoryEntry,
    describeRegistration,
    emptyCriticality,
    intervalLabel,
    intervalRequired,
    statusLabel,
} from './supplierManagement.js';

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

describe('Criticality is chosen, never computed', () => {
    test('choosing a level fills in its interval and leaves the answers alone', () => {
        const answered = { ...emptyCriticality(), processes_personal_data: true, hard_to_replace: false, review_interval_months: '6' };

        assert.deepEqual(chooseCriticality(answered, 'critical'), { ...answered, criticality: 'critical', review_interval_months: '12' });
        assert.equal(chooseCriticality(answered, 'important').review_interval_months, '24');
        assert.equal(chooseCriticality(answered, 'standard').review_interval_months, '');
        assert.deepEqual([intervalRequired('standard'), intervalRequired('important'), intervalRequired('critical'), intervalRequired('')], [false, true, true, false]);
    });

    test('the interval is asked for only once a level is chosen, and the answers never set one', () => {
        const fields = source('./CriticalityFields.jsx');
        assert.match(fields, /\{level && \(/);
        assert.doesNotMatch(fields, /setData\('criticality'/, 'only chooseCriticality sets the level, on the person\'s click');
        assert.match(source('./Index.jsx'), /reviewIntervals=\{reviewIntervals\}/, 'registering asks for criticality');
        assert.doesNotMatch(source('./Show.jsx'), /<SupplierForm[^>]*reviewIntervals/s, 'Rediger must not change criticality');
    });

    test('levels, intervals and «Ikke vurdert» read in the domain language', () => {
        assert.deepEqual(['standard', 'important', 'critical'].map((level) => criticalityLabel(level)), ['Standard', 'Viktig', 'Kritisk']);
        assert.equal(new Set(Object.values(CRITICALITY_TONES)).size, 3);
        assert.deepEqual([intervalLabel(12), intervalLabel(null)], ['Hver 12. måned', 'Ingen fast vurdering']);
        assert.match(source('./SupplierCriticalityBadge.jsx'), /tr\.not_classified \?\? 'Ikke vurdert'/);
    });

    test('a history entry says how the level moved, and the registration closes the list', () => {
        const entry = (from, to) => describeCriticalityChange({
            from: from ? { criticality: from } : null,
            to: { criticality: to },
            changed_by_name: 'Kari',
        });

        assert.equal(entry(null, 'standard'), 'Vurdert som Standard av Kari');
        assert.equal(entry('standard', 'important'), 'Endret fra Standard til Viktig av Kari');
        assert.equal(entry('critical', 'critical'), 'Fortsatt Kritisk – endret av Kari');
        assert.equal(
            describeCriticalityRegistration({ classification: { criticality: 'important' }, by_name: null }),
            'Vurdert som Viktig ved registrering av en tidligere bruker',
        );
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
