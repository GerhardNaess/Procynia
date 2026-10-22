#!/usr/bin/env node
/**
 * Runs the frontend unit tests (`npm run test:unit`): every `*.test.js` under resources/js.
 *
 * Why not `node --test "resources/js/**\/*.test.js"`: Node's own glob skips a directory it fails to
 * read without saying so. Twice (2026-10-22) a run reported 970 tests and 0 failures — every file
 * under resources/js/Pages (1160 tests) had silently not been found, and the run looked green. The
 * cause was not reproduced; a Docker bind mount on macOS is the likeliest place for a transient
 * read error.
 *
 * So the files are listed here, with errors that stop the run instead of shrinking it, and the
 * count per top-level directory is printed before anything runs, where a partial run is visible.
 */
import { spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const BASE = join(ROOT, 'resources', 'js');

function collect(directory) {
    // readdirSync throws on a directory it cannot read, which is the point.
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return entry.name === 'node_modules' ? [] : collect(path);
        }

        return entry.isFile() && entry.name.endsWith('.test.js') ? [path] : [];
    });
}

const files = collect(BASE).sort();

if (files.length === 0) {
    console.error('[test:unit] No *.test.js files found under resources/js.');
    process.exit(1);
}

const perDirectory = {};

for (const file of files) {
    const [top, second] = relative(BASE, file).split('/');
    const key = top === 'Pages' ? `${top}/${second}` : top;
    perDirectory[key] = (perDirectory[key] ?? 0) + 1;
}

console.log(`[test:unit] ${files.length} test files: ${Object.entries(perDirectory).map(([dir, count]) => `${dir} ${count}`).join(', ')}`);

const result = spawnSync(process.execPath, ['--test', ...files.map((file) => relative(ROOT, file))], {
    cwd: ROOT,
    stdio: 'inherit',
});

process.exit(result.status ?? 1);
