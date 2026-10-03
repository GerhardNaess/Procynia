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

/**
 * Whether a new activity may be put on this arrow from the diagram.
 *
 * Both ends must exist, and the arrow must not leave a decision: that is a branch, and a branch is
 * the decision's own statement — "ja" goes there — which a click between two boxes should not
 * reword. Branches stay with "Rediger struktur manuelt".
 */
export function canInsertStepOn(edge, nodes) {
    const from = nodes.find((node) => node.key === edge.from);
    const to = nodes.find((node) => node.key === edge.to);

    return from !== undefined && to !== undefined && (from.type ?? 'step') !== 'decision';
}

/**
 * A key no node in the flow has. Slug-shaped, so the server keeps it rather than renaming it.
 */
export function freshStepKey(nodes, base = 'step') {
    const taken = new Set(nodes.map((node) => node.key));
    let index = nodes.length + 1;

    while (taken.has(`${base}-${index}`)) {
        index += 1;
    }

    return `${base}-${index}`;
}

/**
 * The flow with one activity put on one arrow: from → new → to instead of from → to.
 *
 * The arrow is replaced where it stood rather than removed and appended, and the node goes in right
 * after the step it follows, so the payload still reads in the order the flow runs and a diff of the
 * save shows exactly one step added. A label on the old arrow stays on the leg out of the step it
 * described. Any other arrow between the same two steps is left alone: only the one clicked moves.
 */
export function withStepInserted({ nodes, edges }, edge, { key, label, lane }) {
    const step = { key, lane, type: 'step', label: String(label ?? '').trim(), description: null };
    const fromIndex = nodes.findIndex((node) => node.key === edge.from);
    const at = fromIndex === -1 ? nodes.length : fromIndex + 1;
    const edgeIndex = edges.findIndex((candidate) => candidate.from === edge.from
        && candidate.to === edge.to
        && (candidate.label ?? null) === (edge.label ?? null));

    const legs = [
        { from: edge.from, to: key, label: edge.label ?? null },
        { from: key, to: edge.to, label: null },
    ];

    return {
        nodes: [...nodes.slice(0, at), step, ...nodes.slice(at)],
        edges: edgeIndex === -1
            ? [...edges, ...legs]
            : [...edges.slice(0, edgeIndex), ...legs, ...edges.slice(edgeIndex + 1)],
    };
}

/**
 * Where the "+" for an arrow is drawn: halfway along the line as routed, so it sits on the arrow
 * whether it runs straight, changes lane or loops back underneath. A labelled arrow has its label
 * at the middle, so its "+" moves on towards the arrowhead instead of covering the word.
 */
export function insertAnchor(points, labelled = false) {
    if (! Array.isArray(points) || points.length < 2) {
        return null;
    }

    const lengths = points.slice(1).map((point, index) => Math.hypot(point.x - points[index].x, point.y - points[index].y));
    let remaining = lengths.reduce((sum, length) => sum + length, 0) * (labelled ? 0.75 : 0.5);

    for (let index = 0; index < lengths.length; index += 1) {
        if (remaining <= lengths[index] && lengths[index] > 0) {
            const ratio = remaining / lengths[index];

            return {
                x: points[index].x + ((points[index + 1].x - points[index].x) * ratio),
                y: points[index].y + ((points[index + 1].y - points[index].y) * ratio),
            };
        }

        remaining -= lengths[index];
    }

    return { ...points[points.length - 1] };
}

/**
 * The branches out of one decision, as the dialog edits them: what each says and where it goes.
 *
 * A branch is nothing but an arrow out of the decision — there is no branch record to keep in step
 * with the edges. Reading them is filtering the edges; saving them is replacing those edges.
 */
export function decisionBranches(edges, key) {
    return edges
        .filter((edge) => edge.from === key)
        .map((edge) => ({ label: edge.label ?? '', to: edge.to }));
}

