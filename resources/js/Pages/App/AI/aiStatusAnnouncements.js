/**
 * What a screen reader hears while the AI workspace works in the background (WCAG 4.1.3;
 * docs/wcag-accessibility-plan.md A11Y-045, A11Y-112). Pure, so `npm run test:unit` covers it.
 *
 * The extraction banner is reloaded every few seconds while a document is processed, and its text
 * changes with almost every reload («3 av 10 deler ferdig»). It is therefore not a live region. The
 * page reduces it to one of three states and speaks only when the state changes:
 *
 *   none → processing     «Kravuttrekket er startet.»
 *   processing → none     «Kravuttrekket er ferdig. N krav er hentet ut.»
 *   anything → failed     «Kravuttrekket feilet.» (assertive: the user has to act)
 *
 * The state the page opened in is never announced; Support/statusAnnouncement.js skips it.
 */

/** The banner reduced to the state that is announced: 'processing', 'failed' or 'none'. */
export function extractionState(banner) {
    if (banner?.state === 'processing' || banner?.state === 'failed') {
        return banner.state;
    }

    return 'none';
}

/**
 * @param {string} previous
 * @param {string} next
 * @param {{ texts?: object, extractedCount?: number }} context `texts` is the shared `a11y` namespace.
 * @returns {{ message: string, politeness: 'polite'|'assertive' }|null}
 */
export function extractionAnnouncement(previous, next, { texts = {}, extractedCount = 0 } = {}) {
    if (next === 'failed' && previous !== 'failed') {
        return { message: texts.ai_extraction_failed ?? 'Kravuttrekket feilet.', politeness: 'assertive' };
    }

    if (next === 'processing' && previous !== 'processing') {
        return { message: texts.ai_extraction_started ?? 'Kravuttrekket er startet.', politeness: 'polite' };
    }

    if (previous === 'processing' && next === 'none') {
        const template = texts.ai_extraction_completed ?? 'Kravuttrekket er ferdig. :count krav er hentet ut.';

        return { message: template.replace(':count', String(extractedCount)), politeness: 'polite' };
    }

    return null;
}
