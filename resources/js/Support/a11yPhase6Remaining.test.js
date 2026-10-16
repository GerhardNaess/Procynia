import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { chipFocusAfterRemoval, pickerResultAnnouncement } from '../Components/App/searchableMultiSelectA11y.js';

const here = dirname(fileURLToPath(import.meta.url));
const read = (...parts) => readFileSync(join(here, '..', ...parts), 'utf8');
const lang = (code) => readFileSync(join(here, '..', '..', '..', 'lang', code, 'procynia.php'), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md): the surfaces outside the modules — the
 * shared components no earlier phase linted, and the public website. Behaviour in a real browser is in
 * tests/e2e/a11y-remaining.spec.js.
 */

/** Source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
}

const A11Y = { results_count: ':count treff', results_none: 'Ingen treff' };

describe('SearchableMultiSelect: the hit count (A11Y-111)', () => {
    test('a search says how many options are left', () => {
        assert.equal(pickerResultAnnouncement('risiko', 3, A11Y), '3 treff');
        assert.equal(pickerResultAnnouncement('risiko', 1, { results_count: ':count results' }), '1 results');
    });

    test('a search with no match says so', () => {
        assert.equal(pickerResultAnnouncement('zzz', 0, A11Y), 'Ingen treff');
    });

    test('an empty search says nothing — the whole list opening is not a result', () => {
        assert.equal(pickerResultAnnouncement('', 12, A11Y), null);
        assert.equal(pickerResultAnnouncement('   ', 12, A11Y), null);
        assert.equal(pickerResultAnnouncement(undefined, 12, A11Y), null);
    });

    test('falls back to Norwegian when the translations are missing', () => {
        assert.equal(pickerResultAnnouncement('a', 2), '2 treff');
        assert.equal(pickerResultAnnouncement('a', 0), 'Ingen treff');
    });

    test('the keys it uses exist in both languages', () => {
        for (const code of ['no', 'en']) {
            assert.match(lang(code), /'results_count' => '[^']*:count/, code);
            assert.match(lang(code), /'results_none' =>/, code);
        }
    });

    test('the component speaks once the typing settles, keyed on the query, through announce()', () => {
        const source = code(read('Components', 'App', 'SearchableMultiSelect.jsx'));

        assert.match(source, /pickerResultAnnouncement\(query/);
        assert.match(source, /announce\(message\)/);
        assert.match(source, /setTimeout\(/);
        assert.match(source, /\}, \[query\]\);/, 'the announcement effect depends on the query only');
        assert.doesNotMatch(source, /aria-live|role="status"/, 'no live region mounted with its text');
    });
});

describe('SearchableMultiSelect: removing a chosen item keeps focus (2.4.3)', () => {
    test('the chip that takes its place, i.e. the next one', () => {
        assert.equal(chipFocusAfterRemoval([1, 2, 3], 2), 3);
        assert.equal(chipFocusAfterRemoval(['a', 'b', 'c'], 'a'), 'b');
    });

    test('the one before when the last was removed', () => {
        assert.equal(chipFocusAfterRemoval([1, 2, 3], 3), 2);
    });

    test('null when nothing is left — the caller focuses the search field', () => {
        assert.equal(chipFocusAfterRemoval([7], 7), null);
        assert.equal(chipFocusAfterRemoval([1, 2], 9), null);
    });

    test('the chip buttons go through removeChip, and focus never falls to <body>', () => {
        const source = code(read('Components', 'App', 'SearchableMultiSelect.jsx'));

        assert.match(source, /onClick=\{\(\) => removeChip\(option\.value\)\}/);
        assert.match(source, /chipFocusAfterRemoval\(/);
        assert.match(source, /chipRefs\.current\.get\(chipToFocus\)/);
        assert.match(source, /inputRef\.current\.focus\(\)/);
    });
});

describe('SearchableMultiSelect: visible state and field wiring', () => {
    const source = code(read('Components', 'App', 'SearchableMultiSelect.jsx'));

    test('the option under the arrow keys has a violet outline, not only a grey fill (2.4.7)', () => {
        assert.match(source, /index === active \? 'bg-slate-100 outline outline-2 -outline-offset-2 outline-violet-600'/);
    });

    test('the unchosen box has a 3:1 border (1.4.11)', () => {
        assert.match(source, /'border-slate-500 bg-white'/);
        assert.doesNotMatch(source, /'border-slate-300 bg-white'/);
    });

    test('the chip focus is a violet-600 outline, not a violet-300 ring', () => {
        assert.match(source, /focus-visible:outline-violet-600/);
        assert.doesNotMatch(source, /focus-visible:ring-violet-300/);
    });

    test('a caller can pass its error and required state to the search field (A11Y-013, A11Y-121)', () => {
        assert.match(source, /aria-invalid=\{invalid \|\| undefined\}/);
        assert.match(source, /aria-required=\{required \|\| undefined\}/);
        assert.match(source, /invalid = false,/);
        assert.match(source, /required = false,/);
    });

    test('the props the GRC pages already pass are all still accepted', () => {
        for (const prop of ['id', 'options', 'values', 'onChange', 'placeholder', 'searchLabel', 'noResultsLabel', 'removeLabel', 'selectedLabel', 'describedBy', 'testId']) {
            assert.match(source, new RegExp(`^\\s+${prop}(?: = [^,]+)?,$`, 'm'), prop);
        }
    });
});

describe('AlertBox: no role unless the caller asks for one (§5)', () => {
    const source = code(read('Components', 'App', 'AlertBox.jsx'));

    test('no default role — every current use is a notice that arrives with the page', () => {
        assert.match(source, /role=\{role\}/);
        assert.doesNotMatch(source, /role = '/);
        assert.doesNotMatch(source, /role="(alert|status)"/);
    });

    test('no current caller turns a page-load notice into an alert', () => {
        for (const file of ['Pages/App/Notices/Index.jsx', 'Pages/App/AI/Index.jsx', 'Pages/App/Users/Create.jsx', 'Pages/App/Users/Edit.jsx', 'Pages/App/Billing/Index.jsx', 'Pages/App/SupplierManagement/Import.jsx']) {
            const page = read(...file.split('/'));
            assert.doesNotMatch(page, /<AlertBox[^>]*role="alert"/, file);
        }
    });
});

describe('Shared text components follow the contrast and size rules (A11Y-016)', () => {
    test('AiQuotaCompact: the remaining line is slate-600', () => {
        const source = code(read('Components', 'App', 'AiQuotaCompact.jsx'));
        assert.match(source, /className="text-slate-600">\{remainingLabel/);
        assert.doesNotMatch(source, /text-slate-[34]00|text-slate-500/);
    });

    test('BidPipelineOverview: no text under 12 px, no slate-500/400 text', () => {
        const source = code(read('Components', 'App', 'BidPipelineOverview.jsx'));
        assert.doesNotMatch(source, /text-\[1[01]px\]/);
        assert.doesNotMatch(source, /text-slate-(300|400|500)/);
    });
});

describe('Public registration form (A11Y-013, A11Y-121, A11Y-019)', () => {
    const source = code(read('Pages', 'Public', 'Register.jsx'));

    test('every text field and select goes through FormField and is required', () => {
        for (const field of ['company_name', 'nationality_id', 'language_id', 'owner_name', 'owner_email', 'password']) {
            assert.match(source, new RegExp(`<FormField\\s+id="${field}"[\\s\\S]*?error=\\{errors\\.${field}\\}\\s+required`), field);
        }
        assert.doesNotMatch(source, /<label[^>]*htmlFor="(company_name|owner_email|password)"/, 'no hand-wired label left');
    });

    test('the consent checkboxes are tied to their errors and marked required', () => {
        for (const field of ['privacy_accepted', 'terms_accepted', 'represents_business']) {
            assert.match(source, new RegExp(`\\{\\.\\.\\.checkboxA11y\\('${field}'\\)\\.controlProps\\}`), field);
            assert.match(source, new RegExp(`id=\\{checkboxA11y\\('${field}'\\)\\.errorId\\}`), field);
        }
        assert.match(source, /fieldA11y\(\{ id: name, hasError: Boolean\(errors\[name\]\), required: true \}\)/);
        assert.match(source, /<fieldset aria-labelledby="register-legal-heading"/);
    });

    test('a rejected submit focuses the first invalid field, with the summary as fallback', () => {
        assert.match(source, /useFocusFirstError\(formRef\)/);
        assert.match(source, /onError: focusFirstError/);
        assert.match(source, /data-form-error-summary/);
    });

    test('the «*» is explained once, with the shared key', () => {
        assert.equal((source.match(/<RequiredFieldsNote/g) ?? []).length, 1);
        assert.match(source, /text=\{a11y\.required_fields\}/);
    });

    test('field tone border, rose-700 errors, slate-600 hints; the button stays focusable while sending', () => {
        assert.match(source, /border-field-border/);
        assert.doesNotMatch(source, /text-rose-600/);
        assert.doesNotMatch(source, /text-xs leading-6 text-slate-500/);
        assert.match(source, /aria-disabled=\{processing \|\| undefined\}/);
        assert.match(source, /if \(processing\) return;/);
        assert.doesNotMatch(source, /disabled=\{processing\}/);
    });

    test('the request payload is unchanged', () => {
        assert.match(source, /post\('\/registrer'/);
        for (const field of ['company_name', 'nationality_id', 'language_id', 'owner_name', 'owner_email', 'password', 'privacy_accepted', 'terms_accepted', 'represents_business']) {
            assert.match(source, new RegExp(`name="${field}"`), field);
        }
    });
});

describe('Public pages: structure and contrast', () => {
    test('FAQ: each question is a disclosure in an h3, aria-controls points at its answer', () => {
        const source = code(read('Pages', 'Public', 'Faq.jsx'));
        assert.match(source, /<h3>\s*<button/);
        assert.match(source, /aria-expanded=\{open\}/);
        assert.match(source, /aria-controls=\{answerId\}/);
        assert.match(source, /id=\{answerId\} hidden=\{! open\}/);
        assert.doesNotMatch(source, /text-slate-(300|400)|leading-7 text-slate-500/);
    });

    test('Security: no jump from h1 to h3 (A11Y-119)', () => {
        const source = code(read('Pages', 'Public', 'Security.jsx'));
        assert.doesNotMatch(source, /<h3\b/);
        assert.match(source, /<h2 className="mb-2 text-base font-semibold text-slate-900">/);
    });

    test('Privacy: tables have names, column and row headers; table of contents is a named nav', () => {
        const source = code(read('Pages', 'Public', 'Privacy.jsx'));
        assert.equal((source.match(/<table aria-labelledby="privacy-/g) ?? []).length, 2);
        assert.equal((source.match(/<th scope="col"/g) ?? []).length, 5);
        assert.equal((source.match(/<th scope="row"/g) ?? []).length, 2);
        assert.match(source, /<nav aria-labelledby="privacy-toc-heading"/);
        assert.match(source, /id="privacy-categories-intro"/);
        assert.match(source, /id="privacy-basis-intro"/);
    });

    test('Privacy at 320 px: the tables scroll in a named box, the long title may break (1.4.10)', () => {
        const source = code(read('Pages', 'Public', 'Privacy.jsx'));
        assert.equal((source.match(/<ScrollRegion label=\{(CATEGORIES|BASIS)_INTRO/g) ?? []).length, 2);
        assert.doesNotMatch(source, /<div className="overflow-x-auto">/);
        assert.match(source, /<div className="min-w-0 space-y-12 break-words">/);
        assert.match(source, /<h1 className="break-words hyphens-auto /);
    });

    test('Terms: no text under 12 px, the table of contents is a named nav', () => {
        const source = code(read('Pages', 'Public', 'Terms.jsx'));
        assert.doesNotMatch(source, /text-\[0\.7rem\]/);
        assert.match(source, /<nav aria-labelledby="terms-toc-heading"/);
    });

    test('no information text in slate-500 on the page background', () => {
        for (const file of ['Home', 'Faq', 'Privacy', 'Security']) {
            const source = code(read('Pages', 'Public', `${file}.jsx`));
            assert.doesNotMatch(source, /text-lg leading-8 text-slate-500/, file);
        }
        assert.doesNotMatch(code(read('Pages', 'Public', 'Home.jsx')), /leading-7 text-slate-500/);
    });
});
