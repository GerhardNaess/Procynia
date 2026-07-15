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