/**
 * The steps a branch may lead to: any existing node but the decision itself and a start.
 *
 * normalise() drops an arrow from a node to itself, and an arrow into the start is the validator's
 * `start_has_incoming` — offering either would let the user pick something that is lost or wrong
 * the moment it is saved. No step is created here; a branch only ever points at one that exists.
 */
export function branchTargets(nodes, key) {
    return nodes.filter((node) => node.key !== key && (node.type ?? 'step') !== 'start');
}

/**
 * What stands between these branches and a decision the flow validator would accept.
 *
 * The same three rules as QualityProcessFlowValidator::decisionProblems() — at least two ways out,
 * every one named, no two named alike (ignoring case) — under the same problem keys, so the dialog
 * says what approval would say, only before the save rather than after. Plus the one thing the
 * validator never sees because normalise() has already dropped it: a branch with no valid target.
 */
export function branchProblems(branches, nodes, key) {
    const problems = [];
    const targets = new Set(branchTargets(nodes, key).map((node) => node.key));
    const labels = branches.map((branch) => String(branch.label ?? '').trim());

    if (branches.length < 2) {
        problems.push('decision_needs_two_outcomes');
    }

    if (labels.some((label) => label === '')) {
        problems.push('decision_outcome_unnamed');
    }

    const named = labels.filter((label) => label !== '').map((label) => label.toLocaleLowerCase());

    if (new Set(named).size !== named.length) {
        problems.push('decision_outcomes_repeat');
    }

    if (branches.some((branch) => ! targets.has(branch.to))) {
        problems.push('branch_without_target');
    }

    return problems;
}

/**
 * The flow with one decision's outgoing arrows replaced by these branches.
 *
 * They go in where the decision's first arrow stood, so the payload keeps reading in flow order and
 * a diff of the save shows the branches changing and nothing else moving. Labels are trimmed: what
 * is saved is what is drawn. Every other arrow, and every node, is left exactly as it was.
 */
export function withDecisionBranches(edges, key, branches) {
    const replacement = branches.map((branch) => ({
        from: key,
        to: branch.to,
        label: String(branch.label ?? '').trim(),
    }));
    const at = edges.findIndex((edge) => edge.from === key);
    const others = edges.filter((edge) => edge.from !== key);
    const insertAt = at === -1 ? others.length : edges.slice(0, at).filter((edge) => edge.from !== key).length;

    return [...others.slice(0, insertAt), ...replacement, ...others.slice(insertAt)];
}

/**
 * Whether every run of the flow passes this node: with it taken out, no end can be reached from a
 * start any more. That is what "on the main line" means here, and it is the whole of the rule that
 * keeps a move out of a decision's branches — a step one branch passes and another does not is not
 * on it, and nor is a step only a loop back reaches.
 */
function isOnEveryPath({ nodes, edges }, key) {
    const starts = nodes.filter((node) => (node.type ?? 'step') === 'start').map((node) => node.key);
    const ends = new Set(nodes.filter((node) => (node.type ?? 'step') === 'end').map((node) => node.key));

    const reachesEnd = (without) => {
        const seen = new Set(starts.filter((start) => start !== without));
        const queue = [...seen];

        while (queue.length > 0) {
            const current = queue.shift();

            if (ends.has(current)) {
                return true;
            }

            edges.forEach((edge) => {
                if (edge.from === current && edge.to !== without && ! seen.has(edge.to)) {
                    seen.add(edge.to);
                    queue.push(edge.to);
                }
            });
        }

        return false;
    };

    return reachesEnd(null) && ! reachesEnd(key);
}

/**
 * The one arrow into and the one arrow out of a step that can be moved from the diagram, or null.
 *
 * Only a plain activity on the main line, joined to it by exactly one arrow in and one out, and not
 * the first step of a branch. Anything else — a step with a loop coming back into it, a step inside
 * a branch, a start, an end or a decision — is not moved from here: lifting it out could not be
 * done by joining one arrow to another, and the flow would be left with a gap or a fork.
 */
