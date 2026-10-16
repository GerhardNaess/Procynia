/**
 * Accessibility rules for Etterlevelse og revisjon (WCAG plan, Fase 6), kept pure so they are
 * unit-tested in resources/js/Support/a11yPhase6Compliance.test.js. The hooks that apply them to the
 * pages live in useComplianceFocus.js.
 */

/** The id of a field's error text, derived from the field's own id so it is stable. */
export function fieldErrorId(id) {
    return `${id}-error`;
}

/**
 * aria-describedby and aria-invalid for a field whose label, hint and error the page draws itself
 * (3.3.1, A11Y-013). The hint ids come first and the error last: the error is the last thing heard
 * before input. Nothing is set that has nothing to say, so a valid field keeps a clean description.
 *
 * @param {string}   id       The control's id.
 * @param {string}   [error]  The field's error message, if any.
 * @param {string[]} [hints]  Ids of the texts that describe the field.
 */
export function fieldStateProps(id, error = null, hints = []) {
    const ids = [...hints.filter(Boolean), ...(error ? [fieldErrorId(id)] : [])];

    return {
        'aria-describedby': ids.length > 0 ? ids.join(' ') : undefined,
        'aria-invalid': error ? true : undefined,
    };
}

/**
 * The same for a group of radios or checkboxes (1.3.1, A11Y-120): the fieldset is described by the
 * hint and the error; the options themselves carry aria-invalid (optionInvalid), which is where
 * focusFirstInvalidField lands after a rejected submit.
 */
export function groupStateProps(id, error = null, hints = []) {
    return { 'aria-describedby': fieldStateProps(id, error, hints)['aria-describedby'] };
}

/** aria-invalid for each checkbox of a group with an error. */
export function optionInvalid(error) {
    return error ? true : undefined;
}

/**
 * A group of radios (A11Y-120, A11Y-121): the fieldset becomes the radiogroup its legend names, and
 * carries what a radio cannot — aria-required, and aria-invalid when the choice was refused. A
 * rejected submit focuses the group, which is then read with its question, hint and error.
 */
export function radioGroupProps(id, error = null, { required = false, hints = [] } = {}) {
    return {
        role: 'radiogroup',
        ...groupStateProps(id, error, hints),
        'aria-invalid': error ? true : undefined,
        'aria-required': required ? true : undefined,
    };
}

/**
 * Where focus goes after a row has been removed (2.4.3, A11Y-014): the next row's control, the
 * previous row's, then the fallbacks — the list's heading or its primary action. Each row may
 * offer several controls (Rediger, Slett); `idFors` lists them in order of preference.
 *
 * @param {Array<string|number>} ids      The rows, in display order, before the removal.
 * @param {string|number}        removed  The row that goes.
 * @param {Array<(id) => string>} idFors  Element ids for a row's controls.
 * @param {string[]}             [fallbacks]
 * @returns {string[]} Element ids in order of preference.
 */
export function removalFocusCandidates(ids, removed, idFors, fallbacks = []) {
    const list = ids.map(String);
    const index = list.indexOf(String(removed));
    const neighbours = index === -1 ? [] : [list[index + 1], list[index - 1]].filter((id) => id !== undefined);

    return [...neighbours.flatMap((id) => idFors.map((idFor) => idFor(id))), ...fallbacks];
}

/**
 * What a change of the open inline panel means for focus. Pure, so the rule is unit-tested.
 *
 *   { open: key }   a panel opened (or another took its place): focus goes into it.
 *   { close: key }  the panel `key` closed: focus goes back to what opened it.
 *   null            nothing changed — a re-render, a poll, typing — and focus is left alone.
 */
export function panelTransition(previous, next) {
    if (previous === next) {
        return null;
    }

    return next === null || next === undefined ? { close: previous } : { open: next };
}

/**
 * One step of «focus the first error after a rejected submit» (useFocusOnRejectedSubmit). A submit
 * is the processing flag going on; it was rejected when the flag goes off with errors in the bag.
 * Errors appear no other way, so typing or a re-render never moves focus.
 *
 * @returns {{ pending: boolean, focus: boolean }}
 */
export function rejectedSubmitStep(pending, processing, errors) {
    if (processing) {
        return { pending: true, focus: false };
    }

    const rejected = Boolean(errors) && Object.values(errors).some(Boolean);

    return pending && rejected ? { pending: false, focus: true } : { pending, focus: false };
}

/** The element id an in-page address names (#finding-12, #check-3), or null for anything else. */
export function inPageTargetId(hash) {
    const match = /^#((?:finding|check)-\d+)$/.exec(String(hash ?? ''));

    return match ? match[1] : null;
}

/** How many requirements a Kravbibliotek tree (complianceLibrary buildTree/filterTree) holds. */
export function countRequirementNodes(nodes = []) {
    return nodes.reduce((total, node) => total + (node.kind === 'requirement' ? 1 : 0) + countRequirementNodes(node.children ?? []), 0);
}

/**
 * The spoken hit count after a search or filter in Kravbiblioteket (4.1.3, A11Y-111). Nothing while
 * nothing narrows the tree: there are no hits to count then.
 */
export function libraryHitAnnouncement(narrowing, count, texts = {}) {
    if (! narrowing) {
        return null;
    }

    if (count === 0) {
        return texts.none ?? 'Ingen krav passer søket.';
    }

    return count === 1
        ? (texts.one ?? '1 krav passer søket')
        : (texts.many ?? ':count krav passer søket').replace(':count', String(count));
}

/**
 * A key for the search and filters a register was last loaded with. It changes when — and only
 * when — the user searches or filters again, so the hit count is spoken once per search, never on
 * the first render and never when a group is opened or the view switched.
 */
export function filterSignature(filters = {}, keys = []) {
    return keys.map((key) => `${key}=${filters?.[key] ?? ''}`).join('&');
}

/** The heading level one below `level`, never past h6 (1.3.1, A11Y-119). */
export function nextHeadingLevel(level) {
    return Math.min(6, Number(level) + 1);
}
