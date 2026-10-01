/**
 * Putting the register's name into the text that leaves a place for it.
 *
 * Live search runs against one register at a time — Doffin or TED, never both — so "treff fra
 * Doffin" printed over a page of TED results is a plain untruth, and so is "Åpne i Doffin" on a
 * link that opens ted.europa.eu. Rather than branch at every label, the lang files leave a place
 * for the register (`:source`) and it is filled once, from what the backend said the search was
 * in.
 *
 * Only `:source` is touched. `live_capped_warning` also carries `:total` and `:accessible`, which
 * are numbers the page knows later and fills itself; passing the string through here must leave
 * them alone.
 */
export function withRegisterName(value, registerName) {
    if (typeof value === 'string') {
        return value.replaceAll(':source', registerName);
    }

    if (Array.isArray(value)) {
        return value.map((entry) => withRegisterName(entry, registerName));
    }

    if (value && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value).map(([key, entry]) => [key, withRegisterName(entry, registerName)]),
        );
    }

    return value;
}
