import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { COMPLIANCE_HELP_PAGES, complianceHelp } from './complianceHelp.js';
import { LINK_FILTER_THRESHOLD, QUALITY_STATUS_TONES, controlFacts, evidenceAddedLabel, filterQualityOptions, qualityItemLabel, qualityOptionLabel } from './complianceQuality.js';
import { COMPLIANCE_STATUS_TONES, REQUIREMENT_STATUS_TONES, complianceStatusLabel, countLabel, describeHistoryEntry, registerCompliance, reviewIntervalLabel, sourceKindLabel } from './complianceRequirement.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const PAGE_FILES = { index: './Index.jsx', requirement: './Show.jsx' };

describe('Every Krav page carries the shared PageHelp', () => {
    test('each help page is rendered by its page through PageHelpButton', () => {
        assert.deepEqual(Object.keys(PAGE_FILES), COMPLIANCE_HELP_PAGES);

        for (const [page, file] of Object.entries(PAGE_FILES)) {
            const code = source(file);
            assert.match(code, /import PageHelpButton from '\.\.\/\.\.\/\.\.\/\.\.\/Components\/App\/PageHelpButton'/, file);
            assert.match(code, new RegExp(`<PageHelpButton \\{\\.\\.\\.complianceHelp\\(\\w+, '${page}'\\)\\} />`), file);
            assert.ok(! code.includes('PageHelpPanel'), `${file} must not roll its own help panel`);
        }
    });
});

describe('No text below 16 px in the module', () => {
    test('no component uses text-xs or text-sm', () => {
        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(source(`./${file}`), /\btext-(xs|sm)\b/, file);
        }
    });
});

describe('complianceHelp', () => {
    test('passes the page\'s sections from the translations through unchanged', () => {
        const sections = [{ title: 'Status', items: [{ title: 'Utgått', text: 'Gjelder ikke lenger.' }] }];
        const help = complianceHelp({ help: { button: 'Hjelp', requirement: { title: 'Om kravet', intro: 'Intro', sections } } }, 'requirement');

        assert.deepEqual(help, { buttonLabel: 'Hjelp', title: 'Om kravet', intro: 'Intro', sections });
    });

    test('falls back to an empty panel rather than breaking the page', () => {
        const help = complianceHelp({}, 'index');

        assert.equal(help.buttonLabel, 'Hjelp');
        assert.deepEqual(help.sections, []);
    });
});

describe('complianceRequirement', () => {
    test('every status has a badge tone, and the two differ', () => {
        assert.deepEqual(Object.keys(REQUIREMENT_STATUS_TONES), ['active', 'retired']);
        assert.notEqual(REQUIREMENT_STATUS_TONES.active, REQUIREMENT_STATUS_TONES.retired);
    });

    test('the review interval reads as a word, with none as «Ingen fast intervall»', () => {
        assert.equal(reviewIntervalLabel(null), 'Ingen fast intervall');
        assert.equal(reviewIntervalLabel(''), 'Ingen fast intervall');
        assert.equal(reviewIntervalLabel(1), 'Månedlig');
        assert.equal(reviewIntervalLabel(3), 'Kvartalsvis');
        assert.equal(reviewIntervalLabel(6), 'Halvårlig');
        assert.equal(reviewIntervalLabel(12), 'Årlig');
        assert.equal(reviewIntervalLabel(12, { review_intervals: { 12: 'Yearly' } }), 'Yearly');
    });

    test('a source kind reads as its label', () => {
        assert.equal(sourceKindLabel('law'), 'Lov/forskrift');
        assert.equal(sourceKindLabel('internal'), 'Internt krav');
        assert.equal(sourceKindLabel('contract', { kinds: { contract: 'Contract' } }), 'Contract');
    });

    test('a history entry reads as a sentence, with a former user when the author is gone', () => {
        assert.equal(describeHistoryEntry({ to_status: 'retired', changed_by_name: 'Kari' }), 'Satt som utgått av Kari');
        assert.equal(describeHistoryEntry({ to_status: 'active', changed_by_name: null }), 'Gjenåpnet av en tidligere bruker');
        assert.equal(describeHistoryEntry({ to_status: 'retired', changed_by_name: 'Ola' }, { history: { retired: 'Retired by :name' } }), 'Retired by Ola');
    });

    test('a count is singular for one', () => {
        assert.equal(countLabel(1, '1 krav', ':count krav'), '1 krav');
        assert.equal(countLabel(0, '1 krav', ':count krav'), '0 krav');
        assert.equal(countLabel(12, '1 requirement', ':count requirements'), '12 requirements');
    });
});

