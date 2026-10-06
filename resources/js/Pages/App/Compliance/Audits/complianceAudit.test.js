import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { COMPLIANCE_HELP_PAGES, complianceHelp } from '../Requirements/complianceHelp.js';
import {
    AUDIT_STATUS_TONES,
    auditStatusLabel,
    auditTypeLabel,
    describeAuditHistoryEntry,
    filterRequirementOptions,
    lockedNotice,
    plannedPeriodLabel,
    requirementLabel,
} from './complianceAudit.js';

const here = fileURLToPath(new URL('.', import.meta.url));
const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');
const layout = source('../../../../Layouts/CustomerAppLayout.jsx');
const lang = (locale) => readFileSync(new URL(`../../../../../../lang/${locale}/procynia.php`, import.meta.url), 'utf8');

const block = (text, start, end) => {
    const from = text.indexOf(start);
    assert.ok(from > -1, `${start} must exist`);

    return text.slice(from, text.indexOf(end, from));
};

describe('Etterlevelse og revisjon has two Level 2 areas: Krav | Revisjoner', () => {
    test('exactly Krav and Revisjoner, in that order, with their own routes', () => {
        const areas = block(layout, "if (activeMainArea === 'compliance') {\n            // Etterlevelse", '];');
        const keys = [...areas.matchAll(/key: '([^']+)'/g)].map((m) => m[1]);
        const hrefs = [...areas.matchAll(/href: '([^']+)'/g)].map((m) => m[1]);

        assert.deepEqual(keys, ['compliance-requirements', 'compliance-audits']);
        assert.deepEqual(hrefs, ['/app/compliance/requirements', '/app/compliance/audits']);
    });

    test('no Oversikt, dashboard or third level is invented for the module', () => {
        const items = block(layout, "if (activeMainArea === 'compliance') {\n            // Etterlevelse", '];').split('return [')[1];

        for (const word of ['overview', 'dashboard', 'Oversikt', 'findings']) {
            assert.ok(! items.includes(word), `${word} must not be an area`);
        }
    });

    test('an audit page lights Revisjoner, everything else in the module lights Krav', () => {
        const resolver = block(layout, "if (activeMainArea === 'compliance') {\n            return pathname", '}');

        assert.match(resolver, /pathname\.startsWith\('\/app\/compliance\/audits'\) \? 'compliance-audits' : 'compliance-requirements'/);
    });

    test('the pages do not draw their own copy of the areas', () => {
        for (const file of ['./Index.jsx', './Show.jsx', '../Requirements/Index.jsx', '../Requirements/Show.jsx']) {
            const code = source(file);
            assert.ok(! code.includes('<nav'), `${file} must not render its own navigation`);
            assert.ok(! code.includes("href=\"/app/compliance/audits\" className=\"rounded"), file);
        }
    });
});

