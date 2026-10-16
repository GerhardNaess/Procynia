/**
 * What a failed axios request means to the person who made it, in their language.
 *
 * axios rejects with an Error whose own message is technical — «Request failed with status code
 * 500», «timeout of 120000ms exceeded», «Network Error». That text is for the developer console, never
 * for the page. What the page says depends on why the request failed:
 *  - validation (422 with Laravel's `errors`): the message for the field, under the field;
 *  - a refusal the server explained (422 with `message`/`error`, e.g. an AI quota or a failed
 *    generation): the server's own sentence, which Procynia writes for users;
 *  - not allowed (403), gone (404), session expired (401/419), too large (413), too many (429);
 *  - anything else — 5xx, timeouts, no network: a general «try again» that names nothing technical.
 * A failure is never turned into a success: the caller still shows that the action did not happen.
 *
 * Laravel's own 403/404/500 bodies carry a generic `message` («This action is unauthorized.», «Server
 * Error») in English. Those are not read; only a 422's message is, because only a 422 is one of ours.
 *
 * @param {unknown} error  what axios rejected with
 * @param {{ fallback?: string, texts?: object }} options
 *        texts: translations.common.request_errors (forbidden, not_found, session_expired,
 *        too_large, too_many, technical, network)
 * @returns {{ kind: 'validation'|'refused'|'forbidden'|'not_found'|'session_expired'|'too_large'|'too_many'|'technical'|'network',
 *             message: string, fieldErrors: Record<string, string> }}
 */
export function describeRequestError(error, { fallback = null, texts = {} } = {}) {
    const response = error?.response ?? null;
    const status = Number(response?.status ?? 0);
    const data = response?.data ?? null;
    const technical = fallback ?? texts.technical ?? 'Noe gikk galt, og endringen ble ikke utført. Prøv igjen om litt.';

    if (! response) {
        return {
            kind: 'network',
            message: texts.network ?? 'Fikk ikke kontakt med Procynia. Kontroller nettverket og prøv igjen.',
            fieldErrors: {},
        };
    }

    if (status === 422) {
        const fieldErrors = firstMessages(data?.errors);
        const first = Object.values(fieldErrors)[0] ?? null;

        if (first) {
            return { kind: 'validation', message: first, fieldErrors };
        }

        const explained = readableText(data?.message) ?? readableText(data?.error);

        return { kind: 'refused', message: explained ?? technical, fieldErrors: {} };
    }

    const byStatus = {
        401: ['session_expired', texts.session_expired ?? 'Økten din er utløpt. Last siden på nytt og logg inn igjen.'],
        419: ['session_expired', texts.session_expired ?? 'Økten din er utløpt. Last siden på nytt og logg inn igjen.'],
        403: ['forbidden', texts.forbidden ?? 'Du har ikke tilgang til å gjøre dette. Kontakt System Owner hvis dette er feil.'],
        404: ['not_found', texts.not_found ?? 'Fant ikke det du prøvde å endre. Det kan være slettet. Last siden på nytt.'],
        413: ['too_large', texts.too_large ?? 'Innholdet er for stort til å sendes. Gjør det mindre og prøv igjen.'],
        429: ['too_many', texts.too_many ?? 'For mange forespørsler på kort tid. Vent litt og prøv igjen.'],
    };

    if (byStatus[status]) {
        const [kind, message] = byStatus[status];

        return { kind, message, fieldErrors: {} };
    }

    return { kind: 'technical', message: technical, fieldErrors: {} };
}

/** Laravel sends a list of messages per field; the first is the one to show. */
function firstMessages(errors) {
    if (! errors || typeof errors !== 'object') {
        return {};
    }

    return Object.fromEntries(
        Object.entries(errors)
            .map(([field, messages]) => [field, readableText(Array.isArray(messages) ? messages[0] : messages)])
            .filter(([, message]) => message !== null),
    );
}

function readableText(value) {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}
