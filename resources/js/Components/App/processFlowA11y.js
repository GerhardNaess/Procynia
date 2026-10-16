import { layoutBlueprint } from '../../Support/processBlueprintLayout.js';
import { canInsertStepOn, isEditableStep } from '../../Support/processStepEdit.js';

/**
 * The accessibility rules of Prosessflyt (WCAG Fase 6, docs/wcag-accessibility-plan.md A11Y-004,
 * -014, -127). Presentation only: every function here reads the flow the editor already holds and
 * decides what is said or focused — none of it changes the blueprint, the layout or what is saved.
 * Pure, so it is unit-tested (resources/js/Support/a11yPhase6Quality.test.js).
 */

/**
 * What a click on one node of the diagram does, or null when the node is only a picture.
 *
 * ONE NODE, ONE ACTION (see ProcessSwimlaneDiagram's Node): drilling into a subprocess wins, then
 * editing, then opening the activity's knowledge.
 *
 * @returns {'subprocess'|'edit'|'activity'|null}
 */
export function nodeAction(node, { onOpenSubprocess = null, onOpenActivity = null, onEditStep = null } = {}) {
    if ((node?.subprocess ?? null) !== null && typeof onOpenSubprocess === 'function') {
        return 'subprocess';
    }

    if (typeof onEditStep === 'function' && isEditableStep(node)) {
        return 'edit';
    }

    if ((node?.articles ?? []).length > 0 && typeof onOpenActivity === 'function') {
        return 'activity';
    }

    return null;
}

/**
 * Whether the drawn diagram holds anything that can be pressed. A picture is `role="img"`; a drawing
 * with buttons inside must not be, because everything inside an img is presentational to a screen
 * reader (A11Y-004) — it is a named group instead.
 */
export function diagramIsInteractive(layout, handlers = {}) {
    const nodes = layout?.nodes ?? [];
    const edges = layout?.edges ?? [];

    if (typeof handlers.onInsertStep === 'function' && edges.some((edge) => canInsertStepOn(edge, nodes))) {
        return true;
    }

    return nodes.some((node) => nodeAction(node, handlers) !== null);
}

/**
 * The arrows a new activity can be put on, leaving one step — the same arrows the diagram draws a «+»
 * on (canInsertStepOn over the laid-out flow), so the step list offers exactly what the diagram does.
 *
 * @returns {Array<{ from: string, to: string, label: string|null, fromLabel: string, toLabel: string }>}
 */
export function insertionsAfter(blueprint, key) {
    const layout = layoutBlueprint(blueprint);
    const labelOf = (nodeKey) => layout.nodes.find((node) => node.key === nodeKey)?.label ?? '';

    return layout.edges
        .filter((edge) => edge.from === key && canInsertStepOn(edge, layout.nodes))
        .map((edge) => ({
            from: edge.from,
            to: edge.to,
            label: edge.label ?? null,
            fromLabel: labelOf(edge.from),
            toLabel: labelOf(edge.to),
        }));
}

/**
 * The visible text and accessible name of one «Legg til aktivitet» button in the step list.
 *
 * The name starts with the visible text (2.5.3 Label in Name). With one way on from the step the
 * button just says «etter»; with several, each says which step the new one comes before.
 */
export function insertButtonText(ta, insertion, several) {
    const fill = (template) => template
        .replace(':from', insertion.fromLabel ?? '')
        .replace(':to', insertion.toLabel ?? '');

    if (several) {
        return {
            text: fill(ta.quality_step_insert_before ?? 'Legg til aktivitet før «:to»'),
            label: fill(ta.quality_step_insert_before_label ?? 'Legg til aktivitet før «:to», etter «:from»'),
        };
    }

    return {
        text: ta.quality_step_insert_after ?? 'Legg til aktivitet etter',
        label: fill(ta.quality_step_insert_after_label ?? 'Legg til aktivitet etter «:from»'),
    };
}

/**
 * Where focus goes when the row at `index` is removed from a list: the row after it, the row before
 * it, then the fallback (the list's «Legg til …» button) — so focus never falls to <body> (2.4.3).
 *
 * @param {string[]} ids     The id of each row's focus target, before the removal.
 * @param {number}   index   The row being removed.
 * @param {string}   [fallback]
 */
export function removalFocusCandidates(ids, index, fallback = null) {
    return [ids[index + 1], ids[index - 1], fallback].filter((id) => typeof id === 'string' && id !== '');
}

/**
 * Same as removalFocusCandidates for a list whose row ids are their position (the edge table): after
 * the removal the next row has moved up into `index`.
 */
export function positionalRemovalFocusCandidates(prefix, count, index, fallback = null) {
    const after = index + 1 < count ? `${prefix}${index}` : null;
    const before = index > 0 ? `${prefix}${index - 1}` : null;

    return [after, before, fallback].filter(Boolean);
}