describe('PageHelp on both audit pages', () => {
    test('the register and the audit page are help pages, rendered through PageHelpButton', () => {
        assert.deepEqual(COMPLIANCE_HELP_PAGES.slice(2), ['audit_index', 'audit']);
        assert.match(source('./Index.jsx'), /<PageHelpButton \{\.\.\.complianceHelp\(tr, 'audit_index'\)\} \/>/);
        assert.match(source('./Show.jsx'), /<PageHelpButton \{\.\.\.complianceHelp\(tr, 'audit'\)\} \/>/);
    });

    test('the help is read from translations.compliance.help', () => {
        const sections = [{ title: 'Scope', items: [{ title: 'Scope', text: 'Hva revisjonen omfatter.' }] }];
        const help = complianceHelp({ help: { button: 'Hjelp', audit: { title: 'Om revisjonen', intro: 'Intro', sections } } }, 'audit');

        assert.deepEqual(help, { buttonLabel: 'Hjelp', title: 'Om revisjonen', intro: 'Intro', sections });
    });

    test('both languages declare the same audit help pages and audit keys', () => {
        for (const locale of ['no', 'en']) {
            const text = lang(locale);
            assert.match(text, /'audit_index' => \[/, locale);
            assert.match(text, /'audit' => \[\n\s+'title' =>/, locale);
            assert.match(text, /'audits' => \[\n\s+'nav' =>/, locale);
        }
    });
});

describe('No text below 16 px in the audit pages', () => {
    test('no component uses text-xs or text-sm', () => {
        for (const file of readdirSync(here).filter((name) => name.endsWith('.jsx'))) {
            assert.doesNotMatch(source(`./${file}`), /\btext-(xs|sm)\b/, file);
        }
    });
});

describe('The register', () => {
    const index = source('./Index.jsx');

    test('shows title, type, responsible, planned period and status — on the table and on the phone cards', () => {
        const table = block(index, 'data-testid="compliance-audit-table"', '</table>');
        const cards = block(index, 'data-testid="compliance-audit-list"', '</ul>');

        for (const column of ['col_title', 'col_type', 'col_responsible', 'col_period', 'col_status']) {
            assert.ok(table.includes(column), `table: ${column}`);
        }
        for (const helper of ['auditTypeLabel', 'auditStatusLabel', 'plannedPeriodLabel', '<Responsible']) {
            assert.ok(cards.includes(helper), `cards: ${helper}`);
            assert.ok(table.includes(helper), `table: ${helper}`);
        }
    });

    test('phones get cards, wider screens the table — never both, never sideways scrolling', () => {
        assert.match(index, /className="mt-4 divide-y divide-slate-100 md:hidden" data-testid="compliance-audit-list"/);
        assert.match(index, /className="mt-4 hidden overflow-x-auto md:block"/);
    });

    test('search, status and type are the filters, and Nullstill clears all three', () => {
        assert.match(index, /router\.get\('\/app\/compliance\/audits', \{\n\s+search: search \|\| undefined,\n\s+status: status \|\| undefined,\n\s+type: type \|\| undefined,/);
        assert.match(index, /setSearch\(''\);\n\s+setStatus\(''\);\n\s+setType\(''\);/);
    });

    test('Ny revisjon only with compliance.audit, and no status in the form', () => {
        assert.match(index, /\{canAudit && ! creating && \(/);
        const form = source('./AuditForm.jsx');
        assert.ok(! /setData\('status'/.test(form), 'status is never a form field');
        for (const field of ['title', 'audit_type', 'responsible_user_id', 'auditor_name', 'planned_start_date', 'planned_end_date', 'scope_description']) {
            assert.ok(form.includes(`setData('${field}'`), field);
        }
    });

    test('no dashboard, chart or attention in the register yet', () => {
        for (const word of ['Chart', 'attention', 'overdue', 'findings']) {
            assert.ok(! index.includes(word), word);
        }
    });
});

describe('The audit page', () => {
    const show = source('./Show.jsx');

    test('reads top to bottom: status, information, scope, requirements, processes, conclusion, history', () => {
        const order = ['data-testid="compliance-audit-status"', 'compliance-audit-info-heading', 'compliance-audit-scope-heading', '<AuditRequirementScope', '<AuditProcessScope', 'compliance-audit-conclusion-heading', '<AuditHistory'];
        const positions = order.map((needle) => show.indexOf(needle));

        positions.forEach((position, i) => assert.ok(position > -1, `${order[i]} must exist`));
        assert.deepEqual([...positions].sort((a, b) => a - b), positions);
    });

    test('every lifecycle action waits for the server\'s permission', () => {
        for (const [permission, action] of [['can_start', 'start'], ['can_complete', 'complete'], ['can_reopen', 'reopen'], ['can_cancel', 'cancel']]) {
            assert.match(show, new RegExp(`\\{permissions\\.${permission} && \\(\\n\\s+<button type="button" onClick=\\{\\(\\) => setPanel\\('${action}'\\)\\}`), permission);
        }
        assert.match(show, /\{permissions\.can_edit && panel === null && \(/);
        assert.match(show, /\{permissions\.can_delete && \(/);
    });

    test('Rediger draws only the fields the server left open', () => {
        assert.match(show, /fields=\{editableFields\}/);
        assert.match(source('./AuditForm.jsx'), /const has = \(field\) => fields\.includes\(field\);/);
    });

    test('a completed audit says it is locked and how to change it; a cancelled one that it is read-only', () => {
        assert.equal(lockedNotice('completed'), 'Revisjonen er fullført og låst. Gjenåpne den for å gjøre endringer.');
        assert.equal(lockedNotice('cancelled'), 'Revisjonen er avbrutt og kan ikke endres.');
        assert.equal(lockedNotice('planned'), null);
        assert.equal(lockedNotice('in_progress'), null);
        assert.match(show, /\{locked && \(/);
    });

    test('Fullfør asks for the conclusion, prefilled with what was saved underway', () => {
        const forms = source('./AuditLifecycleForms.jsx');
        assert.match(forms, /useForm\(\{ conclusion: audit\.conclusion \?\? '' \}\)/);
        assert.match(forms, /\/app\/compliance\/audits\/\$\{audit\.id\}\/complete/);
    });

    test('Avbryt and Gjenåpne require a reason, Start does not', () => {
        const forms = source('./AuditLifecycleForms.jsx');
        assert.match(block(forms, 'export function AuditStartForm', '\n}\n'), /required=\{false\}/);
        assert.match(block(forms, 'export function AuditCancelForm', '\n}\n'), /\n\s+required\n/);
        assert.match(block(forms, 'export function AuditReopenForm', '\n}\n'), /\n\s+required\n/);
    });

    test('the processes are drawn only when the server sent them — never an empty or counted placeholder', () => {
        assert.match(show, /\{processes !== null && \(\n\s+<AuditProcessScope/);
        assert.ok(! /processes\?\.length|processes\.length/.test(show));
    });

    test('adding and removing scope waits for the server\'s permission', () => {
        const scope = source('./AuditScope.jsx');
        assert.match(show, /canManage=\{Boolean\(permissions\.can_manage_requirements\)\}/);
        assert.match(show, /canManage=\{Boolean\(permissions\.can_manage_processes\)\}/);
        assert.equal((scope.match(/\{canManage && ! adding && \(/g) ?? []).length, 2);
        assert.equal((scope.match(/\{canManage && \(\n\s+<button type="button" onClick=\{\(\) => remove/g) ?? []).length, 2);
    });

    test('a retired requirement in scope says so, in the list and in the picker', () => {
        const scope = source('./AuditScope.jsx');
        assert.equal((scope.match(/status === 'retired' && /g) ?? []).length, 3);
    });

    test('scope lists stack as rows that wrap, so they read on a phone', () => {
        const scope = source('./AuditScope.jsx');
        assert.ok((scope.match(/flex flex-wrap items-start justify-between gap-3 py-3/g) ?? []).length >= 2);
        assert.ok(scope.includes('break-words'));
    });
});

describe('complianceAudit helpers', () => {
    test('four statuses, each with a label and a tone of its own', () => {
        assert.deepEqual(Object.keys(AUDIT_STATUS_TONES), ['planned', 'in_progress', 'completed', 'cancelled']);
        assert.equal(new Set(Object.values(AUDIT_STATUS_TONES)).size, 4);
        assert.equal(auditStatusLabel('in_progress'), 'Under arbeid');
        assert.equal(auditStatusLabel('completed', { statuses: { completed: 'Completed' } }), 'Completed');
    });

    test('the type reads as Intern or Ekstern', () => {
        assert.equal(auditTypeLabel('internal'), 'Intern');
        assert.equal(auditTypeLabel('external'), 'Ekstern');
        assert.equal(auditTypeLabel('external', { types: { external: 'External' } }), 'External');
    });

    test('the planned period, with or without a start', () => {
        assert.equal(plannedPeriodLabel({ planned_start_date: '2026-11-01', planned_end_date: '2026-11-15' }), '01.11.2026–15.11.2026');
        assert.equal(plannedPeriodLabel({ planned_start_date: null, planned_end_date: '2026-11-15' }), 'Til 15.11.2026');
        assert.equal(plannedPeriodLabel({ planned_start_date: null, planned_end_date: '2026-11-15' }, { period_until: 'Until :date' }), 'Until 15.11.2026');
    });

    test('a history entry reads as a sentence, and Gjenåpnet is told apart from Startet', () => {
        assert.equal(describeAuditHistoryEntry({ from_status: 'planned', to_status: 'in_progress', changed_by_name: 'Kari' }), 'Startet av Kari');
        assert.equal(describeAuditHistoryEntry({ from_status: 'completed', to_status: 'in_progress', changed_by_name: 'Kari' }), 'Gjenåpnet av Kari');
        assert.equal(describeAuditHistoryEntry({ from_status: 'in_progress', to_status: 'completed', changed_by_name: null }), 'Fullført av en tidligere bruker');
        assert.equal(describeAuditHistoryEntry({ from_status: 'planned', to_status: 'cancelled', changed_by_name: 'Ola' }, { history: { planned_cancelled: 'Cancelled by :name' } }), 'Cancelled by Ola');
    });

    test('the requirement search matches every word, and keeps what is already ticked', () => {
        const options = [
            { id: 1, reference: 'A.5.15', title: 'Tilgangsstyring', source_label: 'ISO 27001 (2022)' },
            { id: 2, reference: '§ 30', title: 'Behandlingsprotokoll', source_label: 'Personopplysningsloven' },
            { id: 3, reference: null, title: 'Internt krav om tilgang', source_label: 'Interne krav' },
        ];

        assert.deepEqual(filterRequirementOptions(options, '').map((o) => o.id), [1, 2, 3]);
        assert.deepEqual(filterRequirementOptions(options, 'tilgang').map((o) => o.id), [1, 3]);
        assert.deepEqual(filterRequirementOptions(options, 'iso tilgang').map((o) => o.id), [1]);
        assert.deepEqual(filterRequirementOptions(options, 'iso tilgang', [2]).map((o) => o.id), [1, 2]);
        assert.equal(requirementLabel(options[0]), 'A.5.15 Tilgangsstyring');
        assert.equal(requirementLabel(options[2]), 'Internt krav om tilgang');
    });
});
