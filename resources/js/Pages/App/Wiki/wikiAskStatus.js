/**
 * What Spør Wiki says while it works and when it answers (WCAG 4.1.3). Pure, so it is unit-tested.
 *
 * Only the kind of answer is spoken — «Svaret er klart.», «Fant ikke nok dokumentert informasjon.» —
 * never the answer text, which can be long; the reader moves to it themselves. Focus is not moved.
 */

/** A small key for the page's state: asking, or which result is showing (and for which question). */
export function askStateKey(processing, result) {
    if (processing) {
        return 'asking';
    }

    if (! result) {
        return 'idle';
    }

    return `result:${result.answer_status ?? 'answered'}:${result.question ?? ''}:${(result.answer ?? '').length}`;
}

export function askAnnouncement(next, tw = {}, ta = {}) {
    if (next === 'asking') {
        return tw.ask_searching ?? 'Søker i Wiki-en...';
    }

    if (! next.startsWith('result:')) {
        return null;
    }

    const status = next.split(':')[1];

    if (status === 'insufficient_evidence') {
        return tw.ask_insufficient_evidence ?? 'Jeg finner ikke tilstrekkelig dokumentert informasjon i Wiki-en til å svare sikkert på dette.';
    }

    if (status === 'conflicting_evidence') {
        return tw.ask_conflicting_evidence ?? 'Wiki-en inneholder motstridende opplysninger om dette spørsmålet.';
    }

    return ta.wiki_ask_answer_ready ?? 'Svaret er klart.';
}
