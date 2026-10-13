import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { appliesToText, groupByTheme, requirementFormData, rowActions, statusChangeByline, statusChangeText, toggleCode } from './controlRequirements.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const tr = {
    control: {
        themes: { privacy: 'Personvern', environment: 'Klima og miljø' },
        catalogue: { applies_to_none: 'Gjelder ingen leverandører nå', applies_to_one: 'Gjelder 1 leverandør nå', applies_to_many: 'Gjelder :count leverandører nå' },
    },
};

describe('Krav og kvalifikasjoner (supplier-assurance-v2-plan §5.2, §5.5)', () => {
    test('rows keep the server order and are grouped by theme', () => {
        const rows = [
            { id: 1, theme: 'privacy', title: 'Databehandleravtale' },
            { id: 2, theme: 'environment', title: 'Miljøkrav' },
            { id: 3, theme: 'privacy', title: 'Underdatabehandlere' },
        ];

        assert.deepEqual(groupByTheme(rows, tr).map((group) => [group.label, group.rows.map((row) => row.id)]), [
            ['Personvern', [1, 3]],
            ['Klima og miljø', [2]],
        ]);
        assert.deepEqual(groupByTheme(null, tr), []);
    });

    test('override actions follow the server, and a mandatory requirement is never offered exclusion', () => {
        assert.deepEqual(rowActions({ level: 'important', can_exclude: true, can_clear: false }), { exclude: true, clear: false });
        assert.deepEqual(rowActions({ level: 'mandatory', can_exclude: true, can_clear: true }), { exclude: false, clear: true });
        assert.deepEqual(rowActions({ level: 'standard', can_exclude: true, supplier_specific: true }), { exclude: false, clear: false });
        // Without supplier.assure the server sends false for both, and nothing is offered.
        assert.deepEqual(rowActions({ level: 'standard', can_exclude: false, can_clear: false }), { exclude: false, clear: false });
    });

    test('the section shows the server’s reason and never a predicate or a control result', () => {
        const code = source('./SupplierControlRequirements.jsx');

        assert.match(code, /reason\.text/);
        assert.match(code, /data-testid="requirement-reason"/);
        assert.match(code, /rowActions\(row\)/);
        assert.doesNotMatch(code, /applies_when|predicate|score|percent/i);
        // The only status in phase 2 is «Ikke vurdert».
        assert.doesNotMatch(code, /documented|missing|temporarily_accepted|decision/);
        // An override always carries a begrunnelse.
        assert.match(code, /name="?reason|'reason'|data\.reason/);
    });

    test('one «Legg til krav» opens two choices; this supplier’s own requirement is edited and retired on its row, found again and used again, never deleted', () => {
        const code = source('./SupplierControlRequirements.jsx');

        // One main action, not two side by side; the choices are the existing include and own-requirement flows.
        assert.equal((code.match(/data-testid="add-requirement"/g) ?? []).length, 1);
        assert.match(code, /onClick=\{\(\) => setPanel\(\{ kind: 'add' \}\)\}/);
        assert.match(code, /onChoose\('include'\)[\s\S]*disabled=\{noneToInclude\}/);
        assert.match(code, /onChoose\('own'\)/);
        assert.doesNotMatch(code, /add_for_supplier \?\?/, 'the old second button is gone');
        assert.match(code, /panel\?\.kind === 'include' && <OverrideForm supplierId=\{supplierId\} action="include"/);
        assert.match(code, /<ControlRequirementForm supplierId=\{supplierId\}/);

        // Rediger krav / Sett som utgått only where the server says so, the retire behind a confirm; no Slett.
        assert.match(code, /row\.can_manage && <button type="button" onClick=\{\(\) => setPanel\(\{ kind: 'edit', requirement: row \}\)\}/);
        assert.match(code, /row\.can_manage && <button type="button" onClick=\{\(\) => retire\(row\)\}/);
        assert.match(code, /window\.confirm\([\s\S]*control-requirements\/\$\{row\.id\}\/\$\{action\}/);
        assert.match(code, /changeStatus\(row, 'retire', c\.retire_own_confirm/);

        // Utgåtte kontrollkrav: their own folded section, never among the ones that apply; Ta i bruk igjen
        // only where the server says so, behind the same confirm.
        assert.match(code, /retiredOwn\.length > 0 && \(\s*<details [^>]*data-testid="control-retired-own"/);
        assert.doesNotMatch(code, /<details open[^>]*control-retired-own/);
        assert.match(code, /row\.can_reactivate && \(\s*<button type="button" onClick=\{\(\) => reactivate\(row\)\}/);
        assert.match(code, /changeStatus\(row, 'reactivate', c\.reactivate_own_confirm/);
        assert.doesNotMatch(code, /router\.delete|\/destroy|k\.delete/);
        assert.match(code, /<ControlRequirementForm requirement=\{row\}/);
    });
});

describe('Kontrollkrav (supplier-assurance-v2-plan §5.1, §5.3)', () => {
    test('counts are counts, not shares', () => {
        assert.equal(appliesToText(0, tr), 'Gjelder ingen leverandører nå');
        assert.equal(appliesToText(1, tr), 'Gjelder 1 leverandør nå');
        assert.equal(appliesToText(4, tr), 'Gjelder 4 leverandører nå');
    });

    test('the form starts empty or from the catalogue row, rule included', () => {
        assert.equal(requirementFormData().rule_mode, 'all');
        const data = requirementFormData({
            title: 'DBA', theme: 'privacy', level: 'mandatory', control_point: 'before_contract', control_interval_months: 24,
            accepted_document_types: ['data_processing_agreement'], anchor: { id: 9 },
            rule: { rule_mode: 'conditions', conditions: ['processor'], criticality_scope: 'important' },
        });

        assert.deepEqual([data.control_interval_months, data.compliance_requirement_id, data.rule_mode, data.conditions, data.criticality_scope], ['24', '9', 'conditions', ['processor'], 'important']);
    });

    test('conditions are capped at the rule’s maximum', () => {
        assert.deepEqual(toggleCode(['a'], 'b', 2), ['a', 'b']);
        assert.deepEqual(toggleCode(['a', 'b'], 'c', 2), ['a', 'b']);
        assert.deepEqual(toggleCode(['a', 'b'], 'a', 2), ['b']);
    });

    test('the anchor is only offered when the server sent options', () => {
        assert.match(source('./ControlRequirementForm.jsx'), /anchorOptions !== null &&/);
    });
});

describe('Endringshistorikk on a requirement', () => {
    const history = { control: { status_history: { retired: 'Satt som utgått', reactivated: 'Tatt i bruk igjen' } }, unknown_user: 'en tidligere bruker' };

    test('says what each change did in words, never the status codes', () => {
        assert.equal(statusChangeText({ from_status: 'active', to_status: 'retired' }, history), 'Satt som utgått');
        assert.equal(statusChangeText({ from_status: 'retired', to_status: 'active' }, history), 'Tatt i bruk igjen');
        assert.equal(statusChangeText({ to_status: 'retired' }, {}), 'Satt som utgått');
        assert.equal(statusChangeText({ to_status: 'active' }, {}), 'Tatt i bruk igjen');
    });

    test('says when and by whom, with a stand-in for a deleted user', () => {
        const format = (iso) => `[${iso}]`;
        assert.equal(statusChangeByline({ changed_at: '2026-10-13T09:30:00Z', changed_by_name: 'Kari' }, history, format), '[2026-10-13T09:30:00Z] – Kari');
        assert.equal(statusChangeByline({ changed_at: '2026-10-13T09:30:00Z', changed_by_name: null }, history, format), '[2026-10-13T09:30:00Z] – en tidligere bruker');
    });

    test('is shown on the requirement, apart from its controls, on Kontrollkrav and on the supplier', () => {
        assert.match(source('./ControlRequirements.jsx'), /<RequirementStatusHistory entries=\{row\.status_history \?\? \[\]\}/);
        const supplier = source('./SupplierControlRequirements.jsx');
        assert.equal(supplier.match(/<RequirementStatusHistory entries=\{row\.status_history \?\? \[\]\}/g)?.length, 2, 'applying and retired rows');
        assert.doesNotMatch(source('./RequirementStatusHistory.jsx'), /evaluations/);
    });
});

describe('Slettede leverandører', () => {
    test('the register offers the log only when the server says the person may delete', () => {
        assert.match(source('./Index.jsx'), /\{canViewDeletions && ! creating && \(/);
        assert.match(source('./Index.jsx'), /permissions\.can_view_deletions \?\? false/);
    });

    test('the log is read-only: no form, no button that writes', () => {
        const page = source('./DeletedSuppliers.jsx');
        assert.doesNotMatch(page, /router\.(post|delete|patch|put)|useForm|<form/);
    });
});
