import { DECISIONS, HISTORY, OVERVIEW } from './reviewSections.js';

/**
 * Focus targets and status words for Ledelsens gjennomgang (WCAG Fase 6), kept pure so they are
 * unit-tested.
 */

/**
 * The heading a pane opens on. «Neste seksjon», «Forrige seksjon» and the section links on Oversikt
 * stay where they are when the pane changes — at the bottom of the old section, or inside an Oversikt
 * card that is gone — so focus is put on the new pane's heading instead (2.4.3, A11Y-014). The
 * section navigation itself keeps focus: it is still there, and says which pane is open.
 *
 * @param {string} pane OVERVIEW, DECISIONS, HISTORY or a section key.
 * @param {boolean} [hasReadiness] Whether Oversikt opens on the readiness card (a draft) or on
 *                                 «Hva krever oppmerksomhet».
 */
export function paneHeadingId(pane, hasReadiness = false) {
    if (pane === OVERVIEW) {
        return hasReadiness ? 'mr-readiness-heading' : 'mr-attention-heading';
    }

    if (pane === DECISIONS) {
        return 'mr-decisions-heading';
    }

    if (pane === HISTORY) {
        return 'mr-history-heading';
    }

    return `mr-section-${pane}`;
}

/**
 * Where focus goes after a row is removed (Fjern deltaker, Slett beslutning): the same action on the
 * next row, then on the one before, then the fallbacks (the add button, the list's heading). The row
 * pressed is gone, so its button is never a candidate.
 *
 * @param {Array<number|string>} ids  The rows, in page order, as they were when the action was taken.
 * @param {number|string} removedId
 * @param {(id: string) => string} elementId Maps a row id to the id of the control to land on.
 * @param {string[]} fallbacks
 * @returns {string[]} Element ids for refocusIfLost.
 */
export function removalFocusCandidates(ids, removedId, elementId, fallbacks = []) {
    const list = ids.map(String);
    const index = list.indexOf(String(removedId));
    const after = index === -1 ? [] : list.slice(index + 1);
    const before = index === -1 ? list : list.slice(0, index).reverse();

    return [...after, ...before].map((id) => elementId(id)).concat(fallbacks);
}

/**
 * The word a readiness item is read with (1.4.1, A11Y-024): ✓ and ○ are hidden from screen readers
 * and the difference is otherwise only colour and weight. The words are the ones Saksforløp uses.
 */
export function readinessStateText(done, ta = {}) {
    return done ? (ta.step_completed ?? 'fullført') : (ta.step_upcoming ?? 'gjenstår');
}
