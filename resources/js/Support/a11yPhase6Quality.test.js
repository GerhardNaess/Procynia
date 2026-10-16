import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    diagramIsInteractive,
    flowAiAnnouncement,
    flowAiStateKey,
    insertButtonText,
    insertionsAfter,
    laneRemoveLabel,
    laneRemovedAnnouncement,
    nodeAction,
    nodesInLane,
    positionalRemovalFocusCandidates,
    removalFocusCandidates,
    structureFieldLabel,
} from '../Components/App/processFlowA11y.js';
import { layoutBlueprint } from './processBlueprintLayout.js';
import { canInsertStepOn } from './processStepEdit.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, '..', ...parts), 'utf8');
const lang = (code) => readFileSync(join(here, '..', '..', '..', 'lang', code, 'procynia.php'), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md): Kvalitet and the process diagram. The
 * rules are pinned here; behaviour in a real browser is in tests/e2e/a11y-grc-quality.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

const panel = code(read('Components', 'App', 'ProcessFlowPanel.jsx'));
const diagram = code(read('Components', 'App', 'ProcessSwimlaneDiagram.jsx'));
const stepList = code(read('Components', 'App', 'ProcessFlowStepList.jsx'));
const changeCard = code(read('Components', 'App', 'ProcessFlowChangeCard.jsx'));
const actions = code(read('Components', 'App', 'QualityItemActions.jsx'));
const index = code(read('Pages', 'App', 'Quality', 'Index.jsx'));
const item = code(read('Pages', 'App', 'Quality', 'Item.jsx'));

// A small flow with every kind of node: start → registrer → beslutning (Ja → godkjenn → slutt, Nei → slutt).
const FLOW = {
    lanes: [{ key: 'buyer', label: 'Innkjøper' }, { key: 'lead', label: 'Leder' }],
    nodes: [
        { key: 'start', lane: 'buyer', type: 'start', label: 'Start' },
        { key: 'register', lane: 'buyer', type: 'step', label: 'Registrer', articles: [{ title: 'A' }] },
        { key: 'big', lane: 'lead', type: 'decision', label: 'Stor?' },
        { key: 'approve', lane: 'lead', type: 'step', label: 'Godkjenn', subprocess: { id: 9, title: 'Kontroll' } },
        { key: 'end', lane: 'lead', type: 'end', label: 'Slutt' },
    ],
    edges: [
        { from: 'start', to: 'register' },
        { from: 'register', to: 'big' },
        { from: 'big', to: 'approve', label: 'Ja' },
        { from: 'big', to: 'end', label: 'Nei' },
        { from: 'approve', to: 'end' },
    ],
};
const handler = () => {};

describe('the diagram is a picture only when nothing in it can be pressed (A11Y-004)', () => {
    test('one node, one action — the same priority the diagram has always used', () => {
        const all = { onOpenSubprocess: handler, onOpenActivity: handler, onEditStep: handler };
        const node = (key) => FLOW.nodes.find((candidate) => candidate.key === key);

        assert.equal(nodeAction(node('approve'), all), 'subprocess');
        assert.equal(nodeAction(node('register'), all), 'edit');
        assert.equal(nodeAction(node('register'), { onOpenActivity: handler }), 'activity');
        assert.equal(nodeAction(node('start'), all), null);
        assert.equal(nodeAction(node('big'), { onOpenActivity: handler }), null);
    });

    test('interactive with any handler that reaches a node or an arrow, a picture without', () => {
        const layout = layoutBlueprint(FLOW);

        assert.equal(diagramIsInteractive(layout, {}), false);
        assert.equal(diagramIsInteractive(layout, { onEditStep: handler }), true);
        assert.equal(diagramIsInteractive(layout, { onInsertStep: handler }), true);
        assert.equal(diagramIsInteractive(layout, { onOpenActivity: handler }), true, 'a node with knowledge opens it');
        assert.equal(
            diagramIsInteractive(layoutBlueprint({ ...FLOW, nodes: FLOW.nodes.map(({ articles, subprocess, ...node }) => node) }), { onOpenActivity: handler, onOpenSubprocess: handler }),
            false,
            'nothing to open: a read-only flow without knowledge or subprocesses stays a picture',
        );
    });

    test('the svg takes its role from that rule and draws its own focus ring (A11Y-128)', () => {
        assert.ok(diagram.includes("role={interactive ? 'group' : 'img'}"));
        assert.ok(diagram.includes('diagramIsInteractive(layout'));
        assert.ok(diagram.includes('nodeAction(node'), 'the nodes use the same rule as the svg role');
        assert.ok(! diagram.includes('focus-visible:outline-slate-600'), 'no outline on an SVG <g>: Firefox and Safari do not paint it');
        assert.ok(diagram.includes('group-focus-visible:opacity-100'));
        assert.equal((diagram.match(/className=\{FOCUS_RING\}/g) ?? []).length, 2, 'a ring on the node and on the «+»');
        assert.ok(diagram.includes('<desc id={descriptionId}>'));
    });

    test('strokes are at least slate-500 (A11Y-018)', () => {
        for (const weak of ["'#cbd5e1', text", "stroke: '#6ee7b7'", "stroke: '#a5b4fc'", "stroke: '#fcd34d'", 'stroke="#94a3b8"']) {
            assert.ok(! diagram.includes(weak), `${weak} is under 3:1`);
        }
        assert.ok(diagram.includes("const EDGE_STROKE = '#64748b'"));
    });

    test('only the buttons are exposed inside the group; the drawing is hidden', () => {
        assert.ok(diagram.includes('<g key={lane.key} aria-hidden="true">'));
        assert.ok(diagram.includes('<g aria-hidden="true">\n            <path'), 'edges');
        assert.ok(diagram.includes('return <g aria-hidden="true">{body}</g>;'), 'a node that does nothing');
    });
});

