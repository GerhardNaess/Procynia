/**
 * Editing one step of a flow from the diagram.
 *
 * Not a second model. The diagram has no state of its own — it is drawn from the blueprint — so a
 * step edited "on the diagram" is the blueprint's node edited and the picture redrawn from it. These
 * functions are the whole of that: which nodes may be edited this way, and the blueprint with one of
 * them changed. Saving is the same PUT the structure editor uses, against the same working version.
 *
 * Only what a step says and who does it. Type, arrows, position and subprocess stay with "Rediger
 * struktur manuelt": changing them changes the shape of the flow, and that is not a correction made
 * from a click on a box.
 */

/** The kinds of node that are an activity the user can reword or reassign. Start and end are not. */
export const EDITABLE_STEP_TYPES = ['step', 'decision'];

/** Whether a node, as drawn or as stored, can be opened for editing from the diagram. */
export function isEditableStep(node) {
    return node !== null && node !== undefined && EDITABLE_STEP_TYPES.includes(node.type ?? 'step');
}

/**
 * The nodes with one step's text and role replaced, everything else as it was.
 *
 * The label is trimmed, because what is saved is what is drawn and a trailing space is invisible on
 * both. A lane that does not exist is refused rather than written: normalise() on the server would
 * otherwise move the step to a lane the user did not choose.
 */
export function withStepEdited(nodes, key, { label, lane }) {
    return nodes.map((node) => (node.key === key
        ? { ...node, label: String(label ?? '').trim(), lane }
        : node));
}

/** Whether an edit can be saved: it says something, and its role is one of the flow's roles. */
export function stepEditIsValid({ label, lane }, lanes) {
    return String(label ?? '').trim() !== '' && lanes.some((candidate) => candidate.key === lane);
}
