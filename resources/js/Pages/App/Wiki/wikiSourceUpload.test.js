import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { MAX_SOURCE_FILE_BYTES, validateSourceFile } from './wikiSourceUpload.js';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Choosing a source document IS the intent to upload it, so the upload now starts on selection and
 * the separate "Last opp kilde" click is gone.
 *
 * The two things that make that safe rather than merely shorter are asynchronous state and the
 * native file input's change semantics — both easy to get subtly wrong, and both pinned down below.
 */

const file = (name, size = 1024) => ({ name, size });

describe('the client-side pre-check', () => {
    test('a PDF and a DOCX are accepted', () => {
        assert.equal(validateSourceFile(file('security-document.pdf')), null);
        assert.equal(validateSourceFile(file('rutine.docx')), null);
    });

    test('the extension is read case-insensitively', () => {
        // A file picked on Windows is routinely "REPORT.PDF".
        assert.equal(validateSourceFile(file('REPORT.PDF')), null);
        assert.equal(validateSourceFile(file('Rutine.DocX')), null);
    });

    test('another format is refused before anything is sent', () => {
        assert.equal(validateSourceFile(file('presentasjon.pptx')), 'Filen må være PDF eller DOCX.');
        assert.equal(validateSourceFile(file('bilde.png')), 'Filen må være PDF eller DOCX.');
    });

    test('a file with no extension at all is refused', () => {
        assert.equal(validateSourceFile(file('dokument')), 'Filen må være PDF eller DOCX.');
    });

    test('the size limit matches the server, to the byte', () => {
        // WikiSourceController::store() uses 'max:20480', and Laravel counts a KB as 1024 bytes.
        assert.equal(MAX_SOURCE_FILE_BYTES, 20480 * 1024);
        assert.equal(validateSourceFile(file('stor.pdf', MAX_SOURCE_FILE_BYTES)), null);
        assert.equal(
            validateSourceFile(file('stor.pdf', MAX_SOURCE_FILE_BYTES + 1)),
            'Filen er større enn 20 MB.',
        );
    });

    test('messages come from the translations when they are present', () => {
        assert.equal(
            validateSourceFile(file('x.png'), { sources_error_file_type: 'Wrong format.' }),
            'Wrong format.',
        );
    });

    test('no file is not an error — a cancelled picker has nothing to validate', () => {
        for (const nothing of [null, undefined]) {
            assert.equal(validateSourceFile(nothing), null);
        }
    });
});

/**
 * Source-level guards, in the same style as the other Wiki tests — the project has no JSX test
 * renderer, and what needs protecting here is the handler's sequence rather than its markup.
 */
