import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { requirementFormData, requirementKeepsRule, templateItemUse, templatePreview } from './controlRequirements.js';

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
        // One list for all nine templates: stacked on a phone, three across from lg; an open one takes
        // the whole row, and the closed ones fill the rows around it.
        assert.match(page, /<ul className="mt-4 grid gap-3 lg:grid-flow-row-dense lg:grid-cols-3">/);
        assert.match(page, /shown\.includes\(row\.key\) \? .lg:col-span-3. : ..\}/);
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

    test('only supplier.assure is offered the action, the confirmation says nothing is changed or approved, and no template key is shown', () => {
        const page = source('./RequirementTemplates.jsx');

        assert.match(page, /\{canManage && \(\s*<button type="button" onClick=\{\(\) => setOpen\(row\.key\)\}/);
        assert.match(page, /isOpen=\{canManage && template !== null\}/);
        assert.match(page, /t\.dialog_keeps/);
        assert.match(page, /\/app\/supplier-management\/control-requirements\/templates\/\$\{template\.key\}/);
        // The person reads names, titles and the item's code (S1, P1 …) — never a template key, a
        // version, a rule or a score. The code is shown in the read-only list only.
        assert.doesNotMatch(page, />\s*\{(row|template)\.(key|version)\}|>\s*\{item\.version\}|applies_when|predicate|score|%/);
        assert.equal((page.match(/>\{item\.key\}</g) ?? []).length, 1);
        assert.match(page, /\{browse && <span className="font-semibold text-slate-600" data-testid="template-item-code">\{item\.key\}<\/span>\}/);
    });

    test('«Vis kravene» lists a template\'s items for everyone who reads Kontrollkrav, and changes nothing', () => {
        const page = source('./RequirementTemplates.jsx');
        const toggle = page.slice(page.indexOf('data-testid="requirement-template-toggle"') - 400, page.indexOf('data-testid="requirement-template-toggle"'));

        // Not behind canManage, and a plain toggle: no request is sent to look.
        assert.doesNotMatch(toggle, /canManage/);
        assert.match(page, /onClick=\{\(\) => toggleShown\(row\.key\)\}/);
        assert.match(page, /aria-expanded=\{shown\.includes\(row\.key\)\}/);
        assert.match(page, /<ItemList items=\{row\.items\} tr=\{tr\} browse \/>/);
        // The count on the button is the list it opens.
        assert.match(page, /t\.show_items \?\? 'Vis kravene \(:count\)', \{ count: row\.items\.length \}/);
        assert.equal((page.match(/router\.post\(/g) ?? []).length, 1, 'applying is the only request');
    });

    test('whether a template item is in use follows the server\'s match, retired included', () => {
        assert.equal(templateItemUse({ key: 'P1', existing: null }), 'not_in_use');
        assert.equal(templateItemUse({ key: 'P1', existing: { title: 'DBA', status: 'active' } }), 'in_use');
        assert.equal(templateItemUse({ key: 'S4', existing: { title: 'Varsling', status: 'retired' } }), 'in_use_retired');
        assert.equal(templateItemUse(null), 'not_in_use');
    });

    test('Virksomhetens kontrollkrav come before the templates, and a reader is told once why nothing can be changed', () => {
        const page = source('./ControlRequirements.jsx');

        assert.ok(page.indexOf('data-testid="control-register"') < page.indexOf('<RequirementTemplates '), 'the register is the first section');
        assert.match(page, /k\.register_heading \?\? 'Virksomhetens kontrollkrav'/);
        assert.match(page, /\{! canManage && \(\s*<p [^>]*data-testid="control-read-only">/);
        assert.equal((page.match(/k\.read_only/g) ?? []).length, 1);
        // The empty register says how to start — by what the person may do.
        assert.match(page, /\{canManage \? k\.none_start_manage : k\.none_start_read\}/);
    });

    test('until the content review is signed, the section and the confirmation say the templates are not quality-assured', () => {
        const component = source('./RequirementTemplates.jsx');
        assert.match(component, /reviewed = false/);
        assert.equal((component.match(/\{!reviewed && <Unreviewed t=\{t\} \/>\}/g) ?? []).length, 2);
        assert.match(source('./ControlRequirements.jsx'), /templates_reviewed: templatesReviewed = false/);
        assert.match(source('./ControlRequirements.jsx'), /reviewed=\{templatesReviewed\}/);
    });
});

describe('Kontrollkrav and Krav og kvalifikasjoner stay apart (2026-10-13)', () => {
    test('Kravmaler is closed until opened, by a native button, and keeps its content mounted', () => {
        const section = source('./RequirementTemplates.jsx');
        assert.match(section, /const \[expanded, setExpanded\] = useState\(false\)/);
        assert.match(section, /<button\s+type="button"\s+aria-expanded=\{expanded\}\s+aria-controls="requirement-templates-panel"/);
        assert.match(section, /id="requirement-templates-panel" hidden=\{! expanded\}/);
    });

    test('Kontrollkrav is headed Kontrollkrav, and the supplier tab has no link suggesting more requirements apply', () => {
        assert.match(source('./ControlRequirements.jsx'), /<h1[^>]*>\{k\.heading \?\? 'Kontrollkrav'\}<\/h1>/);
        const tab = source('./SupplierControlRequirements.jsx');
        assert.doesNotMatch(tab, /go_to_catalogue|Se alle kontrollkrav/);
        assert.doesNotMatch(tab, /<Link href="\/app\/supplier-management\/control-requirements"/);
    });
});
