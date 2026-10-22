/**
 * Where the E2E run's server-side commands go: the isolated E2E environment
 * (docs/testing/e2e-environment.md, scripts/e2e-env.sh). Every fixture, seed and tinker call runs in
 * the e2e-app container, which only knows procynia_e2e as a role that cannot reach the development
 * database — never in the development app container.
 */
export const E2E_APP_SERVICE = 'e2e-app';
export const E2E_APP_CONTAINER = 'procynia-e2e-app';
export const E2E_WEB_CONTAINER = 'procynia-e2e-web';
export const E2E_DATABASE = 'procynia_e2e';
export const E2E_BASE_URL = 'http://127.0.0.1:8091';

/** The repository root, the working directory for docker compose. */
export const REPO_ROOT = new URL('../../..', import.meta.url).pathname;

/**
 * A shell command that runs `php artisan <args>` in the E2E app container. E2E_TINKER_WORKDIR runs it
 * from a git worktree's checkout instead of the main one.
 */
export function artisanCommand(args) {
    const workdir = process.env.E2E_TINKER_WORKDIR ? `-w ${process.env.E2E_TINKER_WORKDIR} ` : '';

    return `docker compose --profile e2e exec -T ${workdir}${E2E_APP_SERVICE} php artisan ${args}`;
}
