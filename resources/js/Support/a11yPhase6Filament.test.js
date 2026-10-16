import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');

/**
 * Fase 6 of the WCAG plan (docs/wcag-accessibility-plan.md §8): the Filament admin panel. Its views are
 * Blade, not React, so the rules are pinned on the Blade and PHP sources; behaviour in a real browser
 * (light and dark, axe, keyboard, 320 px) is in tests/e2e/a11y-admin.spec.js.
 */

/** Blade source without comments, so a rule is not satisfied (or broken) by prose about it. */
function code(source) {
    return source.replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<!--[\s\S]*?-->/g, '');
}

function bladeFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? bladeFiles(path) : (name.endsWith('.blade.php') ? [path] : []);
    });
}

const VIEWS = bladeFiles(join(root, 'resources', 'views', 'filament')).map((path) => ({
    name: relative(root, path),
    source: code(readFileSync(path, 'utf8')),
}));

const view = (name) => code(read('resources', 'views', 'filament', name));

describe('A11Y-055: a primary palette with readable link text', () => {
    test('primary-600 is amber-700, every other shade is Filament\'s amber', () => {
        const provider = read('app', 'Providers', 'Filament', 'AdminPanelProvider.php');
        assert.match(provider, /'primary' => array_replace\(Color::Amber, \[600 => Color::Amber\[700\]\]\)/);
    });

    test('no custom view hovers a link to the 2:1 primary-500', () => {
        for (const { name, source } of VIEWS) {
            assert.doesNotMatch(source, /hover:text-primary-500/, name);
        }
    });
});

