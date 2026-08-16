import { test, describe, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
    buildWikiAnswerCopyHtml,
    copyWikiAnswerToClipboard,
    writePlainClipboardPayload,
    writeRichClipboardPayload,
} from './wikiAnswerPresentation.js';

/**
 * "Kopier svar" looked inert in the browser. The handler ran, the four figure fetches all returned
 * 200, and navigator.clipboard.write() was reached — it threw
 * "NotAllowedError: Write permission denied", which the handler swallowed into a 'failed' status
 * nothing rendered, and it never fell back to writeText() (which succeeded on the same page).
 *
 * These pin both halves: a refused rich write must degrade to plain text, and no branch may end
 * without a visible state.
 */
const originalNavigator = globalThis.navigator;
const originalWindow = globalThis.window;

function installClipboard({ write, writeText, clipboardItem = true } = {}) {
    const calls = { write: [], writeText: [] };

    globalThis.navigator = {
        clipboard: {
            ...(write ? { write: async (items) => { calls.write.push(items); return write(items); } } : {}),
            ...(writeText ? { writeText: async (text) => { calls.writeText.push(text); return writeText(text); } } : {}),
        },
    };

    globalThis.window = {
        ...(clipboardItem ? { ClipboardItem: class { constructor(payload) { this.payload = payload; } } } : {}),
    };

    globalThis.Blob = class { constructor(parts, options) { this.parts = parts; this.type = options?.type; } };

    return calls;
}

beforeEach(() => { globalThis.navigator = undefined; globalThis.window = undefined; });
afterEach(() => { globalThis.navigator = originalNavigator; globalThis.window = originalWindow; });

describe('writeRichClipboardPayload', () => {
    test('writes both flavours, with the html carried as a promise', async () => {
        const calls = installClipboard({ write: async () => undefined });

        assert.equal(await writeRichClipboardPayload(Promise.resolve('<p>Svar</p>'), 'Svar'), true);
        assert.equal(calls.write.length, 1);

        const payload = calls.write[0][0].payload;
        assert.equal(payload['text/plain'].parts[0], 'Svar');
        // text/html must be a promise, never a resolved Blob: that is what keeps the write inside
        // the user gesture while the figures are still being fetched.
        assert.equal(typeof payload['text/html'].then, 'function');
        assert.equal((await payload['text/html']).parts[0], '<p>Svar</p>');
    });

    test('the write is issued before the html promise settles', async () => {
        let resolveHtml = null;
        const htmlPromise = new Promise((resolve) => { resolveHtml = resolve; });
        const calls = installClipboard({ write: async () => undefined });

        const writing = writeRichClipboardPayload(htmlPromise, 'Svar');
        await Promise.resolve();

        assert.equal(calls.write.length, 1, 'clipboard.write() must be called without awaiting the html');

        resolveHtml('<p>Svar</p>');
        assert.equal(await writing, true);
    });

    test('a refused write reports false instead of throwing', async () => {
        installClipboard({
            write: async () => { throw new Error('NotAllowedError: Write permission denied.'); },
        });

        assert.equal(await writeRichClipboardPayload(Promise.resolve('<p>Svar</p>'), 'Svar'), false);
    });

    test('a browser without ClipboardItem or clipboard.write reports false', async () => {
        installClipboard({ write: async () => undefined, clipboardItem: false });
        assert.equal(await writeRichClipboardPayload(Promise.resolve('<p>x</p>'), 'x'), false);

        installClipboard({ writeText: async () => undefined });
        assert.equal(await writeRichClipboardPayload(Promise.resolve('<p>x</p>'), 'x'), false);
    });
});

describe('writePlainClipboardPayload', () => {
    test('writes the text and reports success', async () => {
        const calls = installClipboard({ writeText: async () => undefined });

        assert.equal(await writePlainClipboardPayload('Svar'), true);
        assert.deepEqual(calls.writeText, ['Svar']);
    });

    test('a refused or missing writeText reports false instead of throwing', async () => {
        installClipboard({ writeText: async () => { throw new Error('NotAllowedError'); } });
        assert.equal(await writePlainClipboardPayload('Svar'), false);

        installClipboard({});
        assert.equal(await writePlainClipboardPayload('Svar'), false);
    });
});