describe('the step list offers what the diagram offers, on the same data (A11Y-004)', () => {
    test('«Legg til aktivitet» on exactly the arrows the diagram draws a «+» on', () => {
        const layout = layoutBlueprint(FLOW);
        const diagramArrows = layout.edges
            .filter((edge) => canInsertStepOn(edge, layout.nodes))
            .map((edge) => `${edge.from}->${edge.to}`)
            .sort();
        const listArrows = FLOW.nodes
            .flatMap((node) => insertionsAfter(FLOW, node.key))
            .map((insertion) => `${insertion.from}->${insertion.to}`)
            .sort();

        assert.deepEqual(listArrows, diagramArrows);
        assert.deepEqual(insertionsAfter(FLOW, 'big'), [], 'never on a branch out of a decision');
        assert.equal(insertionsAfter(FLOW, 'register')[0].toLabel, 'Stor?');
    });

    test('the button names start with their visible text (2.5.3)', () => {
        const insertion = { from: 'a', to: 'b', fromLabel: 'Registrer', toLabel: 'Godkjenn' };
        const single = insertButtonText({}, insertion, false);
        const several = insertButtonText({}, insertion, true);

        assert.equal(single.text, 'Legg til aktivitet etter');
        assert.equal(single.label, 'Legg til aktivitet etter «Registrer»');
        assert.ok(several.label.startsWith(several.text));
        assert.ok(several.label.includes('«Registrer»'));
    });

    test('the panel hands the list the same handlers it hands the diagram', () => {
        const edit = 'onEditStep={canEdit && ! reviewing ? (node) => setEditingStepKey(node.key) : null}';
        const insert = 'onInsertStep={canEdit && ! reviewing ? setInsertingOn : null}';

        assert.equal(panel.split(edit).length - 1, 2, 'diagram and list');
        assert.equal(panel.split(insert).length - 1, 2, 'diagram and list');
        assert.ok(stepList.includes('onClick={() => onEditStep(node)}'));
        assert.ok(stepList.includes('onInsertStep({ from: insertion.from, to: insertion.to, label: insertion.label })'));
        assert.ok(stepList.includes("(tb.step_edit_open ?? 'Rediger steget :label')"), 'the same name as the diagram node');
    });

    test('the read-only subprocess list gets no edit actions', () => {
        const subprocess = panel.slice(panel.indexOf('function SubprocessFlow('), panel.indexOf('function SubprocessOwnPageLink('));

        assert.ok(! subprocess.includes('onEditStep'));
        assert.ok(! subprocess.includes('onInsertStep'));
    });
});

