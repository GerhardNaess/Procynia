import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildWikiAnswerCopyText,
    dedupeWikiAnswerSourcesByPageId,
    normalizeWikiAnswerText,
} from '../../resources/js/Pages/App/AI/wikiAnswerPresentation.js';

test('wiki answer copy text uses only the answer text', () => {
    const answerText = 'Første avsnitt.\n\nAndre avsnitt.';

    assert.strictEqual(buildWikiAnswerCopyText(answerText), answerText);
    assert.strictEqual(normalizeWikiAnswerText(answerText), answerText);
});

test('wiki answer sources are deduplicated by page id while preserving order', () => {
    const sources = dedupeWikiAnswerSourcesByPageId([
        { enterprise_wiki_page_id: 12, page_title: 'Først' },
        { enterprise_wiki_page_id: 12, page_title: 'Duplikat' },
        { enterprise_wiki_page_id: 27, page_title: 'Sist' },
    ]);

    assert.deepStrictEqual(sources.map((source) => source.page_title), ['Først', 'Sist']);
});

test('wiki answer text normalization preserves content and line breaks', () => {
    assert.strictEqual(
        normalizeWikiAnswerText('Linje 1\r\nLinje 2\rLinje 3'),
        'Linje 1\nLinje 2\nLinje 3',
    );
});
