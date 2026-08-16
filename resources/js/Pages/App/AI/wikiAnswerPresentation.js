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
