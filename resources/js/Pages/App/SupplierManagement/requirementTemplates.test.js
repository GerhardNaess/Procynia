import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { requirementFormData, requirementKeepsRule, templatePreview } from './controlRequirements.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

describe('Kravmaler (supplier-assurance-v2-plan §16, §22.2)', () => {
    test('the preview adds what is missing and lists what is already there, as the server matched it', () => {
        const template = {
            key: 'data_processor',
            items: [
                { key: 'P1', title: 'Databehandleravtale', level: 'mandatory', existing: { title: 'DBA', status: 'active' } },
                { key: 'P5', title: 'Forsterkede tiltak for særlige kategorier', level: 'important', existing: null },
                { key: 'S4', title: 'Varsling', level: 'mandatory', existing: { title: 'Varsling', status: 'retired' } },
            ],
        };

        const preview = templatePreview(template);
        assert.deepEqual(preview.toCreate.map((item) => item.key), ['P5']);
        assert.deepEqual(preview.existing.map((item) => item.key), ['P1', 'S4']);
        assert.deepEqual(templatePreview(null), { toCreate: [], existing: [] });
    });

    test('a template rule the form cannot write is kept on edit, and never offered for a new or own requirement', () => {
        const fromTemplate = { id: 7, title: 'Overføringsgrunnlag', rule: null, rule_text: 'Gjelder når …', supplier_specific: false };

        assert.equal(requirementKeepsRule(fromTemplate), true);
        assert.equal(requirementFormData(fromTemplate).rule_mode, 'keep');
        assert.equal(requirementKeepsRule(null), false);
        assert.equal(requirementFormData().rule_mode, 'all');
        assert.equal(requirementKeepsRule({ id: 8, rule: null, supplier_specific: true }), false);
        // A rule the form can write is shown as the form writes it.
        assert.deepEqual(requirementFormData({ id: 9, rule: { rule_mode: 'conditions', conditions: ['processor'], criticality_scope: '' } }).conditions, ['processor']);
    });

    test('only supplier.assure is offered the action, the confirmation says nothing is changed or approved, and no key is shown', () => {
        const page = source('./RequirementTemplates.jsx');

        assert.match(page, /\{canManage && \(\s*<div className="mt-auto pt-3">/);
        assert.match(page, /isOpen=\{canManage && template !== null\}/);
        assert.match(page, /t\.dialog_keeps/);
        assert.match(page, /\/app\/supplier-management\/control-requirements\/templates\/\$\{template\.key\}/);
        // The person reads names and titles — never a template or item key, a rule or a score.
        assert.doesNotMatch(page, />\s*\{(item|row|template)\.(key|version)\}|applies_when|predicate|score|%/);
    });
});
