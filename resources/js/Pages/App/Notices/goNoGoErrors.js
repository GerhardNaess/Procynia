/**
 * A refused Go/No-go save, read back onto the criteria it is about.
 *
 * The page sends one answer per criterion, in the order the criteria are shown, so Laravel names a
 * refused reasoning `answers.3.comment`. That index is the criterion's place in the list: the message
 * belongs under that criterion's reasoning, and the summary beside «Lagre vurdering» says which
 * criteria to correct. A refusal that is about the assessment as a whole (`template_id`: the case has
 * left Go/No-go, the template was deactivated) is said as it is.
 */

// The same limit as GoNoGoAssessmentController::COMMENT_MAX_LENGTH.
export const COMMENT_MAX_LENGTH = 2000;

// The length is shown only once the reasoning nears the limit, so the field stays quiet while typing.
export const COMMENT_LENGTH_SHOWN_FROM = 1800;

const ANSWER_FIELD = /^answers\.(\d+)\.(comment|selected_value)$/;

/**
 * @param {Record<string, string>} errors   Inertia's errors from the rejected visit
 * @param {Array<{id: number, title: string}>} criteria  in the order they were sent
 * @returns {{ byCriterion: Record<string, string>, general: string|null }}
 */
export function criterionErrors(errors, criteria) {
    const byCriterion = {};
    let general = null;

    for (const [key, message] of Object.entries(errors ?? {})) {
        if (! message) {
            continue;
        }

        const match = key.match(ANSWER_FIELD);
        const criterion = match ? criteria[Number(match[1])] : null;

        if (criterion) {
            byCriterion[criterion.id] ??= String(message);
        } else {
            general ??= String(message);
        }
    }

    return { byCriterion, general };
}

/**
 * The sentence beside «Lagre vurdering»: the general refusal, or which criteria to correct, or the
 * plain «not saved» when the server said nothing more.
 */
export function saveErrorMessage({ byCriterion, general }, criteria, texts = {}) {
    if (general) {
        return general;
    }

    const titles = criteria.filter((criterion) => byCriterion[criterion.id]).map((criterion) => `«${criterion.title}»`);

    if (titles.length > 0) {
        return (texts.save_failed_criteria ?? 'Vurderingen ble ikke lagret. Rett opp det som er markert ved: :criteria. Det du har fylt ut, er beholdt.')
            .replace(':criteria', titles.join(', '));
    }

    return texts.save_failed ?? 'Vurderingen ble ikke lagret. Prøv igjen.';
}