function moveSeam(flow, key) {
    const node = flow.nodes.find((candidate) => candidate.key === key);

    if (node === undefined || (node.type ?? 'step') !== 'step') {
        return null;
    }

    const incoming = flow.edges.filter((edge) => edge.to === key);
    const outgoing = flow.edges.filter((edge) => edge.from === key);

    if (incoming.length !== 1 || outgoing.length !== 1) {
        return null;
    }

    const previous = flow.nodes.find((candidate) => candidate.key === incoming[0].from);
    const next = flow.nodes.find((candidate) => candidate.key === outgoing[0].to);

    if (previous === undefined || next === undefined || previous.key === next.key
        || (previous.type ?? 'step') === 'decision' || ! isOnEveryPath(flow, key)) {
        return null;
    }

    return { incoming: incoming[0], outgoing: outgoing[0] };
}

/** Whether this step can be moved from the diagram at all. */
export function canMoveStep(flow, key) {
    return moveSeam(flow, key) !== null;
}

/** The key of the step a movable step stands after now, or null when it cannot be moved. */
export function stepBefore(flow, key) {
    return moveSeam(flow, key)?.incoming.from ?? null;
}

/**
 * The flow with one step lifted out: the arrow into it now goes where the arrow out of it went.
 *
 * Kept where the arrow in stood. If that arrow already exists, it is not added a second time.
 */
function withStepLiftedOut({ nodes, edges }, key) {
    const seam = moveSeam({ nodes, edges }, key);
    const bridge = { from: seam.incoming.from, to: seam.outgoing.to, label: seam.incoming.label ?? null };
    const exists = edges.some((edge) => edge.from === bridge.from && edge.to === bridge.to);

    return {
        nodes: nodes.filter((node) => node.key !== key),
        edges: edges.flatMap((edge) => {
            if (edge === seam.incoming) {
                return exists ? [] : [bridge];
            }

            return edge === seam.outgoing ? [] : [edge];
        }),
    };
}

/**
 * The steps a movable step may be put after: a start or a plain activity on the main line with
 * exactly one arrow out, other than the step itself and the one it already follows.
 *
 * Judged on the flow with the step already lifted out, because that is the flow it is put back into.
 * One arrow out is what makes "after" mean one place: a decision has a branch for every outcome and
 * putting the step after it would be putting it into one of them.
 */
export function moveTargets(flow, key) {
    const seam = moveSeam(flow, key);

    if (seam === null) {
        return [];
    }

    const lifted = withStepLiftedOut(flow, key);

    return lifted.nodes.filter((node) => ['start', 'step'].includes(node.type ?? 'step')
        && node.key !== seam.incoming.from
        && lifted.edges.filter((edge) => edge.from === node.key).length === 1
        && isOnEveryPath(lifted, node.key));
}

/**
 * The flow with one step moved to stand right after another: lifted out with the arrows on either
 * side joined, then put on the arrow out of the step it now follows — the same as a new activity
 * is put on an arrow. The node moves too, to right after that step, so the payload keeps reading in
 * the order the flow runs. Returns null for a move this is not offered for, rather than guessing.
 */
export function withStepMoved(flow, key, afterKey) {
    if (! moveTargets(flow, key).some((node) => node.key === afterKey)) {
        return null;
    }

    const seam = moveSeam(flow, key);
    const node = flow.nodes.find((candidate) => candidate.key === key);
    const lifted = withStepLiftedOut(flow, key);
    const edgeIndex = lifted.edges.findIndex((edge) => edge.from === afterKey);
    const edge = lifted.edges[edgeIndex];
    const at = lifted.nodes.findIndex((candidate) => candidate.key === afterKey) + 1;

    return {
        nodes: [...lifted.nodes.slice(0, at), node, ...lifted.nodes.slice(at)],
        edges: [
            ...lifted.edges.slice(0, edgeIndex),
            { from: afterKey, to: key, label: edge.label ?? null },
            { from: key, to: edge.to, label: seam.outgoing.label ?? null },
            ...lifted.edges.slice(edgeIndex + 1),
        ],
    };
}