describe('the structure editor names every field and keeps focus (A11Y-004, -108, -014, -127)', () => {
    test('no empty header cell, every header scoped, tables captioned', () => {
        assert.ok(! panel.includes('<th className="pb-2" />'));
        const headers = panel.match(/<th\b[^>]*>/g) ?? [];

        assert.ok(headers.length >= 9);
        for (const header of headers) {
            assert.ok(header.includes('scope="col"'), header);
        }
        assert.equal((panel.match(/<caption className="sr-only">/g) ?? []).length, 2);
    });

    test('every structure field is named by column and row', () => {
        assert.equal(structureFieldLabel({}, 'Tekst', 'node', 3), 'Tekst, node 3');
        assert.ok(panel.includes("aria-label={name(tb.node_label ?? 'Tekst', index)}"));
        assert.ok(panel.includes("aria-label={name(tb.node_lane ?? 'Rolle', index)}"));
        assert.ok(panel.includes("aria-label={name(tb.node_type ?? 'Type', index)}"));
        assert.ok(panel.includes("aria-label={name(tb.edge_label ?? 'Utfall', index)}"));
        assert.ok(panel.includes("label={name(tb.edge_from ?? 'Fra', index)}"));
        assert.ok(panel.includes("label={name(tb.edge_to ?? 'Til', index)}"));
    });

    test('removing a row puts focus on the next row, the previous one, then «Legg til»', () => {
        assert.deepEqual(removalFocusCandidates(['a', 'b', 'c'], 1, 'add'), ['c', 'a', 'add']);
        assert.deepEqual(removalFocusCandidates(['a'], 0, 'add'), ['add']);
        assert.deepEqual(positionalRemovalFocusCandidates('edge-', 3, 1, 'add'), ['edge-1', 'edge-0', 'add']);
        assert.deepEqual(positionalRemovalFocusCandidates('edge-', 3, 2, 'add'), ['edge-1', 'add']);
        assert.deepEqual(positionalRemovalFocusCandidates('edge-', 1, 0), []);
    });

    test('removing a role says that its steps go with it, before and after (A11Y-127)', () => {
        assert.equal(nodesInLane(FLOW.nodes, 'lead'), 3);
        assert.equal(laneRemoveLabel({}, 'Leder', 3), 'Fjern rollen «Leder» og 3 steg i den');
        assert.equal(laneRemoveLabel({}, 'Leder', 0), 'Fjern rollen «Leder»');
        assert.match(laneRemovedAnnouncement({}, 'Leder', 3), /3 steg.*ikke lagret/);
        assert.match(laneRemovedAnnouncement({}, '', 0), /uten navn/);

        const lane = panel.slice(panel.indexOf('function LaneEditor('), panel.indexOf('function NodeEditor('));
        assert.ok(lane.includes('announce(laneRemovedAnnouncement('));
        assert.ok(lane.includes('setNodes(nodes.filter((node) => node.lane !== removed.key));'), 'what removing does is unchanged');
    });

    test('fields use the field border (A11Y-019)', () => {
        for (const source of [panel, changeCard, index, item]) {
            assert.ok(! /w-full rounded-(xl|lg) border border-slate-200 bg-white/.test(source));
        }
    });
});

describe('AI answers are said once, when they arrive (4.1.3)', () => {
    test('a key per answer, idle without one', () => {
        assert.equal(flowAiStateKey({}), 'idle');
        assert.match(flowAiStateKey({ proposal: { description: 'x', nodes: [1], edges: [] } }), /^proposal:/);
        assert.match(flowAiStateKey({ flowError: { message: 'm' }, proposal: { description: 'x' } }), /^flow-error:/);
        assert.match(flowAiStateKey({ changeProposal: { base_hash: 'h', changes: [1, 2] } }), /^change:/);
        assert.notEqual(
            flowAiStateKey({ proposal: { description: 'a', nodes: [] } }),
            flowAiStateKey({ proposal: { description: 'b', nodes: [] } }),
            'a re-read after «Avklar» is a new answer',
        );
    });

    test('what is said: the kind of answer, never its text', () => {
        assert.match(flowAiAnnouncement('proposal:x', {}).message, /Forslaget til prosessflyt er klart/);
        assert.equal(flowAiAnnouncement('flow-error:x', {}).politeness, 'assertive');
        assert.match(flowAiAnnouncement('change:h', {}, { changeCount: 2 }).message, /2 endringer/);
        assert.match(flowAiAnnouncement('change:h', {}, { changeCount: 1 }).message, /1 endring\./);
        assert.equal(flowAiAnnouncement('change-error:x', {}), null, 'already a role="alert"');
        assert.equal(flowAiAnnouncement('idle', {}), null);
        assert.ok(panel.includes('useTransitionAnnouncement(\n        flowAiStateKey('));
    });

    test('the AI buttons keep focus while they work (A11Y-143)', () => {
        assert.ok(panel.includes('aria-disabled={busy || undefined}'));
        assert.ok(changeCard.includes('aria-disabled={busy || undefined}'));
    });

    test('a refused description or instruction is tied to its field and focused (A11Y-013)', () => {
        assert.ok(panel.includes('id="process-flow-description"'));
        assert.ok(changeCard.includes('id="process-flow-change-instruction"'));
        assert.ok(panel.includes('focusInterpretError();'));
        assert.ok(panel.includes('focusChangeError();'));
        assert.ok(! panel.includes("aria-label={tb.ai_label ?? 'Prosessbeskrivelse'}"), 'named by its visible label only');
    });
});

