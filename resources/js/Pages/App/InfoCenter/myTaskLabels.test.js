import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { filterTaskGroups, moduleFilterOptions, moduleLabel, supplierReasonText, taskCountLabel, taskReasonText, visibleTaskGroups } from './myTaskLabels.js';

const here = dirname(fileURLToPath(import.meta.url));
const index = readFileSync(join(here, 'Index.jsx'), 'utf8');
const myTasksView = readFileSync(join(here, 'MyTasks.jsx'), 'utf8');

/**
 * «Mine oppgaver» (punkt 6B): every module's open work for the person, grouped Forfalt / Denne uken /
 * Senere / Uten frist. The backend decides the groups and the access; the page only names them.
 */
describe('the groups', () => {
    const payload = {
        count: 3,
        groups: [
            { key: 'overdue', tasks: [{ id: 'supplier-1' }] },
            { key: 'this_week', tasks: [] },
            { key: 'later', tasks: [{ id: 'tender-item-4' }] },
            { key: 'no_due', tasks: [{ id: 'wiki-review-9' }] },
        ],
    };

    test('an empty group is left out and the order is the backend\'s', () => {
        assert.deepEqual(visibleTaskGroups(payload).map((group) => group.key), ['overdue', 'later', 'no_due']);
    });

    test('labels come from translations, with the Norwegian names as fallback', () => {
        assert.deepEqual(visibleTaskGroups(payload, { overdue: 'Overdue' }).map((group) => group.label), ['Overdue', 'Senere', 'Uten frist']);
    });

    test('a missing payload is no groups, not a crash', () => {
        assert.deepEqual(visibleTaskGroups(undefined), []);
        assert.deepEqual(visibleTaskGroups({ groups: null }), []);
    });
});

describe('a supplier reason in words', () => {
    const categories = { document_expired: 'Dokumentasjon utløpt', control_overdue: 'Kontroll forfalt', not_assessed: 'Ikke vurdert' };

    test('it uses the supplier module\'s own name for the signal', () => {
        assert.equal(supplierReasonText({ key: 'not_assessed' }, categories), 'Ikke vurdert');
    });

    test('it names the document, or how many requirements', () => {
        assert.equal(supplierReasonText({ key: 'document_expired', document_title: 'Forsikring 2025' }, categories), 'Dokumentasjon utløpt: Forsikring 2025');
        assert.equal(supplierReasonText({ key: 'control_overdue', requirement_count: 2 }, categories, { requirement_count: ':count requirements' }), 'Kontroll forfalt (2 requirements)');
    });

    test('an unknown signal falls back to its key rather than to nothing', () => {
        assert.equal(supplierReasonText({ key: 'new_signal' }, categories), 'new_signal');
    });
});

describe('labels', () => {
    test('module and count labels translate', () => {
        assert.equal(moduleLabel('supplier'), 'Leverandører');
        assert.equal(moduleLabel('supplier', { supplier: 'Suppliers' }), 'Suppliers');
        assert.equal(taskCountLabel(1), 'oppgave');
        assert.equal(taskCountLabel(2, { count_many: 'tasks' }), 'tasks');
    });
});

describe('the page', () => {
    test('«Mine oppgaver» replaces the paginated list under its own view, and only there', () => {
        assert.match(index, /const isMyTasksView = activeView === 'my_tasks';/);
        assert.match(index, /\{isMyTasksView \? \(\s*\n\s*<MyTaskGroups/);
        // No pager under a list that is not paginated.
        assert.match(index, /\{isMyTasksView \? null : \(\s*\n\s*<div className="mt-5 flex/);
    });

    test('each module keeps its own card', () => {
        assert.match(index, /case 'supplier':\s*\n\s*return <SupplierTaskCard/);
        assert.match(index, /return task\.item \? <InfoItemCard item=\{task\.item\} locale=\{locale\} \/> : null;/);
    });

    test('a supplier task says when the person cannot act, and never hides the task for it', () => {
        assert.match(myTasksView, /\{task\.can_act \? null : \(/);
        assert.match(myTasksView, /\{reason\.can_act \? null : \(/);
        assert.match(myTasksView, /data-testid="info-center-supplier-task-missing-permission"/);
    });

    test('the supplier signal names are the supplier module\'s translations', () => {
        assert.match(index, /translations\?\.supplier_management\?\.attention\?\.categories/);
    });
});

describe('the module filter (punkt 6D)', () => {
    const payload = {
        count: 3,
        modules: [{ key: 'tender', count: 0 }, { key: 'risk', count: 2 }, { key: 'quality', count: 1 }],
        groups: [
            { key: 'overdue', tasks: [{ id: 'risk-1', module: 'risk' }, { id: 'quality-item-4', module: 'quality' }] },
            { key: 'no_due', tasks: [{ id: 'risk-2', module: 'risk' }] },
        ],
    };

    test('it offers every module the person can see, with its count, labelled', () => {
        assert.deepEqual(moduleFilterOptions(payload), [
            { key: 'tender', count: 0, label: 'Anbud' },
            { key: 'risk', count: 2, label: 'Risiko' },
            { key: 'quality', count: 1, label: 'Kvalitet' },
        ]);
    });

    test('one module is no choice, so no filter', () => {
        assert.deepEqual(moduleFilterOptions({ modules: [{ key: 'risk', count: 1 }] }), []);
    });

    test('choosing a module narrows the same groups; an emptied group disappears', () => {
        assert.deepEqual(visibleTaskGroups(filterTaskGroups(payload, 'quality')).map((group) => [group.key, group.tasks.map((task) => task.id)]), [['overdue', ['quality-item-4']]]);
        assert.deepEqual(filterTaskGroups(payload, null), payload);
    });
});

describe('a styringsmodul reason in words', () => {
    const t = { reasons: { risk: { review_overdue: 'Vurdering forfalt' }, compliance: { review_overdue: 'Revurdering forfalt' } }, count: ':count stk.' };

    test('the same key reads in each module\'s own words', () => {
        assert.equal(taskReasonText({ module: 'risk' }, { key: 'review_overdue' }, t), 'Vurdering forfalt');
        assert.equal(taskReasonText({ module: 'compliance' }, { key: 'review_overdue' }, t), 'Revurdering forfalt');
    });

    test('a counted reason says how many, and an unknown one falls back to its key', () => {
        assert.equal(taskReasonText({ module: 'risk' }, { key: 'review_overdue', count: 3 }, t), 'Vurdering forfalt (3 stk.)');
        assert.equal(taskReasonText({ module: 'risk' }, { key: 'new_rule' }, t), 'new_rule');
    });
});

describe('the five styringsmoduler share one card', () => {
    test('anything that is not Anbud, Wiki or Leverandører is drawn by GovernanceTaskCard', () => {
        assert.match(index, /case 'tender':\s*\n\s*return task\.item \? <InfoItemCard/);
        assert.match(index, /default:\s*\n\s*return <GovernanceTaskCard task=\{task\} locale=\{locale\} t=\{mt\} \/>;/);
        assert.match(myTasksView, /data-testid="info-center-task-missing-permission"/);
        assert.match(myTasksView, /aria-pressed=\{module === option\.key\}/);
    });
});