describe('A11Y-056: the custom views follow dark mode', () => {
    // A light surface or text colour without a dark: counterpart in the same class string.
    const PAIRS = [
        ['bg-white', 'dark:bg-'],
        ['text-gray-900', 'dark:text-'],
        ['text-gray-600', 'dark:text-'],
        ['border-gray-200', 'dark:border-'],
    ];

    test('every class string with a light surface or text colour also sets its dark value', () => {
        for (const { name, source } of VIEWS) {
            for (const match of source.matchAll(/class="([^"]*)"/g)) {
                const classes = match[1];

                for (const [light, dark] of PAIRS) {
                    if (new RegExp(`(^|\\s)${light}(\\s|$)`).test(classes)) {
                        assert.ok(classes.includes(dark), `${name}: «${light}» without «${dark}» in "${classes.slice(0, 120)}"`);
                    }
                }
            }
        }
    });

    test('the hand-written AI-erfaring styles have dark rules', () => {
        const source = view('pages/ai-erfaring.blade.php');
        assert.match(source, /\.dark \.exp-card \{/);
        assert.match(source, /\.dark \.exp-table th \{/);
    });

    test('the scheduler ring script toggles classes one token at a time', () => {
        // classList.add('a dark:b') throws; each class is its own argument.
        assert.doesNotMatch(view('pages/system-status.blade.php'), /classList\.(add|remove)\('[^']* [^']*'\)/);
    });
});

describe('A11Y-053: runbook attachment descriptions open from a button', () => {
    const source = view('infolists/attachment-table.blade.php');

    test('a real button with aria-expanded and aria-controls, and the row it controls', () => {
        assert.match(source, /<button\s+type="button"[\s\S]*?aria-controls="runbook-attachment-description-\{\{ \$attachment->id \}\}"[\s\S]*?:aria-expanded=/);
        assert.match(source, /<tr id="runbook-attachment-description-\{\{ \$attachment->id \}\}"/);
    });

    test('table semantics: caption, column scope, row header, named action column', () => {
        assert.match(source, /<caption class="sr-only">/);
        assert.match(source, /<th scope="row"/);
        assert.doesNotMatch(source, /<th[^>]*>\s*<\/th>/);
    });
});

describe('A11Y-054: the date filters are labelled', () => {
    for (const [file, prefix] of [['pages/ai-forbruk.blade.php', 'ai-forbruk'], ['pages/ai-profitability.blade.php', 'ai-profitability']]) {
        test(file, () => {
            const source = view(file);

            for (const field of ['date-from', 'date-to']) {
                assert.match(source, new RegExp(`<label[^>]*for="${prefix}-${field}"`));
                assert.match(source, new RegExp(`<input type="date" id="${prefix}-${field}"`));
            }
        });
    }
});

describe('A11Y-125: status, headings, progress and live updates', () => {
    test('notifications say their severity in words', () => {
        const source = view('pages/admin-notifications.blade.php');
        assert.match(source, /data-notification-severity="\{\{ \$severity \}\}">\{\{ \$severityLabels\[\$severity\] \}\}/);
        assert.match(source, /aria-describedby="\{\{ \$titleId \}\}"/);
        assert.match(source, /focusAfterRemoval/);
    });

    test('backup and system-status warnings say critical or warning in words', () => {
        assert.match(view('pages/backup-recovery.blade.php'), /data-backup-warning-severity=/);
        assert.match(view('pages/system-status.blade.php'), /data-system-issue-severity="critical"/);
        assert.match(view('pages/system-status.blade.php'), /data-system-issue-severity="warning"/);
    });

    test('section titles on backup and system status are headings', () => {
        for (const file of ['pages/backup-recovery.blade.php', 'pages/system-status.blade.php']) {
            assert.doesNotMatch(view(file), /<div class="mb-3 text-base font-semibold uppercase tracking-\[0\.16em\]/, file);
        }
    });

    test('progress bars have the progressbar role and a value range', () => {
        for (const file of ['pages/system-status.blade.php', 'pages/doffin-harvest.blade.php', 'pages/doffin-supplier-harvest-run.blade.php']) {
            const source = view(file);
            assert.match(source, /role="progressbar"/, file);
            assert.match(source, /aria-valuemin="0"/, file);
            assert.match(source, /aria-valuemax="100"/, file);
        }

        // The SVG ring is a picture inside the progress bar, never named itself.
        assert.doesNotMatch(view('pages/system-status.blade.php'), /progressRing\.setAttribute\(\s*'aria-label'/);
    });

    test('system status: a pause control instead of an unstoppable wire:poll', () => {
        const source = view('pages/system-status.blade.php');
        assert.doesNotMatch(source, /wire:poll/);
        assert.match(source, /data-scheduler-live-toggle/);
        assert.match(source, /if \(window\.__procyniaSchedulerPaused\)/);
    });

    test('the harvest monitor keeps its poll (it stops itself when the run ends) and says phase changes', () => {
        const source = view('pages/doffin-supplier-harvest-run.blade.php');
        assert.match(source, /wire:poll\.3s="refreshRunStatus"/);
        assert.match(source, /role="status" data-harvest-run-phase/);
        assert.match(source, /motion-reduce:animate-none/);
    });
});

describe('Tables and scroll boxes in every custom view', () => {
    test('every header cell in a <thead> has scope="col"', () => {
        for (const { name, source } of VIEWS) {
            for (const head of source.matchAll(/<thead\b[\s\S]*?<\/thead>/g)) {
                for (const th of head[0].matchAll(/<th\b[^>]*>/g)) {
                    assert.match(th[0], /scope="col"/, `${name}: ${th[0]}`);
                }
            }
        }
    });

    test('no empty header cells', () => {
        for (const { name, source } of VIEWS) {
            assert.doesNotMatch(source, /<th\b[^>]*>\s*<\/th>/, name);
        }
    });

    test('every scroll box around a table becomes a named, focusable region and is positioned', () => {
        for (const { name, source } of VIEWS) {
            for (const box of source.matchAll(/<div class="([^"]*(?:overflow-x-auto|exp-scroll)[^"]*)"([^>]*)>\s*<table/g)) {
                assert.match(box[2], /scroll-region-attrs/, `${name}: ${box[1]}`);
                assert.match(box[1], /(^|\s)relative(\s|$)/, `${name}: ${box[1]} (an sr-only caption would widen the page)`);
            }
        }
    });

    test('the scroll-region partial has no Blade conditional (Livewire markers would land inside the tag)', () => {
        assert.doesNotMatch(view('components/scroll-region-attrs.blade.php'), /@if|@foreach|@unless/);
    });

    test('custom inputs and selects use the field-border token', () => {
        for (const { name, source } of VIEWS) {
            for (const control of source.matchAll(/<(input|select)\b[^>]*class="([^"]*)"/g)) {
                assert.match(control[2], /border-field-border/, `${name}: <${control[1]} class="${control[2].slice(0, 80)}">`);
            }
        }

        assert.match(read('resources', 'css', 'filament', 'admin', 'theme.css'), /--color-field-border:/);
    });
});

describe('A11Y-212: focus to the first invalid field after a rejected submit', () => {
    test('the panel registers the focus script as a render hook', () => {
        const provider = read('app', 'Providers', 'Filament', 'AdminPanelProvider.php');
        assert.match(provider, /renderHook\(PanelsRenderHook::BODY_END, fn \(\): string => view\('filament\.components\.focus-first-error'\)->render\(\)\)/);
        assert.match(view('components/focus-first-error.blade.php'), /addEventListener\('form-validation-error'/);
    });
});

describe('A11Y-105: Filament help is translatable', () => {
    test('the help action reads its labels through AdminText with Norwegian fallbacks', () => {
        const source = read('app', 'Filament', 'Concerns', 'HasAdminPageHelp.php');
        assert.doesNotMatch(source, /->label\('Hjelp'\)/);
        assert.doesNotMatch(source, /->modalCancelActionLabel\('Lukk'\)/);
        assert.match(source, /AdminText::get\('admin_a11y\.help_button', 'Hjelp'\)/);
    });
});
