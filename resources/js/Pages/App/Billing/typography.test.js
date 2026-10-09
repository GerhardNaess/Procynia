import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const billing = readFileSync(join(here, 'Index.jsx'), 'utf8');
const card = readFileSync(join(here, '..', '..', '..', 'Components', 'App', 'AiCapacityCard.jsx'), 'utf8');

/** The value of a page-level class constant, e.g. SECTION_HEADING. */
function constant(name) {
    const match = billing.match(new RegExp(`const ${name} = '([^']+)'`));
    assert.notEqual(match, null, `${name} is gone`);

    return match[1];
}

/** The rendered px size of the first text-* size class in a class list. */
function size(classes) {
    const scale = { 'text-base': 16, 'text-lg': 18, 'text-xl': 20, 'text-2xl': 24, 'text-4xl': 36 };
    const arbitrary = classes.match(/text-\[(\d*\.?\d+)rem\]/);

    if (arbitrary) {
        return Number(arbitrary[1]) * 16;
    }

    const named = classes.split(/\s+/).find((cls) => cls in scale);
    assert.ok(named, `no text size in "${classes}"`);

    return scale[named];
}

/** The slice of a source from a marker to the first `until` after it. */
function slice(source, from, until) {
    const start = source.indexOf(from);
    assert.notEqual(start, -1, `${from} is gone`);

    return source.slice(start, source.indexOf(until, start + from.length));
}

/**
 * Abonnement had a flat hierarchy: Basis, Opsjoner and AI-kapasitet were set at body size, so the
 * text explaining a section looked as heavy as its heading. Each tier now outranks the next.
 */
describe('the subscription page reads top-down', () => {
    test('section headings outrank the text that explains them', () => {
        assert.ok(size(constant('SECTION_HEADING')) >= 20 && size(constant('SECTION_HEADING')) <= 22);
        assert.match(constant('SECTION_HEADING'), /font-semibold/);
        assert.equal(size(constant('SECTION_HELP')), 16);
        assert.match(constant('SECTION_HELP'), /text-slate-600/);
    });

    test('every section heading uses the shared style, the AI card included', () => {
        for (const testId of ['subscription-card', 'module-packages', 'other-services', 'invoices']) {
            assert.match(slice(billing, `data-testid="${testId}"`, '</h2>'), /<h2 className=\{SECTION_HEADING\}>/, testId);
        }
        assert.ok(card.includes(`<h2 className="${constant('SECTION_HEADING')}">`), 'the AI card heading drifted from the page');
    });

    test('an option name outranks its description', () => {
        const row = slice(billing, 'optionPackages.map', '</li>');
        assert.match(row, /<h3 className="text-lg font-semibold text-slate-900">/);
        assert.match(row, /<p className="mt-0\.5 text-base leading-6 text-slate-600">/);
    });

    test('a value is easier to find than its label', () => {
        assert.equal(size(constant('FACT_LABEL')), 16);
        assert.ok(size(constant('FACT_VALUE')) > size(constant('FACT_LABEL')));
        assert.match(constant('FACT_VALUE'), /font-semibold/);
        assert.doesNotMatch(constant('FACT_LABEL'), /font-(medium|semibold|bold)/);
    });

    test('Basis groups its facts as label/value pairs, under the heading and its status', () => {
        const basis = slice(billing, 'data-testid="subscription-card"', '</section>');
        assert.ok(basis.indexOf('<h2') < basis.indexOf('<StatusBadge'));
        assert.ok(basis.indexOf('basisModules &&') < basis.indexOf('data-testid="subscription-facts"'));
        assert.equal((basis.match(/<dt className=\{FACT_LABEL\}>/g) ?? []).length, 3);
        assert.equal((basis.match(/<dd className=\{FACT_VALUE\}>/g) ?? []).length, 3);
    });

    test('an option row puts name and description left, status and action together on the right', () => {
        const row = slice(billing, 'optionPackages.map', '</li>');
        assert.match(row, /flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between/);
        const side = slice(row, 'data-testid={`package-side-', '</div>');
        assert.ok(side.includes('renderPackageStatus(entry)') && side.includes('renderPackageAction(entry)'));
        assert.ok(row.indexOf('<h3') < row.indexOf('package-side-'));
    });

    test('no visible text below 16 px anywhere on the page', () => {
        for (const [name, source] of [['billing', billing], ['card', card]]) {
            assert.ok(!/text-(xs|sm)\b|text-\[(1[0-5]|[0-9])px\]/.test(source), `${name} has text below 16 px`);
        }
    });

    test('every button shares the house geometry and shows focus', () => {
        assert.doesNotMatch(billing + card, /rounded-lg px-4 py-2 text-base font-medium/);
        for (const button of billing.match(/<button[\s\S]*?className=[^\n]+/g)) {
            assert.match(button, /PRIMARY_ACTION|SECONDARY_ACTION|WARNING_ACTION/, button);
            assert.match(button, /PRIMARY_ACTION|FOCUS_RING/, `${button} has no focus ring`);
        }
    });
});

describe('the AI card: heading → Nivå + Endre nivå → usage → bar → facts', () => {
    const configured = card.slice(card.indexOf('const reserved ='));

    test('Nivå and «Endre nivå» sit in one group, left-aligned and wrapping in place', () => {
        const group = slice(configured, 'data-testid="ai-capacity-level-group"', '</div>');
        assert.ok(group.includes('data-testid="ai-capacity-tier"'));
        assert.ok(group.includes('data-testid="ai-capacity-change-level"'));
        // justify-between is what used to push the button to the far edge of the card.
        const classes = configured.match(/<div className="([^"]+)" data-testid="ai-capacity-level-group">/)[1];
        assert.match(classes, /flex flex-wrap items-center/);
        assert.doesNotMatch(classes, /justify-(between|end)/);
    });

    test('the status badge stays with the heading, never with the button', () => {
        const headingRow = slice(configured, '{heading}', '</div>');
        assert.ok(headingRow.includes('<StatusBadge'));
        assert.doesNotMatch(slice(configured, 'data-testid="ai-capacity-level-group"', '</div>'), /StatusBadge/);
    });

    test('the parts come in reading order', () => {
        const order = ['{heading}', 'ai-capacity-level-group', 'ai-capacity-headline', 'role="progressbar"', 'ai-capacity-provisional', 'ai-capacity-facts']
            .map((marker) => configured.indexOf(marker));
        assert.ok(order.every((at) => at !== -1), String(order));
        assert.deepEqual([...order].sort((a, b) => a - b), order);
    });

    test('the usage headline stands out, but below the section heading', () => {
        const headline = configured.match(/<p className="([^"]+)" data-testid="ai-capacity-headline">/)[1];
        assert.ok(size(headline) >= 18 && size(headline) < size(constant('SECTION_HEADING')));
        assert.match(headline, /font-semibold/);
    });

    test('the bar has a label, a value and a readable percentage', () => {
        const bar = slice(configured, 'role="progressbar"', '>');
        for (const attribute of ['aria-valuenow', 'aria-valuemin', 'aria-valuemax', 'aria-valuetext', 'aria-label']) {
            assert.ok(bar.includes(attribute), attribute);
        }
    });

    test('the facts use the page\'s label/value styles', () => {
        assert.match(card, /const factLabel = 'text-base text-slate-600'/);
        assert.match(card, new RegExp(`const factValue = '${constant('FACT_VALUE').replace(/\./g, '\\.')}'`));
        assert.equal((slice(configured, 'ai-capacity-facts', '</dl>').match(/<dd className=\{factValue\}/g) ?? []).length, 4);
    });
});