describe('the upload flow in Index.jsx', () => {
    const source = readFileSync(join(here, 'Index.jsx'), 'utf8');
    const handler = source.slice(
        source.indexOf('const handleFileSelected'),
        source.indexOf('const sourceStatusLabel'),
    );
    const startUpload = source.slice(
        source.indexOf('const startUpload'),
        source.indexOf('const handleFileSelected'),
    );

    test('choosing a file is what starts the upload', () => {
        assert.match(source, /onChange=\{handleFileSelected\}/);
        assert.match(handler, /startUpload\(file\)/);
    });

    test('there is no submit step left', () => {
        assert.equal(/sources_upload_button/.test(source), false, '"Last opp kilde" must be gone');
        assert.equal(/submitUpload/.test(source), false, 'the submit handler must be gone');
        // Not a disabled button either, and no form to submit from the owner select.
        assert.equal(/<form onSubmit=\{submit/.test(source), false);
    });

    /**
     * The bug this ordering exists to prevent: useForm's setData is asynchronous, so a post() in
     * the same tick sends the previous value — null on the very first upload. The File travels as
     * an argument and is applied in transform(), which runs at submit time.
     */
    test('the chosen file reaches the request without going through form state', () => {
        assert.match(startUpload, /const startUpload = \(file\) =>/);
        assert.match(startUpload, /transform\(\(data\) => \(\{ \.\.\.data, file, /);
        assert.ok(
            startUpload.indexOf('transform(') < startUpload.indexOf('.post('),
            'transform must be set before the request is issued',
        );
    });

    test('the owner selected in the dropdown rides along unchanged', () => {
        // owner_user_id stays part of the form data; transform spreads it rather than replacing it.
        assert.match(source, /onChange=\{\(event\) => uploadForm\.setData\('owner_user_id', event\.target\.value\)\}/);
        assert.match(startUpload, /\.\.\.data, file/);
        assert.match(source, /owner_user_id: String\(currentUser\.id \?\? ''\)/);
    });

    test('a cancelled picker does nothing at all', () => {
        assert.match(handler, /if \(file === null\) \{\s*\n\s*return;/);
        assert.ok(
            handler.indexOf('if (file === null)') < handler.indexOf('startUpload('),
            'the empty-selection guard must come before any upload',
        );
    });

    test('an invalid file is refused before a request is made', () => {
        assert.match(handler, /const error = validateSourceFile\(file, tw\);/);
        assert.match(handler, /uploadForm\.setError\('file', error\)/);
        assert.ok(
            handler.indexOf('setError(\'file\', error)') < handler.indexOf('startUpload('),
            'validation must gate the upload, not follow it',
        );
    });

    test('a second selection cannot start a second upload mid-flight', () => {
        assert.match(handler, /if \(uploadForm\.processing\) \{\s*\n\s*return;/);
        // The picker itself is also closed while the request is running.
        assert.match(source, /disabled=\{uploadForm\.processing\}/);
    });

    /**
     * The retry case. A native file input does not fire `change` when the same file is picked
     * again, so after a failed upload the user could not retry with the file that failed — the one
     * file they are most likely to reach for. Clearing the value up front, for every selection,
     * removes that trap without needing to know whether the upload will succeed.
     */
    test('the same file can be chosen again after a failure', () => {
        assert.match(handler, /input\.value = '';/);
        assert.ok(
            handler.indexOf("input.value = ''") < handler.indexOf('uploadForm.processing'),
            'the input must be cleared on every selection, not only on success',
        );
    });

    test('the existing error display is what the user sees', () => {
        assert.match(source, /\{uploadForm\.errors\.file \? \(/);
    });

    test('success still resets the form and keeps the default owner', () => {
        assert.match(startUpload, /onSuccess: \(\) => \{/);
        assert.match(startUpload, /uploadForm\.reset\(\)/);
        assert.match(startUpload, /setData\('owner_user_id', String\(currentUser\.id \?\? ''\)\)/);
    });

    test('progress reuses the state and wording that already existed', () => {
        assert.match(source, /tw\.sources_uploading \?\? 'Laster opp\.\.\.'/);
    });
});

/**
 * Starting the upload on selection removed a click, and with it the moment where the user confirmed
 * what they were doing. The document now appears in the list on its own, which reads as "it is in
 * the wiki" — it is not, until "Lag Wiki" is pressed.
 *
 * A notice in the page could be scrolled past and flash.success is a toast that clears itself, so
 * neither can establish that the user saw it. The dialog can, because only its own button closes
 * it. These tests pin down both halves: that it says the right thing, and that it cannot be got rid
 * of any other way.
 */
describe('the next-step dialog', () => {
    const source = readFileSync(join(here, 'Index.jsx'), 'utf8');
    const startUpload = source.slice(
        source.indexOf('const startUpload'),
        source.indexOf('const handleFileSelected'),
    );
    const dialog = source.slice(
        source.indexOf('<ActionDialog'),
        source.indexOf('</ActionDialog>'),
    );

    test('it opens once the upload has succeeded', () => {
        assert.match(startUpload, /onSuccess: \(\) => \{[\s\S]*setIsUploadedDialogOpen\(true\)/);
        assert.match(dialog, /isOpen=\{isUploadedDialogOpen\}/);
    });

    test('nothing else ever opens it', () => {
        // Choosing a file, an invalid file, a failed request: none of them reach a `true`.
        const opened = [...source.matchAll(/setIsUploadedDialogOpen\((\w+)\)/g)].map((m) => m[1]);

        assert.deepEqual([...new Set(opened)].sort(), ['false', 'true']);
        assert.equal((source.match(/setIsUploadedDialogOpen\(true\)/g) ?? []).length, 1);
        assert.ok(
            startUpload.includes('setIsUploadedDialogOpen(true)'),
            'the single opener must be inside onSuccess',
        );
    });

    test('a failed upload leaves it closed', () => {
        assert.equal(/onError[\s\S]{0,200}setIsUploadedDialogOpen\(true\)/.test(source), false);
    });

    test('it says the document is uploaded, not that it is in the wiki', () => {
        assert.match(dialog, /tw\.sources_uploaded_title \?\? 'Kildedokumentet er lastet opp'/);

        for (const overclaim of ['importert', 'innarbeidet', 'lagt inn i wikien', 'er i wikien']) {
            assert.equal(source.includes(overclaim), false, `must not claim: ${overclaim}`);
        }
    });

    test('it tells the user which button to press', () => {
        assert.match(dialog, /tw\.sources_uploaded_next_step \?\? 'For å ta dokumentet inn i wikien må du nå trykke «:action»\.'/);
    });

    /**
     * The action is interpolated from the button's own label rather than written out again, so the
     * instruction cannot end up naming a button that reads differently.
     */
    test('the named action comes from the button itself', () => {
        assert.match(dialog, /\.split\(':action'\)/);
        assert.match(dialog, /tw\.source_ingest_button \?\? 'Lag Wiki'/);
    });

    test('there is exactly one action, and it is the confirmation', () => {
        const buttons = [...dialog.matchAll(/<button/g)];

        assert.equal(buttons.length, 1, 'a second button would give the user a way to skip reading');
        assert.match(dialog, /tw\.sources_uploaded_confirm \?\? 'OK, jeg forstår'/);
    });

    test('confirming only closes the dialog', () => {
        assert.match(dialog, /onClick=\{\(\) => setIsUploadedDialogOpen\(false\)\}/);
    });

    /**
     * The dialog explains the next step; it must not take it. Running the ingest here would defeat
     * the point of telling the user that pressing "Lag Wiki" is theirs to do.
     */
    test('confirming does NOT start the wiki run', () => {
        // The button's LABEL is read here, which is the point; the ingest flow itself is not
        // touched — no request, and none of the state that tracks a run being started.
        assert.equal(/router\.(post|patch|put)/.test(dialog), false, 'the dialog must issue no request');
        assert.equal(/setIngestingIds/.test(dialog), false, 'the dialog must not start a run');
        assert.equal(/\/ingest/.test(dialog), false, 'the dialog must not reach the ingest endpoint');

        // The only handler on the only button is the one that closes it.
        const handlers = [...dialog.matchAll(/onClick=\{([^}]*)\}/g)].map((m) => m[1].trim());

        assert.deepEqual(handlers, ['() => setIsUploadedDialogOpen(false)']);
    });

    /**
     * closeDisabled is ActionDialog's own switch for both routes out: it guards the Escape handler
     * and the backdrop mousedown. Passing it bare means "always on" — the dialog has no busy state
     * to tie it to, because it must be unclosable the whole time it is open.
     */
    test('neither the backdrop nor Escape can dismiss it', () => {
        assert.match(dialog, /\n\s+closeDisabled\n/);

        const component = readFileSync(join(here, '..', '..', '..', 'Components', 'App', 'ActionDialog.jsx'), 'utf8');

        assert.match(component, /event\.key === 'Escape' && !closeDisabledRef\.current/);
        assert.match(component, /event\.target === event\.currentTarget && !closeDisabled/);
    });

    test('there is no X in the header to close it with', () => {
        assert.equal(/aria-label=\{tw\.close/.test(dialog), false);
        assert.equal(/Lukk/.test(dialog), false);
    });

    test('it is a real dialog, with focus on the one thing to do', () => {
        assert.match(dialog, /titleId="wiki-source-uploaded-title"/);
        assert.match(dialog, /initialFocusRef=\{uploadedDialogConfirmRef\}/);
        assert.match(dialog, /ref=\{uploadedDialogConfirmRef\}/);

        const component = readFileSync(join(here, '..', '..', '..', 'Components', 'App', 'ActionDialog.jsx'), 'utf8');

        assert.match(component, /role="dialog"/);
        assert.match(component, /aria-modal="true"/);
    });

    test('the inline notice it replaces is gone', () => {
        assert.equal(/AlertBox/.test(source), false, 'the page must not show both');
        assert.equal(/uploadedFilename/.test(source), false);
    });

    test('every line the user reads is at the readable size', () => {
        assert.match(dialog, /className="text-xl font-semibold/);

        assert.equal(/text-(xs|sm)\b/.test(dialog), false, 'the dialog must not use small text');
        for (const [, px] of dialog.matchAll(/text-\[(\d+)px\]/g)) {
            assert.ok(Number(px) >= 16, `text-[${px}px] is below the 16px floor`);
        }
    });
});
