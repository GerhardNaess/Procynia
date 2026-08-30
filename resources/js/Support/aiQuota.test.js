import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import * as aiQuota from './aiQuota.js';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Reads the ai_quota.* copy straight out of the PHP language files, so these tests fail when a
 * status exists without human wording — not only when the helper's own logic breaks.
 */
function readStatusCopy(locale) {
    const php = readFileSync(join(here, `../../../lang/${locale}/procynia.php`), 'utf8');
    const block = php.slice(php.indexOf("'ai_quota' => ["));
    const read = (section) => {
        const start = block.indexOf(`'${section}' => [`);
        const slice = block.slice(start, block.indexOf('],', start));
        const found = {};
        const pattern = /'([a-z_]+)' => '((?:[^'\\]|\\.)*)'/g;
        let match = pattern.exec(slice);
        while (match) {
            found[match[1]] = match[2];
            match = pattern.exec(slice);
        }
        return found;
    };

    return { statuses: read('statuses'), status_descriptions: read('status_descriptions') };
}

const TEXTS = {
    heading: 'AI-kapasitet',
    unlimited: 'Ubegrenset',
    unlimited_description: 'Abonnementet har ubegrenset antall AI-anbudssaker.',
    not_included: 'Abonnementet inkluderer ikke AI-anbudssaker.',
    used_of: ':used av :allowance AI-saker brukt',
    remaining: ':remaining gjenstår',
    compact: ':used av :allowance AI-saker brukt denne måneden',
    compact_unlimited: 'Ubegrenset AI-kapasitet',
    progress_label: ':percent % av AI-kapasiteten er brukt',
    period_range: ':start–:end',
    statuses: {
        normal: 'Normal',
        warning: 'Nærmer seg grensen',
        critical: 'Snart oppbrukt',
        exhausted: 'Kapasitet brukt',
        suspended: 'AI-tilgang suspendert',
    },
    status_descriptions: {
        normal: 'Du har kapasitet igjen denne perioden.',
        exhausted: 'Ny AI-sak kan ikke startes før neste periode.',
        suspended: 'AI-tilgangen er midlertidig deaktivert.',
    },
};

function quota(overrides = {}) {
    return {
        quota_type: 'finite',
        included: 10,
        extra: 0,
        allowance: 10,
        used: 2,
        reserved: 0,
        remaining: 8,
        period_start: '2026-08-01',
        period_end: '2026-08-31',
        percentage_used: 20,
        status: 'normal',
        is_unlimited: false,
        is_suspended: false,
        ...overrides,
    };
}

describe('AI quota headline and counts', () => {
    test('a finite quota reads as used-of-allowance', () => {
        assert.equal(aiQuota.headline(quota(), TEXTS), '2 av 10 AI-saker brukt');
        assert.equal(aiQuota.remainingLabel(quota(), TEXTS), '8 gjenstår');
    });

    test('a reserved case counts as consumed so the customer is not promised a spent credit', () => {
        const withReservation = quota({ used: 2, reserved: 1, remaining: 7 });

        assert.equal(aiQuota.consumed(withReservation), 3);
        assert.equal(aiQuota.headline(withReservation, TEXTS), '3 av 10 AI-saker brukt');
    });

    test('an unlimited plan never shows a count, a percentage or a bar', () => {
        const unlimited = quota({ quota_type: 'unlimited', is_unlimited: true, allowance: 0, remaining: null, percentage_used: null });

        assert.equal(aiQuota.headline(unlimited, TEXTS), TEXTS.unlimited_description);
        assert.equal(aiQuota.compactLabel(unlimited, TEXTS), TEXTS.compact_unlimited);
        assert.equal(aiQuota.remainingLabel(unlimited, TEXTS), '');
        assert.equal(aiQuota.showsProgressBar(unlimited), false, 'A fake 100% bar would say the opposite of the truth.');
    });

    test('a plan without AI says so instead of showing 0 of 0', () => {
        const none = quota({ quota_type: 'none', included: 0, allowance: 0, used: 0, remaining: 0, percentage_used: null });

        assert.equal(aiQuota.headline(none, TEXTS), TEXTS.not_included);
        assert.equal(aiQuota.showsProgressBar(none), false);
    });

    test('a remainder is never rendered as negative after a downgrade', () => {
        const downgraded = quota({ included: 3, allowance: 3, used: 10, remaining: 0, percentage_used: 100, status: 'exhausted' });

        assert.equal(aiQuota.remaining(downgraded), 0);
        assert.equal(aiQuota.headline(downgraded, TEXTS), '10 av 3 AI-saker brukt');
    });
});

