import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Keeps the server's side of an E2E failure next to its trace (KTF-4, KTF-5).
 *
 * With preserveOutput 'failures-only', the run's output folder holds a folder per failed test and
 * nothing else. For each, this writes server-logs.txt: the nginx and PHP-FPM container logs from a
 * few minutes before the test's first file to just after its last — enough to see a 499, a 503 or a
 * full PHP-FPM pool next to the trace. A global teardown runs whatever --reporter is chosen; a
 * reporter would not (the CLI flag replaces the configured ones). Never fails the run.
 */
const CONTAINERS = ['procynia-web', 'procynia-app'];
const BEFORE_MS = 3 * 60_000;

export default async function globalTeardown(config) {
    const outputDir = config.projects?.[0]?.outputDir;

    if (!outputDir || !fs.existsSync(outputDir)) {
        return;
    }

    for (const entry of fs.readdirSync(outputDir, { withFileTypes: true })) {
        if (!entry.isDirectory()) {
            continue;
        }

        const dir = path.join(outputDir, entry.name);

        try {
            const times = fs.readdirSync(dir).map((file) => fs.statSync(path.join(dir, file)).mtimeMs);

            if (times.length === 0) {
                continue;
            }

            const since = new Date(Math.min(...times) - BEFORE_MS).toISOString();
            const until = new Date(Math.max(...times) + 5_000).toISOString();
            const sections = [`window: ${since} – ${until}\n`];

            for (const container of CONTAINERS) {
                try {
                    const logs = execFileSync('docker', ['logs', '--since', since, '--until', until, container], {
                        encoding: 'utf8',
                        stdio: ['ignore', 'pipe', 'pipe'],
                        maxBuffer: 64 * 1024 * 1024,
                    });
                    sections.push(`===== ${container} (stdout) =====\n${logs}`);
                } catch (error) {
                    sections.push(`===== ${container} =====\nnot available: ${error.message}`);
                }

                // docker logs sends the container's stderr (PHP-FPM warnings, nginx errors) to stderr.
                try {
                    const result = execFileSync('sh', ['-c', `docker logs --since ${since} --until ${until} ${container} 2>&1 1>/dev/null`], {
                        encoding: 'utf8',
                        maxBuffer: 64 * 1024 * 1024,
                    });
                    sections.push(`===== ${container} (stderr) =====\n${result}`);
                } catch {
                    // already noted above
                }
            }

            fs.writeFileSync(path.join(dir, 'server-logs.txt'), sections.join('\n'));
        } catch {
            // Keeping evidence must never change a run's result.
        }
    }
}
