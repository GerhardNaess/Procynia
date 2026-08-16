export function normalizeWikiAnswerText(value) {
    return String(value ?? '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

export function getWikiAnswerPageId(source) {
    const pageId = Number(source?.enterprise_wiki_page_id ?? source?.page_id ?? source?.id);

    return Number.isInteger(pageId) && pageId > 0 ? pageId : null;
}

export function dedupeWikiAnswerSourcesByPageId(sources) {
    if (!Array.isArray(sources)) {
        return [];
    }

    const dedupedSources = [];
    const seenPageIds = new Set();

    sources.forEach((source) => {
        if (!source || typeof source !== 'object') {
            return;
        }

        const pageId = getWikiAnswerPageId(source);

        if (pageId === null || seenPageIds.has(pageId)) {
            return;
        }

        seenPageIds.add(pageId);
        dedupedSources.push(source);
    });

    return dedupedSources;
}

export function buildWikiAnswerCopyText(answerText) {
    return normalizeWikiAnswerText(answerText);
}

/**
 * Purpose: Turn the answer as it is actually rendered on screen into clipboard HTML, with every
 *          Wiki figure inlined as a data: URI so it survives a paste into Word.
 * Inputs: The rendered answer element, and a fetcher that resolves one image URL to a data: URI.
 * Returns: An HTML string, or '' when there is nothing renderable to copy.
 *
 * Reading the rendered DOM rather than re-rendering the Markdown a second way guarantees the paste
 * matches what the user sees — tables included. Figures must be inlined because their <img> src is
 * an authenticated, customer-scoped app route: Word has no session, so a plain URL would paste as
 * a broken image. A figure whose bytes cannot be fetched is dropped from the HTML rather than
 * pasted broken; the text around it is unaffected.
 */
export async function buildWikiAnswerCopyHtml(renderedElement, fetchImageAsDataUri) {
    if (!renderedElement || typeof renderedElement.cloneNode !== 'function') {
        return '';
    }

    const clone = renderedElement.cloneNode(true);
    const images = Array.from(clone.querySelectorAll('img'));

    await Promise.all(images.map(async (image) => {
        const source = image.getAttribute('src') ?? '';
        const dataUri = source === '' ? null : await fetchImageAsDataUri(source);

        if (typeof dataUri === 'string' && dataUri !== '') {
            image.setAttribute('src', dataUri);
            image.setAttribute('style', 'max-width: 100%; height: auto;');

            return;
        }

        image.remove();
    }));

    return clone.innerHTML;
}

/**
 * Purpose: Write the rich (HTML + plain text) clipboard flavours.
 * Inputs: A PROMISE of the HTML (not the HTML itself) and the plain text.
 * Returns: true when the browser accepted the write, false for ANY reason it did not. Never throws.
 *
 * The promise is the whole point. Every clipboard write requires transient user activation, and
 * that activation is spent by the time the answer's figures have been fetched and base64-encoded —
 * roughly half a megabyte of image data. Awaiting that work first and writing afterwards is what
 * made "Kopier svar" fail outright: clipboard.write, clipboard.writeText and execCommand all
 * refuse once the gesture has expired, so the button reported "Kunne ikke kopiere" even though
 * nothing was wrong with the answer or the figures.
 *
 * ClipboardItem accepts Promise<Blob> values, so navigator.clipboard.write() is CALLED
 * synchronously inside the click and the image work resolves under the still-valid gesture. Callers
 * must therefore hand this function a promise and must not await anything before it.
 */
export async function writeRichClipboardPayload(htmlPromise, plainText) {
    if (typeof window === 'undefined' || typeof window.ClipboardItem === 'undefined') {
        return false;
    }

    if (typeof navigator === 'undefined' || typeof navigator.clipboard?.write !== 'function') {
        return false;
    }

    try {
        await navigator.clipboard.write([
            new window.ClipboardItem({
                'text/html': Promise.resolve(htmlPromise)
                    .then((html) => new Blob([html], { type: 'text/html' })),
                'text/plain': new Blob([plainText], { type: 'text/plain' }),
            }),
        ]);

        return true;
    } catch (error) {
        return false;
    }
}
/**
 * Purpose: Write the answer as plain text — the flavour that must always be available.
 * Returns: true when the text reached the clipboard, false otherwise. Never throws.
 */
export async function writePlainClipboardPayload(plainText) {
    if (typeof navigator === 'undefined' || typeof navigator.clipboard?.writeText !== 'function') {
        return false;
    }

    try {
        await navigator.clipboard.writeText(plainText);

        return true;
    } catch (error) {
        return false;
    }
}

/**
 * Purpose: Put one answer on the clipboard, as richly as the browser allows.
 * Inputs: A promise of the rendered HTML (null when nothing is rendered — the Markdown editor is
 *         open), the plain text, and the writers (injectable for testing).
 * Returns: {copied, reason} — reason names the flavour that succeeded, or why none did, so the UI
 *          can tell the user something more useful than "it failed".
 *
 * Order matters and is not negotiable: the rich write goes first because it is the only one that
 * can be started synchronously with work still pending. Plain text is tried next; it needs no
 * pending work, so it is cheap, but it still needs the gesture. Callers must not await before
 * calling this.
 */
export async function copyWikiAnswerToClipboard({
    htmlPromise = null,
    plainText,
    writeRich = writeRichClipboardPayload,
    writePlain = writePlainClipboardPayload,
}) {
    if (htmlPromise !== null && await writeRich(htmlPromise, plainText)) {
        return { copied: true, reason: 'rich' };
    }

    if (await writePlain(plainText)) {
        return { copied: true, reason: 'plain' };
    }

    const hasAsyncClipboard = typeof navigator !== 'undefined' && Boolean(navigator.clipboard);

    return { copied: false, reason: hasAsyncClipboard ? 'denied' : 'unavailable' };
}