describe('AI quota status presentation', () => {
    test('each status maps to its own tone and label', () => {
        for (const [status, tone] of Object.entries({ normal: 'green', warning: 'amber', critical: 'amber', exhausted: 'rose', suspended: 'rose' })) {
            const current = quota({ status });
            assert.equal(aiQuota.statusTone(current), tone, `tone for ${status}`);
            assert.equal(aiQuota.statusLabel(current, TEXTS), TEXTS.statuses[status], `label for ${status}`);
        }
    });

    test('every status a customer can be in has wording in both locales', () => {
        for (const locale of ['no', 'en']) {
            const copy = readStatusCopy(locale);

            for (const status of Object.values(aiQuota.QUOTA_STATUS)) {
                assert.ok(copy.statuses[status], `${locale}: missing status label for ${status}`);
                assert.ok(copy.status_descriptions[status], `${locale}: missing status description for ${status}`);
            }
        }
    });

    test('status is never conveyed by colour alone', () => {
        // Every tone the card can paint must be backed by a distinct text label.
        const labels = Object.values(aiQuota.QUOTA_STATUS).map((status) => aiQuota.statusLabel(quota({ status }), TEXTS));

        assert.equal(new Set(labels).size, labels.length, 'Two statuses share a label, so colour becomes the only difference.');
        assert.ok(labels.every((label) => label.length > 0));
    });

    test('an unknown status falls back to normal rather than rendering blank', () => {
        assert.equal(aiQuota.statusKey(quota({ status: 'something-new' })), 'normal');
        assert.equal(aiQuota.statusTone(quota({ status: undefined })), 'green');
    });

    test('the progress bar always carries an accessible text label', () => {
        assert.equal(aiQuota.progressAccessibleLabel(quota({ percentage_used: 80 }), TEXTS), '80 % av AI-kapasiteten er brukt');
    });

    test('percentage is clamped into a renderable range', () => {
        assert.equal(aiQuota.percentage(quota({ percentage_used: 140 })), 100);
        assert.equal(aiQuota.percentage(quota({ percentage_used: -5 })), 0);
        assert.equal(aiQuota.percentage(quota({ percentage_used: null })), 0);
    });
});

describe('AI quota period', () => {
    test('the period is rendered as a readable range', () => {
        assert.equal(aiQuota.periodLabel(quota(), TEXTS, 'nb-NO'), '1. august 2026–31. august 2026');
    });

    test('the next reset is the day after the period ends', () => {
        assert.equal(aiQuota.nextResetLabel(quota(), 'nb-NO'), '1. september 2026');
    });

    test('an unlimited plan has no reset to advertise', () => {
        assert.equal(aiQuota.nextResetLabel(quota({ is_unlimited: true }), 'nb-NO'), '');
    });

    test('a missing period does not crash the card', () => {
        assert.equal(aiQuota.periodLabel({ ...quota(), period_start: null }, TEXTS), '');
        assert.equal(aiQuota.nextResetLabel({ ...quota(), period_end: null }), '');
    });
});

describe('AI quota guards', () => {
    test('a missing quota payload is handled without throwing', () => {
        // The card renders before the prop exists on a partial Inertia reload.
        assert.equal(aiQuota.headline(null, TEXTS), '0 av 0 AI-saker brukt');
        assert.equal(aiQuota.consumed(undefined), 0);
        assert.equal(aiQuota.remaining(undefined), 0);
        assert.equal(aiQuota.showsProgressBar(null), false);
        assert.equal(aiQuota.statusLabel(null, TEXTS), TEXTS.statuses.normal);
    });
});
