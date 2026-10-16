/**
 * Pure accessibility rules for Risiko and Avvik og forbedringer (docs/wcag-accessibility-plan.md,
 * Fase 6). No React, no DOM: what is spoken and where focus goes are decided here and unit-tested in
 * resources/js/Support/a11yPhase6Risk.test.js; the components only carry them out.
 */

const fill = (text, values) => Object.entries(values)
    .reduce((result, [name, value]) => result.replace(`:${name}`, String(value ?? '')), text ?? '');

/**
 * Where focus goes when an item leaves a list (2.4.3, A11Y-014): the next item, else the previous
 * one, else the fallbacks in order (the section's primary action, its heading). Ids, resolved after
 * the list has re-rendered, so the caller passes them to refocusIfLost.
 *
 * @param {string[]} ids       The items' focus targets, in list order, as they were before removal.
 * @param {string}   removedId The one that is going.
 * @param {string[]} [fallbacks]
 * @returns {string[]}
 */
export function removalFocusCandidates(ids, removedId, fallbacks = []) {
    const index = ids.indexOf(removedId);

    if (index === -1) {
        return [...fallbacks];
    }

    return [ids[index + 1], ids[index - 1], ...fallbacks].filter(Boolean);
}

/**
 * The number of hits after a search or filter on a register, spoken once (4.1.3, A11Y-111). Built
 * from the register's own visible count text, so what is heard is what is shown.
 *
 * @param {number} count
 * @param {{ many?: string, one?: string, none?: string }} templates `:count` in many.
 */
export function resultCountMessage(count, { many = ':count', one = null, none = null } = {}) {
    const number = Number(count) || 0;

    if (number === 0 && none) {
        return none;
    }

    if (number === 1 && one) {
        return one;
    }

    return fill(many, { count: number });
}

/**
 * The calculated level in Ny risikovurdering, as a sentence: «Beregnet nivå: Høy. Score 12». Null
 * until both scales are chosen — nothing is said for a half-filled pair. The level is the server's
 * band (previewLevel); this only words it.
 *
 * @param {{ level: string, score: number }|null} result
 * @param {{ label?: string, levels?: object, score?: string }} ta translations.risk.assessment
 */
export function previewAnnouncement(result, ta = {}) {
    if (! result) {
        return null;
    }

    const level = ta.levels?.[result.level] ?? result.level;
    const score = fill(ta.score ?? 'Score :score', { score: result.score });

    return `${ta.level_preview ?? 'Beregnet nivå'}: ${level}. ${score}`;
}

/** The key a preview is compared by, so the same level is not spoken twice. */
export function previewKey(result) {
    return result ? `${result.level}:${result.score}` : null;
}

/**
 * Focus after a risk tiltak's own panel closes or its state changes: the button that opened it,
 * then the buttons the tiltak has in its next state (Marker som fullført ↔ Gjenåpne), then the
 * tiltak's title and the section heading. Only used through refocusIfLost.
 *
 * @param {number|string} actionId
 * @param {string} [preferred] 'edit' | 'complete' | 'reopen'
 */
export function riskActionFocusCandidates(actionId, preferred = null) {
    const ids = ['edit', 'complete', 'reopen'].map((kind) => `risk-action-${actionId}-${kind}`);
    const first = preferred ? [`risk-action-${actionId}-${preferred}`] : [];

    return [...first, ...ids.filter((id) => ! first.includes(id)), `risk-action-${actionId}-name`, 'risk-treatment-heading'];
}
