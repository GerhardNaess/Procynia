import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { appliesToText, groupByTheme, requirementFormData, rowActions, toggleCode } from './controlRequirements.js';

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
