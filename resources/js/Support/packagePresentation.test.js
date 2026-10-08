import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { changeTargets, consequenceLines, formatList, packageActionLabel, packageStatus } from './packagePresentation.js';

const names = { basis: 'Basis', governance: 'Styring', iso: 'ISO', grc: 'GRC', tender: 'Anbud' };
const modules = { supplier: 'Leverandøroppfølging', compliance: 'Etterlevelse og revisjon', risk: 'Risiko', tender: 'Anbud' };
const packageName = (key) => names[key];
const moduleName = (key) => modules[key];

const entry = (key, overrides = {}) => ({
    key,
    kind: key === 'tender' ? 'addon' : 'main',
    orderable: true,
    status: 'available',
    included_in: null,
    action: null,
    direction: null,
    modules_lost: [],
    modules_gained: [],
    ...overrides,
});

// The overview a GRC customer with Anbud receives from ModuleEntitlementService::overviewFor().
const grcCustomer = [
    entry('basis', { status: 'included', included_in: 'grc', direction: 'downgrade', modules_lost: ['risk', 'compliance', 'supplier'] }),
    entry('governance', { status: 'included', included_in: 'grc', direction: 'downgrade', modules_lost: ['compliance', 'supplier'] }),
    entry('iso', { status: 'included', included_in: 'grc', direction: 'downgrade', modules_lost: ['supplier'] }),
    entry('grc', { status: 'active', action: 'change' }),
    entry('tender', { status: 'active', action: 'cancel', modules_lost: ['tender'] }),
];

describe('one active main package', () => {
    test('only the effective step reads Aktiv; the steps below read Inkludert i GRC', () => {
        assert.deepEqual(
            grcCustomer.map((row) => packageStatus(row, {}, packageName).label),
            ['Inkludert i GRC', 'Inkludert i GRC', 'Inkludert i GRC', 'Aktiv', 'Aktiv'],
        );
    });

    test('the active step offers Endre pakke, included steps offer nothing, Anbud offers Avbestill', () => {
        assert.deepEqual(
            grcCustomer.map((row) => packageActionLabel(row)),
            [null, null, null, 'Endre pakke', 'Avbestill'],
        );
    });

    test('a step above the active one offers Oppgrader and reads Ikke aktiv', () => {
        const iso = entry('grc', { action: 'upgrade', direction: 'upgrade' });

        assert.equal(packageActionLabel(iso), 'Oppgrader');
        assert.equal(packageStatus(iso).label, 'Ikke aktiv');
    });

    test('Endre pakke offers every other step, never the active one or Anbud', () => {
        assert.deepEqual(changeTargets(grcCustomer).map((row) => row.key), ['basis', 'governance', 'iso']);
    });
});

describe('the confirmation says what goes and that nothing is deleted', () => {
    test('GRC → ISO names Leverandøroppfølging', () => {
        assert.deepEqual(consequenceLines(grcCustomer[2], {}, moduleName), [
            'Leverandøroppfølging blir ikke lenger tilgjengelig.',
            'Registrerte data og historikk slettes ikke, og er der igjen hvis pakken aktiveres på nytt.',
        ]);
    });

    test('GRC → Styring also names Etterlevelse og revisjon', () => {
        assert.match(consequenceLines(grcCustomer[1], {}, moduleName)[0], /^Etterlevelse og revisjon og Leverandøroppfølging blir ikke/);
    });

    test('GRC → Basis lists the modules briefly', () => {
        assert.match(consequenceLines(grcCustomer[0], {}, moduleName)[0], /^Risiko, Etterlevelse og revisjon og Leverandøroppfølging blir ikke/);
    });

    test('cancelling Anbud says Anbud goes and nothing is deleted', () => {
        const lines = consequenceLines(grcCustomer[4], {}, moduleName);

        assert.equal(lines[0], 'Anbud blir ikke lenger tilgjengelig.');
        assert.match(lines[1], /slettes ikke/);
    });

    test('an upgrade says what becomes available and that access still follows roles', () => {
        const lines = consequenceLines(entry('grc', { direction: 'upgrade', modules_gained: ['supplier'] }), {}, moduleName);

        assert.deepEqual(lines, [
            'Leverandøroppfølging blir tilgjengelig.',
            'Tilganger endres ikke automatisk: brukere får bare tilgang gjennom rollene sine.',
        ]);
    });
});

test('formatList joins the Norwegian way', () => {
    assert.equal(formatList(['A']), 'A');
    assert.equal(formatList(['A', 'B']), 'A og B');
    assert.equal(formatList(['A', 'B', 'C'], 'and'), 'A, B and C');
});
