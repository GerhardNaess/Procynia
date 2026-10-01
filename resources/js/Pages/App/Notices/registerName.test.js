import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

import { withRegisterName } from './registerName.js';

const indexSource = readFileSync(fileURLToPath(new URL('./Index.jsx', import.meta.url)), 'utf8');

describe('the register name in the text', () => {
    it('fills the place the lang file left for it', () => {
        assert.equal(withRegisterName('treff fra :source', 'TED'), 'treff fra TED');
        assert.equal(withRegisterName('Live søk i :source', 'Doffin'), 'Live søk i Doffin');
    });

    it('fills every place in one string', () => {
        assert.equal(
            withRegisterName('Ingen treff fra :source. Søk direkte i :source.', 'TED'),
            'Ingen treff fra TED. Søk direkte i TED.',
        );
    });

    it('leaves the placeholders the page fills itself alone', () => {
        assert.equal(
            withRegisterName(':source rapporterer :total treff, :accessible tilgjengelige.', 'TED'),
            'TED rapporterer :total treff, :accessible tilgjengelige.',
        );
    });

    it('walks a whole translation tree and leaves everything else as it is', () => {
        const translations = {
            live_title: 'Live søk i :source',
            card: { default_category: 'Kategori: kunngjøring' },
            sections: [{ title: 'Åpne i :source' }],
            enabled: true,
            count: 12,
            missing: null,
        };

        assert.deepEqual(withRegisterName(translations, 'TED'), {
            live_title: 'Live søk i TED',
            card: { default_category: 'Kategori: kunngjøring' },
            sections: [{ title: 'Åpne i TED' }],
            enabled: true,
            count: 12,
            missing: null,
        });
    });

    it('does not mutate what it was given', () => {
        const translations = { live_title: 'Live søk i :source' };

        withRegisterName(translations, 'TED');

        assert.equal(translations.live_title, 'Live søk i :source');
    });
});

describe('the live search source selector', () => {
    it('reads the registers from the backend rather than listing them here', () => {
        assert.match(indexSource, /const sourceOptions = Array\.isArray\(source\?\.options\)/);
        assert.doesNotMatch(indexSource, /sourceOptions\s*=\s*\[/);
    });

    it('defaults to Doffin when the URL names no register', () => {
        assert.match(indexSource, /const activeSourceKey = source\?\.key \?\? 'doffin';/);
    });

    it('puts the register in the query, so filters and paging stay in it', () => {
        assert.match(indexSource, /source: activeSourceKey,/);
        assert.match(indexSource, /liveSearchQuery\(\{ source: nextSource \}\)/);
    });

    it('runs both translation trees through the register name', () => {
        assert.match(indexSource, /const tf = withRegisterName\(translations\?\.frontend \?\? \{\}, registerName\);/);
        assert.match(indexSource, /const nt = withRegisterName\(translations\?\.notices \?\? \{\}, registerName\);/);
    });
});