describe('Etterlevelse in the register and on the page', () => {
    test('every compliance status has a label and a tone, and the four results differ', () => {
        assert.deepEqual(Object.keys(COMPLIANCE_STATUS_TONES), ['compliant', 'partially_compliant', 'non_compliant', 'not_applicable', 'not_assessed']);
        assert.equal(new Set(['compliant', 'partially_compliant', 'non_compliant'].map((status) => COMPLIANCE_STATUS_TONES[status])).size, 3);
        assert.deepEqual(
            Object.keys(COMPLIANCE_STATUS_TONES).map((status) => complianceStatusLabel(status)),
            ['Oppfylt', 'Delvis oppfylt', 'Ikke oppfylt', 'Ikke relevant', 'Ikke vurdert'],
        );
        assert.equal(complianceStatusLabel('compliant', { assessment: { results: { compliant: 'Compliant' } } }), 'Compliant');
    });

    test('an active requirement shows its result, and «Revurdering forfalt» beside it, never instead', () => {
        assert.deepEqual(registerCompliance({ status: 'compliant', is_overdue: true }, 'active'), { kind: 'current', label: 'Oppfylt', tone: 'emerald', overdue: true });
        assert.deepEqual(registerCompliance({ status: 'not_assessed', is_overdue: false }, 'active'), { kind: 'current', label: 'Ikke vurdert', tone: 'sky', overdue: false });
        assert.equal(registerCompliance(undefined, 'active').label, 'Ikke vurdert');
    });

    test('a retired requirement shows its last result only as history, and is never overdue', () => {
        assert.deepEqual(registerCompliance({ status: 'compliant', is_overdue: true }, 'retired'), { kind: 'historic', label: 'Siste vurdering: Oppfylt', tone: 'slate', overdue: false });
        assert.equal(registerCompliance({ status: 'not_assessed' }, 'retired').kind, 'none');
        assert.equal(registerCompliance({ status: 'non_compliant' }, 'retired', { assessment: { historic: 'Latest assessment: :result', results: { non_compliant: 'Non-compliant' } } }).label, 'Latest assessment: Non-compliant');
    });

    test('the assessment form asks for a result and a rationale — never a date', () => {
        const code = source('./RequirementCompliance.jsx');

        assert.match(code, /id=\{`compliance-assessment-result-\$\{value\}`\}/);
        assert.match(code, /id="compliance-assessment-rationale"/);
        assert.match(code, /useForm\(\{ result: '', rationale: '' \}\)/);
        assert.doesNotMatch(code, /assessed_at'|type="date"|type="datetime-local"/);
    });

    test('the history offers the requirement as it was', () => {
        const code = source('./RequirementCompliance.jsx');

        assert.match(code, /<details/);
        assert.match(code, /snapshot\.requirement_text/);
    });

    test('the register carries the column on the table and on the phone cards alike', () => {
        assert.equal(source('./Index.jsx').match(/<ComplianceCell /g).length, 2);
    });
});

describe('The requirement form and page say what a requirement is, and whether it is met only through assessments', () => {
    test('the form asks for source, reference, title, text, owner and interval — never a status', () => {
        const code = source('./RequirementForm.jsx');
        const ids = [...code.matchAll(/id="(compliance-requirement-[a-z-]+)"/g)].map((match) => match[1]).filter((id) => ! id.endsWith('-hint'));

        assert.deepEqual(ids, [
            'compliance-requirement-source',
            'compliance-requirement-reference',
            'compliance-requirement-title',
            'compliance-requirement-text',
            'compliance-requirement-owner',
            'compliance-requirement-review',
        ]);
        assert.doesNotMatch(code, /setData\('status'|data\.status/);
    });

    test('the requirement page reads «what is required → how we do it → is it met»: text, source and ownership, Hvordan kravet oppfylles, Etterlevelse, status and history', () => {
        const code = source('./Show.jsx');
        const order = ['compliance-text-heading', 'compliance-details-heading', '<RequirementQualityContext', '<RequirementCompliance', 'compliance-status-heading', '<RequirementHistory'].map((needle) => code.indexOf(needle));

        assert.ok(order.every((index) => index > -1));
        for (let i = 1; i < order.length; i += 1) {
            assert.ok(order[i] > order[i - 1], `section ${i} is out of order`);
        }
    });

    test('no empty sections for what is not built yet', () => {
        // Code only: the doc comments may name what comes later.
        const code = (file) => source(file).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(code(`./${file}`), /audit|Revisjoner|Funn/, file);
        }
    });

    test('sources are managed from the register, not a page of their own', () => {
        assert.match(source('./Index.jsx'), /<ComplianceSources/);
        assert.ok(! readdirSync(here).includes('Sources.jsx'));
    });
});

describe('Hvordan kravet oppfylles', () => {
    const code = () => source('./RequirementQualityContext.jsx');

    test('the section is drawn only when the server sent the Kvalitet context — never an empty or counted placeholder', () => {
        assert.match(source('./Show.jsx'), /\{qualityContext && \(\s*<RequirementQualityContext/);
        assert.match(source('./Show.jsx'), /quality_context: qualityContext = null/);
        assert.doesNotMatch(code(), /hidden_count|hiddenCount|skjult/i);
    });

    test('every link and unlink action waits for the server\'s permission', () => {
        assert.match(source('./Show.jsx'), /canManage=\{Boolean\(permissions\.can_manage_quality_links\)\}/);
        // Add process, add control, unlink process, unlink control.
        assert.equal(code().match(/canManage && /g).length, 4);
        assert.match(code(), /`\/app\/compliance\/requirements\/\$\{requirementId\}\/\$\{kind === 'process' \? 'processes' : 'controls'\}`/);
    });

    test('a retired requirement says its links are read-only', () => {
        assert.match(code(), /\{! active && \(\s*<p[^>]*>\{tq\.retired_requirement_note/);
        assert.match(code(), /control\.status === 'retired'/);
    });

    test('evidence is shown, never written, and never turned into a compliance result', () => {
        assert.doesNotMatch(code(), /type="file"|\/evidence`|compliant|assessment/);
        assert.match(code(), /tq\.evidence_note/);
    });

    test('controls and evidence stack as cards, so they read on a phone', () => {
        assert.doesNotMatch(code(), /<table/);
        assert.match(code(), /data-testid="compliance-evidence"/);
        assert.match(code(), /break-words/);
    });

    test('labels and options name the item, and a control by where it sits in Kvalitet', () => {
        assert.equal(qualityItemLabel({ code: 'K-01', title: 'Logg' }), 'K-01 · Logg');
        assert.equal(qualityItemLabel({ code: null, title: 'Logg' }), 'Logg');
        assert.equal(qualityOptionLabel({ title: 'Logg', placements: ['Drift › Overvåk'] }), 'Logg — Drift › Overvåk');
        assert.equal(qualityOptionLabel({ title: 'Prosess' }), 'Prosess');
        assert.equal(LINK_FILTER_THRESHOLD, 6);
        assert.equal(QUALITY_STATUS_TONES.retired, 'slate');
    });

    test('the search matches every word in code, title or placement, and keeps the chosen one', () => {
        const options = [
            { id: 1, code: 'K-01', title: 'Logggjennomgang', placements: ['Drift › Overvåk'] },
            { id: 2, code: null, title: 'Tilgangsgjennomgang', placements: [] },
        ];

        assert.deepEqual(filterQualityOptions(options, 'drift logg').map((o) => o.id), [1]);
        assert.deepEqual(filterQualityOptions(options, 'TILGANG').map((o) => o.id), [2]);
        assert.deepEqual(filterQualityOptions(options, 'tilgang', '1').map((o) => o.id), [1, 2]);
        assert.equal(filterQualityOptions(options, '  ').length, 2);
    });

    test('a control shows only the fields Kvalitet has filled in, with frequency as Kvalitet names it', () => {
        const facts = controlFacts({ criterion: 'Alt godkjent', method: '', frequency: 'monthly', responsibility: null }, {}, { monthly: 'Månedlig' });

        assert.deepEqual(facts, [
            { key: 'criterion', label: 'Hva kontrolleres', value: 'Alt godkjent' },
            { key: 'frequency', label: 'Frekvens', value: 'Månedlig' },
        ]);
        assert.deepEqual(controlFacts({}, {}), []);
    });

    test('evidence says when and by whom it was added, without a name when the person is gone', () => {
        assert.equal(evidenceAddedLabel({ added_at: '2026-10-05', added_by: 'Kari' }), 'Lagt til 05.10.2026 av Kari');
        assert.equal(evidenceAddedLabel({ added_at: '2026-10-05', added_by: null }), 'Lagt til 05.10.2026');
        assert.equal(evidenceAddedLabel({ added_at: null }), '');
        assert.equal(evidenceAddedLabel({ added_at: '2026-10-05', added_by: 'Ola' }, { evidence_added: 'Added :date by :name' }), 'Added 05.10.2026 by Ola');
    });
});