describe('Kvalitet pages (A11Y-013, -027, -103, -121)', () => {
    test('the row menu is a disclosure, not role="menu"', () => {
        assert.ok(! actions.includes('role="menu"'));
        assert.ok(! actions.includes('role="menuitem"'));
        assert.ok(! actions.includes('aria-haspopup'));
        assert.ok(actions.includes('aria-expanded={isMenuOpen}'));
        assert.ok(actions.includes("event.key === 'Escape'") && actions.includes('triggerRef.current?.focus()'));
    });

    test('a refused delete is shown at the button in the dialog', () => {
        assert.ok(actions.includes('trackRowAction(errorKey'));
        assert.ok(actions.includes('aria-describedby={deleteError ? rowErrorId(errorKey) : undefined}'));
    });

    test('the Dokument | Flyt links say which one is open', () => {
        const tabs = item.slice(item.indexOf('function DetailTabs('), item.indexOf('function ControlPlacementsPanel('));

        assert.ok(tabs.includes("aria-current={key === activeTab ? 'page' : undefined}"));
        assert.ok(tabs.includes('<nav className="flex flex-wrap gap-2" aria-label='));
    });

    test('fields go through FormField; errors are rose-700 and tied to the field', () => {
        for (const source of [index, item]) {
            assert.ok(source.includes('{(field) => cloneElement(children, field)}'));
            assert.ok(! source.includes('<label className="block space-y-1">'), 'no error inside a <label>');
            assert.ok(! source.includes('text-rose-600'));
        }
        assert.ok(item.includes('invalid={Boolean(error)}'), 'the pickers are marked invalid');
    });

    test('forms with «*» explain it once', () => {
        assert.equal((index.match(/<RequiredFieldsNote /g) ?? []).length, 2, 'Registrer verktøy, Registrer i kvalitetssystemet');
        assert.equal((item.match(/<RequiredFieldsNote /g) ?? []).length, 2, 'Styringsinformasjon, Legg til evidens');
        assert.equal((panel.match(/<RequiredFieldsNote /g) ?? []).length, 3, 'Rediger steg, Ny kontroll, artikkel');
    });

    test('removals still ask first, and then keep focus in the list', () => {
        assert.equal((item.match(/window\.confirm\(/g) ?? []).length, 6);
        assert.equal((panel.match(/window\.confirm\(/g) ?? []).length, 1);
        assert.equal((item.match(/removeRow\(`/g) ?? []).length, 6);
    });

    test('the Kvalitet tables have captions, scoped headers and a row header', () => {
        assert.equal((index.match(/<caption className="sr-only">/g) ?? []).length, 3);
        assert.equal((index.match(/<th scope="row"/g) ?? []).length, 3);
        assert.ok(! /<th className=/.test(index), 'every header has a scope');
    });
});

describe('translations', () => {
    test('every new a11y key exists in both languages', () => {
        const keys = [
            'quality_diagram_description', 'quality_diagram_interactive_description', 'quality_step_edit',
            'quality_step_insert_after', 'quality_step_insert_after_label', 'quality_step_insert_before',
            'quality_step_insert_before_label', 'quality_structure_field', 'quality_structure_node',
            'quality_structure_edge', 'quality_lane_remove', 'quality_lane_remove_with_step',
            'quality_lane_remove_with_steps', 'quality_lane_removed', 'quality_lane_removed_with_step',
            'quality_lane_removed_with_steps', 'quality_lane_unnamed', 'quality_flow_failed',
            'quality_flow_proposal_ready', 'quality_change_proposal_empty', 'quality_change_proposal_ready',
            'quality_change_proposal_ready_one', 'quality_item_views', 'quality_tool_source',
        ];

        for (const key of keys) {
            assert.match(lang('no'), new RegExp(`'${key}' => '`), `missing ${key} in lang/no`);
            assert.match(lang('en'), new RegExp(`'${key}' => '`), `missing ${key} in lang/en`);
        }
    });
});
