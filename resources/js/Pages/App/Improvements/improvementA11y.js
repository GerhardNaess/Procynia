/**
 * Pure accessibility rules for Avvik og forbedringer (docs/wcag-accessibility-plan.md, Fase 6): where
 * focus goes when a panel closes or a tiltak changes. Unit-tested in
 * resources/js/Support/a11yPhase6Improvements.test.js; the components only carry them out through
 * refocusIfLost, so nothing moves unless focus was actually lost.
 */

/** A tiltak card's buttons, in the order the card shows them. */
export const ACTION_BUTTON_KINDS = ['start', 'complete', 'verify', 'edit', 'cancel', 'reopen', 'delete'];

/** The id of a tiltak card's button. */
export const actionButtonId = (actionId, kind) => `improvement-action-${actionId}-${kind}`;

/**
 * Focus after a tiltak's panel closes, or after Start: the button that opened it, else whatever the
 * tiltak offers now in card order («Fullfør tiltak» became «Verifiser effekt» and «Gjenåpne tiltak»),
 * else the tiltak's title, else the section heading.
 *
 * @param {number|string} actionId
 * @param {string|null} kind The button that was pressed.
 */
export function actionFocusCandidates(actionId, kind = null) {
    const ordered = ACTION_BUTTON_KINDS.filter((candidate) => candidate !== kind).map((candidate) => actionButtonId(actionId, candidate));

    return [
        ...(kind ? [actionButtonId(actionId, kind)] : []),
        ...ordered,
        `improvement-action-${actionId}-title`,
        'improvement-actions-heading',
    ];
}

/** The case's lifecycle buttons under Behandling, in the order they are shown. */
export const CASE_STATUS_BUTTONS = {
    start: 'improvement-start-button',
    close: 'improvement-close-button',
    cancel: 'improvement-cancel-button',
    reopen: 'improvement-reopen-button',
};

/**
 * Focus after a case panel closes: Rediger returns to «Rediger»; a lifecycle form returns to the
 * button that opened it, else the lifecycle buttons the case offers now (after Lukk sak only
 * «Gjenåpne» is left), else the Behandling heading.
 *
 * @param {'edit'|'close'|'cancel'|'reopen'|'start'|null} panel
 */
export function casePanelFocusCandidates(panel) {
    if (panel === 'edit') {
        return ['improvement-edit-button', 'improvement-heading'];
    }

    const pressed = CASE_STATUS_BUTTONS[panel];
    const others = Object.values(CASE_STATUS_BUTTONS).filter((id) => id !== pressed);

    return [...(pressed ? [pressed] : []), ...others, 'improvement-handling-heading'];
}
