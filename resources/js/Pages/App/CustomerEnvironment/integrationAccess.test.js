import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { integrationAccessLines } from './integrationAccess.js';

describe('what an integration can read', () => {
    test('a readable module with «Alle» reads as all fagområder', () => {
        assert.deepEqual(
            integrationAccessLines({ risk: { entitled: true, readable: true, business_areas: 'all' } }),
            ['Risiko: alle fagområder'],
        );
    });

    test('chosen fagområder are named', () => {
        assert.deepEqual(
            integrationAccessLines({ risk: { entitled: true, readable: true, business_areas: [{ id: 1, name: 'Beredskap' }, { id: 2, name: 'Drift' }] } }),
            ['Risiko: Beredskap, Drift'],
        );
    });

    test('every module the API serves has a Norwegian label', () => {
        assert.deepEqual(
            integrationAccessLines({
                risk: { entitled: true, readable: true, business_areas: 'all' },
                deviations: { entitled: true, readable: true, business_areas: 'all' },
                objectives: { entitled: true, readable: true, business_areas: [{ id: 3, name: 'Salg' }] },
            }),
            ['Risiko: alle fagområder', 'Avvik: alle fagområder', 'Mål og KPI: Salg'],
        );
    });

    test('a module not ordered or not readable is left out', () => {
        assert.deepEqual(integrationAccessLines({ risk: { entitled: false, readable: false, business_areas: [] } }), []);
        assert.deepEqual(integrationAccessLines({ risk: { entitled: true, readable: false, business_areas: [] } }), []);
        assert.deepEqual(integrationAccessLines(undefined), []);
    });

    test('labels come from the translations when given', () => {
        assert.deepEqual(
            integrationAccessLines({ risk: { entitled: true, readable: true, business_areas: 'all' } }, { modules: { risk: 'Risk' }, all_areas: 'all business areas' }),
            ['Risk: all business areas'],
        );
    });
});
