import { execSync } from 'node:child_process';
import { E2E_APP_CONTAINER, E2E_DATABASE, E2E_WEB_CONTAINER, REPO_ROOT, artisanCommand } from './helpers/environment.js';

/**
 * Runs once before all E2E tests: proves the run is pointed at the isolated E2E environment
 * (docs/testing/e2e-environment.md), then seeds the test users into its database.
 *
 * It refuses to start — rather than run against the development stack — when
 *   - the E2E containers are not running (start them with scripts/e2e-env.sh up),
 *   - the app container is on any database but procynia_e2e, or not in APP_ENV=e2e,
 *   - the base URL is not the port the E2E nginx publishes,
 *   - the page under test loads from a Vite dev server instead of the built assets.
 *
 * There is no fallback to a host `php artisan`: that would read .env and seed the development
 * database.
 */
export default async function globalSetup(config) {
    const baseURL = new URL(config.projects?.[0]?.use?.baseURL ?? '');
    const run = (command) => execSync(command, { cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
    const refuse = (reason) => {
        throw new Error(`[e2e] ${reason}\nStart the isolated environment with scripts/e2e-env.sh up (docs/testing/e2e-environment.md).`);
    };

    for (const container of [E2E_APP_CONTAINER, E2E_WEB_CONTAINER]) {
        let running = '';
        try {
            running = run(`docker inspect -f '{{.State.Running}}' ${container}`);
        } catch {
            // not created
        }
        if (running !== 'true') {
            refuse(`${container} is not running.`);
        }
    }

    const published = run(`docker port ${E2E_WEB_CONTAINER} 80/tcp`).split('\n')[0];
    if (published !== `${baseURL.hostname}:${baseURL.port}`) {
        refuse(`baseURL ${baseURL.origin} is not the E2E nginx (${E2E_WEB_CONTAINER} publishes ${published}).`);
    }

    const identity = run(artisanCommand(`tinker --execute="echo json_encode(['db' => DB::connection()->getDatabaseName(), 'env' => app()->environment()]);"`));
    const { db, env } = JSON.parse(identity.match(/\{.*\}/)?.[0] ?? '{}');
    if (db !== E2E_DATABASE || env !== 'e2e') {
        refuse(`the E2E app container is on database ${db} in APP_ENV=${env}; expected ${E2E_DATABASE} in e2e.`);
    }

    const html = await (await fetch(new URL('/login', baseURL))).text();
    if (html.includes(':5173') || !html.includes('/build/assets/')) {
        refuse('the page under test does not load the built assets (npm run build); it points at a Vite dev server.');
    }

    execSync(artisanCommand('db:seed --class=E2ETestSeeder'), { stdio: 'inherit', cwd: REPO_ROOT });
}
