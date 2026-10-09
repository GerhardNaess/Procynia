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

    test('an overlapping template (Kritisk IKT after IT/SaaS) adds only its own items; a recommended level is shown, not applied', () => {
        const itSaas = ['S1', 'S2', 'S4', 'S5', 'S6', 'S8', 'P1', 'P2', 'P3', 'P4', 'C1'];
        const template = {
            key: 'critical_ict',
            items: [...itSaas, 'S3', 'S7', 'C2', 'C3', 'F2', 'Q2'].map((key) => ({
                key, title: key, level: 'important', recommended_level: null, existing: itSaas.includes(key) ? { title: key, status: 'active' } : null,
            })),
        };

        const preview = templatePreview(template);
        assert.deepEqual(preview.toCreate.map((item) => item.key), ['S3', 'S7', 'C2', 'C3', 'F2', 'Q2']);
        assert.equal(preview.existing.length, 11);

        const page = source('./RequirementTemplates.jsx');
        assert.match(page, /\{item\.recommended_level && \(/);
        assert.match(page, /t\.recommended_level/);
        // One list for all nine templates: stacked on a phone, three across from lg.
        assert.match(page, /<ul className="mt-4 grid gap-3 lg:grid-cols-3">/);
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

    test('until the content review is signed, the section and the confirmation say the templates are not quality-assured', () => {
        const component = source('./RequirementTemplates.jsx');
        assert.match(component, /reviewed = false/);
        assert.equal((component.match(/\{!reviewed && <Unreviewed t=\{t\} \/>\}/g) ?? []).length, 2);
        assert.match(source('./ControlRequirements.jsx'), /templates_reviewed: templatesReviewed = false/);
        assert.match(source('./ControlRequirements.jsx'), /reviewed=\{templatesReviewed\}/);
    });
});