describe('copyWikiAnswerToClipboard', () => {
    /** Records what each writer was asked to do; no global browser object is touched. */
    function writers({ rich, plain }) {
        const calls = { rich: [], plain: [] };

        return {
            calls,
            writeRich: async (htmlPromise, text) => { calls.rich.push({ htmlPromise, text }); return rich; },
            writePlain: async (text) => { calls.plain.push(text); return plain; },
        };
    }

    test('a rich write refused by the browser still copies the answer as plain text', async () => {
        const { calls, writeRich, writePlain } = writers({ rich: false, plain: true });

        const result = await copyWikiAnswerToClipboard({
            htmlPromise: Promise.resolve('<p>Svar</p>'), plainText: 'Svar', writeRich, writePlain,
        });

        assert.deepEqual(result, { copied: true, reason: 'plain' });
        assert.equal(calls.rich.length, 1, 'the rich flavour is still attempted first');
        assert.deepEqual(calls.plain, ['Svar']);
    });

    test('an accepted rich write does not also write plain text', async () => {
        const { calls, writeRich, writePlain } = writers({ rich: true, plain: true });

        const result = await copyWikiAnswerToClipboard({
            htmlPromise: Promise.resolve('<p>Svar</p>'), plainText: 'Svar', writeRich, writePlain,
        });

        assert.deepEqual(result, { copied: true, reason: 'rich' });
        assert.equal(calls.plain.length, 0);
    });

    test('nothing rendered (the Markdown editor is open) copies plain text without attempting rich', async () => {
        const { calls, writeRich, writePlain } = writers({ rich: true, plain: true });

        const result = await copyWikiAnswerToClipboard({
            htmlPromise: null, plainText: 'Svar', writeRich, writePlain,
        });

        assert.deepEqual(result, { copied: true, reason: 'plain' });
        assert.equal(calls.rich.length, 0);
        assert.deepEqual(calls.plain, ['Svar']);
    });

    test('when both flavours fail the caller learns it failed', async () => {
        const { writeRich, writePlain } = writers({ rich: false, plain: false });

        const result = await copyWikiAnswerToClipboard({
            htmlPromise: Promise.resolve('<p>Svar</p>'), plainText: 'Svar', writeRich, writePlain,
        });

        assert.equal(result.copied, false);
    });
});

describe('figure inlining is non-fatal', () => {
    function fakeElement(images) {
        const nodes = images.map((src) => {
            const attributes = { src };

            return {
                getAttribute: (name) => attributes[name] ?? null,
                setAttribute: (name, value) => { attributes[name] = value; },
                remove() { this.removed = true; },
                removed: false,
                attributes,
            };
        });

        return {
            nodes,
            cloneNode: () => ({
                querySelectorAll: () => nodes,
                get innerHTML() {
                    return nodes
                        .filter((node) => !node.removed)
                        .map((node) => `<img src="${node.attributes.src}">`)
                        .join('');
                },
            }),
        };
    }

    test('one unreachable figure is dropped while the others are still inlined', async () => {
        const element = fakeElement(['/ok-1', '/broken', '/ok-2']);
        const html = await buildWikiAnswerCopyHtml(
            element,
            async (src) => (src === '/broken' ? null : `data:image/png;base64,AAAA-${src}`),
        );

        assert.ok(html.includes('data:image/png;base64,AAAA-/ok-1'));
        assert.ok(html.includes('data:image/png;base64,AAAA-/ok-2'));
        assert.ok(!html.includes('/broken'));
        assert.ok(element.nodes[1].removed);
    });
});

describe('Copy wiring in the AI answer panel', () => {
    const showSource = readFileSync(fileURLToPath(new URL('./Show.jsx', import.meta.url)), 'utf8');
    const handler = showSource.slice(
        showSource.indexOf('const copyActiveWikiAnswerContent'),
        showSource.indexOf('const saveActiveWikiAnswerText'),
    );

    test('the handler copies through the shared rich-then-plain decision', () => {
        assert.ok(handler.includes('copyWikiAnswerToClipboard({ htmlPromise, plainText: payloadText })'));
        assert.match(showSource, /import \{[\s\S]*?copyWikiAnswerToClipboard[\s\S]*?\} from '\.\/wikiAnswerPresentation'/);
    });

    test('the figure work is started but never awaited before the clipboard is touched', () => {
        // The regression this guards: awaiting ~half a megabyte of figure fetches spends the click's
        // user activation, after which every clipboard write is refused.
        assert.match(handler, /const htmlPromise = renderedAnswer === null\s*\n\s*\? null\s*\n\s*: buildWikiAnswerCopyHtml\(/);
        assert.ok(!handler.includes('await buildWikiAnswerCopyHtml'));
    });

    test('every exit sets a visible status — none returns silently', () => {
        assert.ok(handler.includes("setWikiAnswerCopyStatus('empty')"));
        // The failure state carries WHY it failed, so the message can be actionable.
        assert.ok(handler.includes("setWikiAnswerCopyStatus(copied ? 'copied' : `failed:${reason}`)"));
        assert.ok(handler.includes("setWikiAnswerCopyStatus('failed')"));
    });

    test('the button is disabled while copying so parallel figure fetches cannot pile up', () => {
        assert.ok(handler.includes('setWikiAnswerCopyingRequirementId(activeRequirement.id)'));
        assert.ok(handler.includes('setWikiAnswerCopyingRequirementId(null)'));
        assert.match(showSource, /data-testid="wiki-answer-copy-button"[\s\S]{0,600}wikiAnswerCopyingRequirementId === activeRequirement\.id/);
    });

    test('a failed or empty copy is actually rendered to the user', () => {
        assert.match(showSource, /data-testid="wiki-answer-copy-error"/);
        assert.match(showSource, /startsWith\('failed'\)/);
        assert.match(showSource, /tai\.copy_failed_no_clipboard_access/);
        assert.match(showSource, /tai\.copy_failed_denied/);
    });
});