/** How many steps of the flow sit in one role — removing the role takes them with it (A11Y-127). */
export function nodesInLane(nodes, laneKey) {
    return (nodes ?? []).filter((node) => node.lane === laneKey).length;
}

/**
 * The name of a role's «Fjern» button. When the role has steps, the name says they go too — removing
 * a role deletes its steps in the editor (unsaved, so it can still be undone by not saving), and that
 * consequence must be perceivable before the press, not only visible as nodes vanishing (A11Y-127).
 */
export function laneRemoveLabel(ta, laneLabel, stepCount) {
    const name = laneLabel || (ta.quality_lane_unnamed ?? 'uten navn');

    if (stepCount === 0) {
        return (ta.quality_lane_remove ?? 'Fjern rollen «:label»').replace(':label', name);
    }

    const template = stepCount === 1
        ? (ta.quality_lane_remove_with_step ?? 'Fjern rollen «:label» og 1 steg i den')
        : (ta.quality_lane_remove_with_steps ?? 'Fjern rollen «:label» og :count steg i den');

    return template.replace(':label', name).replace(':count', String(stepCount));
}

/** What is said after a role was removed in the editor (A11Y-127): what went, and that it is not saved. */
export function laneRemovedAnnouncement(ta, laneLabel, stepCount) {
    const name = laneLabel || (ta.quality_lane_unnamed ?? 'uten navn');
    const template = stepCount === 0
        ? (ta.quality_lane_removed ?? 'Rollen «:label» er fjernet. Endringen er ikke lagret.')
        : stepCount === 1
            ? (ta.quality_lane_removed_with_step ?? 'Rollen «:label» og 1 steg i den er fjernet. Endringen er ikke lagret.')
            : (ta.quality_lane_removed_with_steps ?? 'Rollen «:label» og :count steg i den er fjernet. Endringen er ikke lagret.');

    return template.replace(':label', name).replace(':count', String(stepCount));
}

/** «Tekst, node 3» — a structure-table field named by its column and its row (A11Y-004, 1.3.1). */
export function structureFieldLabel(ta, column, rowKind, number) {
    return (ta.quality_structure_field ?? ':column, :row :number')
        .replace(':column', column)
        .replace(':row', rowKind)
        .replace(':number', String(number));
}

/**
 * A small key for what the AI has handed back to the Flyt tab — a flow proposal, a change proposal,
 * or why neither could be made. Read from the props, so it changes once per answer and not on
 * unrelated reloads (a dismissed suggestion reloads only the flash and keeps the same proposal).
 */
export function flowAiStateKey({ proposal = null, flowError = null, changeProposal = null, changeError = null } = {}) {
    if (flowError) {
        return `flow-error:${flowError.message ?? ''}:${(flowError.problems ?? []).length}`;
    }

    if (proposal) {
        return `proposal:${proposal.description ?? ''}:${(proposal.nodes ?? []).length}:${(proposal.edges ?? []).length}`;
    }

    if (changeError) {
        return `change-error:${changeError.message ?? ''}`;
    }

    if (changeProposal) {
        return `change:${changeProposal.base_hash ?? ''}:${changeProposal.instruction ?? ''}:${(changeProposal.changes ?? []).length}`;
    }

    return 'idle';
}

/**
 * What is said when the AI's answer arrives (4.1.3): that it is there and what kind it is, never the
 * text of it — the reader moves to it themselves, and focus is not moved. A refused change already
 * renders as role="alert", so it is not said twice.
 *
 * @returns {{ message: string, politeness?: 'polite'|'assertive' }|null}
 */
export function flowAiAnnouncement(next, ta = {}, { changeCount = 0 } = {}) {
    if (next.startsWith('flow-error:')) {
        return {
            message: ta.quality_flow_failed ?? 'Flyten kunne ikke brukes. Forklaringen står under beskrivelsen.',
            politeness: 'assertive',
        };
    }

    if (next.startsWith('proposal:')) {
        return { message: ta.quality_flow_proposal_ready ?? 'Forslaget til prosessflyt er klart. Ingenting er lagret ennå.' };
    }

    if (next.startsWith('change:')) {
        if (changeCount === 0) {
            return { message: ta.quality_change_proposal_empty ?? 'Endringsforslaget er klart, uten endringer å legge inn.' };
        }

        const template = changeCount === 1
            ? (ta.quality_change_proposal_ready_one ?? 'Endringsforslaget er klart: 1 endring.')
            : (ta.quality_change_proposal_ready ?? 'Endringsforslaget er klart: :count endringer.');

        return { message: template.replace(':count', String(changeCount)) };
    }

    return null;
}
