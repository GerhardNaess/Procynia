import { execSync } from 'node:child_process';

/**
 * Runs once before all E2E tests. Seeds the test users into the app database
 * so every subsequent test can log in with known, stable credentials.
 *
 * Tries Docker first (canonical runtime per docker-compose.yml), then falls
 * back to a direct artisan call for non-Docker local dev.
 */
export default async function globalSetup() {
    const cwd = new URL('../..', import.meta.url).pathname;

    try {
        // Docker is the canonical runtime — seed inside the running container
        execSync(
            'docker compose exec -T app php artisan db:seed --class=E2ETestSeeder',
            { stdio: 'inherit', cwd },
        );
    } catch {
        // Fall back to direct invocation (local PHP without Docker)
        execSync('php artisan db:seed --class=E2ETestSeeder', {
            stdio: 'inherit',
            cwd,
        });
    }
}
