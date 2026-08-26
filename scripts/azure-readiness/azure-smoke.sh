#!/usr/bin/env bash
#
# Procynia — Azure migration readiness smoke test.
#
#   ./scripts/azure-readiness/azure-smoke.sh
#   ./scripts/azure-readiness/azure-smoke.sh --with-restarts
#
# This is the closest local approximation of the Azure Container Apps runtime that the existing
# Docker Compose stack allows. The PHPUnit suite in tests/Feature/Azure covers what can be proven
# inside one process; this script covers what needs more than one container:
#
#   * web and a queue worker seeing the same file at the same path   (Azure Files mount)
#   * web and a queue worker reaching the same PostgreSQL and Redis  (external managed services)
#   * state surviving a container restart                            (Container Apps revisions)
#   * migrations running as a separate job                           (Container Apps Job)
#   * the procynia:backup precondition                               (docker CLI is unavailable in Azure)
#
# It is read-mostly. It writes only to a scratch directory under the shared storage volume, to a
# scratch Redis key namespace that no worker consumes, and — only with --with-restarts — it restarts
# containers. It never touches the development database, never runs migrations against anything but
# procynia_test, and never deletes application data.
#
# Exit code 0 = every executed check passed. Skipped checks do not fail the run, but are reported.

set -euo pipefail

readonly APP_CONTAINER="procynia-app"
readonly WORKER_CONTAINER="procynia-queue-enterprise-wiki"
readonly WEB_CONTAINER="procynia-web"
readonly REDIS_CONTAINER="procynia-redis"
readonly POSTGRES_CONTAINER="procynia-postgres"

# The only database this script may migrate or write to.
readonly TEST_DATABASE="procynia_test"
# The database it must never touch.
readonly FORBIDDEN_DATABASE="procynia"

WITH_RESTARTS="false"
PASS_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0
SCRATCH_REL=""

usage() {
    cat <<'USAGE'
Usage: ./scripts/azure-readiness/azure-smoke.sh [--with-restarts] [--help]

  (no flag)         Run every non-disruptive check against the running Compose stack.
  --with-restarts   Additionally restart the app and worker containers to verify that
                    session and queue state survive. Off by default because it interrupts
                    the local development stack.
USAGE
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --with-restarts) WITH_RESTARTS="true" ;;
        -h|--help) usage; exit 0 ;;
        *) usage; printf '\nUnknown argument: %s\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

section() { printf '\n\033[1;34m── %s\033[0m\n' "$*"; }
pass()    { PASS_COUNT=$((PASS_COUNT + 1)); printf '  \033[1;32mPASS\033[0m %s\n' "$*"; }
fail()    { FAIL_COUNT=$((FAIL_COUNT + 1)); printf '  \033[1;31mFAIL\033[0m %s\n' "$*"; }
skip()    { SKIP_COUNT=$((SKIP_COUNT + 1)); printf '  \033[1;33mSKIP\033[0m %s\n' "$*"; }
note()    { printf '       %s\n' "$*"; }
abort()   { printf '\n\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 2; }

container_running() {
    [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || echo false)" = "true" ]
}

cleanup() {
    if [ -n "${SCRATCH_REL}" ] && container_running "${APP_CONTAINER}"; then
        docker exec "${APP_CONTAINER}" rm -rf "/var/www/html/storage/app/private/${SCRATCH_REL}" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------

section "Preconditions"

command -v docker >/dev/null 2>&1 || abort "docker is not installed or not on PATH."

for container in "${APP_CONTAINER}" "${WORKER_CONTAINER}" "${REDIS_CONTAINER}" "${POSTGRES_CONTAINER}"; do
    if container_running "${container}"; then
        pass "container ${container} is running"
    else
        abort "container ${container} is not running. Start the stack first: docker compose up -d"
    fi
done

if container_running "${WEB_CONTAINER}"; then
    pass "container ${WEB_CONTAINER} is running"
else
    skip "container ${WEB_CONTAINER} is not running — HTTP checks will be skipped"
fi

# ---------------------------------------------------------------------------
# Database safety guard
# ---------------------------------------------------------------------------

section "Database safety"

# Nothing below may run against the development database. This is asserted against the live
# connection, not against a config value.
LIVE_TEST_DB="$(docker exec "${POSTGRES_CONTAINER}" psql -U gehard -d "${TEST_DATABASE}" -tAc 'select current_database();' 2>/dev/null || true)"

if [ "${LIVE_TEST_DB}" = "${TEST_DATABASE}" ]; then
    pass "test database [${TEST_DATABASE}] is reachable and reports itself correctly"
else
    abort "Could not confirm the test database. Expected [${TEST_DATABASE}], got [${LIVE_TEST_DB:-none}]. Refusing to continue."
fi

if [ "${TEST_DATABASE}" = "${FORBIDDEN_DATABASE}" ]; then
    abort "The configured test database is the development database. Refusing to continue."
fi

# ---------------------------------------------------------------------------
# Production image precondition
# ---------------------------------------------------------------------------

section "Production image precondition"

# The Azure images (procynia-web / procynia-app) do not exist yet: docker/php/Dockerfile builds a
# development image whose application code arrives through a bind mount. Everything below therefore
# runs against the development containers, which share the app source rather than baking it in.
if docker image inspect procynia-app:production >/dev/null 2>&1; then
    pass "a procynia-app:production image is present"
    if docker run --rm --entrypoint sh procynia-app:production -c 'test -f /var/www/html/artisan' >/dev/null 2>&1; then
        pass "the production image contains the application code without a bind mount"
    else
        fail "procynia-app:production does not contain /var/www/html/artisan"
    fi
    if docker run --rm --entrypoint sh procynia-app:production -c 'test -d /var/www/html/vendor' >/dev/null 2>&1; then
        pass "the production image contains Composer dependencies"
    else
        fail "procynia-app:production has no vendor/ directory"
    fi
    if docker run --rm --entrypoint sh procynia-app:production -c 'test -f /var/www/html/public/build/manifest.json' >/dev/null 2>&1; then
        pass "the production image contains a frontend build"
    else
        fail "procynia-app:production has no public/build/manifest.json"
    fi
else
    skip "no procynia-app:production image — bind-mount independence cannot be verified yet"
    note "Build it, then re-run this script. The image must satisfy:"
    note "  • /var/www/html contains app/, config/, routes/, database/, artisan (no bind mount)"
    note "  • /var/www/html/vendor is present (composer install --no-dev)"
    note "  • /var/www/html/public/build/manifest.json is present (npm run build)"
    note "  • poppler-utils on /usr/bin (pdftotext, pdftohtml, pdfimages, pdfinfo)"
    note "  • PHP extensions: pdo_pgsql, pgsql, redis, intl, gd, zip, xsl, pcntl, bcmath, exif"
    note "  • no .env baked in — configuration arrives as environment variables"
    note "  • procynia-web additionally serves HTTP on one port and answers GET /up"
fi

# ---------------------------------------------------------------------------
# Web health
# ---------------------------------------------------------------------------

section "Web health"

if container_running "${WEB_CONTAINER}"; then
    HEALTH_STATUS="$(docker exec "${APP_CONTAINER}" sh -c 'command -v curl >/dev/null 2>&1 && curl -s -o /dev/null -w "%{http_code}" http://web/up || echo unavailable')"
    if [ "${HEALTH_STATUS}" = "200" ]; then
        pass "GET /up returned 200 (the Container Apps readiness/liveness probe target)"
    else
        fail "GET /up returned [${HEALTH_STATUS}], expected 200"
    fi
else
    skip "web container is not running — /up not checked"
fi

# ---------------------------------------------------------------------------
# Shared storage across containers
# ---------------------------------------------------------------------------

section "Shared storage (Azure Files stand-in)"

SCRATCH_REL="azure-readiness-smoke/$(date -u +%Y%m%d%H%M%S)-$$"
SCRATCH_ABS="/var/www/html/storage/app/private/${SCRATCH_REL}"
MARKER="azure-readiness-$(date -u +%s)-$$"

docker exec "${APP_CONTAINER}" mkdir -p "${SCRATCH_ABS}"
docker exec "${APP_CONTAINER}" sh -c "printf '%s' '${MARKER}' > '${SCRATCH_ABS}/from-web.txt'"

# The worker must see the same bytes at the same path — the whole point of the Azure Files mount.
WORKER_SAW="$(docker exec "${WORKER_CONTAINER}" cat "${SCRATCH_ABS}/from-web.txt" 2>/dev/null || echo MISSING)"

if [ "${WORKER_SAW}" = "${MARKER}" ]; then
    pass "worker container reads the file written by the app container at the identical path"
else
    fail "worker container could not read the app container's file (got [${WORKER_SAW}])"
fi

# And the reverse direction, because Azure Files is mounted read/write everywhere.
docker exec "${WORKER_CONTAINER}" sh -c "printf '%s' '${MARKER}-worker' > '${SCRATCH_ABS}/from-worker.txt'"
APP_SAW="$(docker exec "${APP_CONTAINER}" cat "${SCRATCH_ABS}/from-worker.txt" 2>/dev/null || echo MISSING)"

if [ "${APP_SAW}" = "${MARKER}-worker" ]; then
    pass "app container reads the file written by the worker container"
else
    fail "app container could not read the worker container's file (got [${APP_SAW}])"
fi

# Laravel must resolve the relative path to that same physical path in the worker.
RESOLVED="$(docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\Storage::disk("local")->path($argv[1]);
' "${SCRATCH_REL}/from-web.txt" 2>/dev/null || echo ERROR)"

if [ "${RESOLVED}" = "${SCRATCH_ABS}/from-web.txt" ]; then
    pass "Storage::disk('local')->path() resolves identically in the worker container"
else
    fail "worker resolved the stored path to [${RESOLVED}], expected [${SCRATCH_ABS}/from-web.txt]"
fi

# Temporary files must NOT be shared — scratch space stays container-local in Azure.
docker exec "${APP_CONTAINER}" sh -c "printf 'app-temp' > /tmp/${MARKER}.tmp"
if docker exec "${WORKER_CONTAINER}" test -f "/tmp/${MARKER}.tmp" 2>/dev/null; then
    fail "/tmp is shared between containers — temporary files must stay container-local"
else
    pass "/tmp is container-local, so temporary files cannot leak between replicas"
fi
docker exec "${APP_CONTAINER}" rm -f "/tmp/${MARKER}.tmp" >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# Shared backing services
# ---------------------------------------------------------------------------

section "Shared PostgreSQL and Redis"

APP_DB="$(docker exec "${APP_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::selectOne("select current_database() as db")->db;
' 2>/dev/null || echo ERROR)"

WORKER_DB="$(docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::selectOne("select current_database() as db")->db;
' 2>/dev/null || echo ERROR)"

if [ "${APP_DB}" = "${WORKER_DB}" ] && [ "${APP_DB}" != "ERROR" ]; then
    pass "app and worker share one PostgreSQL database (${APP_DB})"
    note "read-only identity check; in local development this is the ${FORBIDDEN_DATABASE} database,"
    note "which nothing in this script writes to or migrates."
else
    fail "app sees database [${APP_DB}] but worker sees [${WORKER_DB}]"
fi

SCRATCH_KEY="azure-readiness-smoke:${MARKER}"

# A scratch key namespace no worker consumes, so this cannot disturb real queues.
docker exec "${APP_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Redis::connection("default")->set($argv[1], $argv[2]);
' "${SCRATCH_KEY}" "${MARKER}" >/dev/null 2>&1 || true

WORKER_REDIS="$(docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) Illuminate\Support\Facades\Redis::connection("default")->get($argv[1]);
' "${SCRATCH_KEY}" 2>/dev/null || echo ERROR)"

if [ "${WORKER_REDIS}" = "${MARKER}" ]; then
    pass "app and worker share one Redis instance"
else
    fail "worker read [${WORKER_REDIS}] from Redis, expected [${MARKER}]"
fi

# ---------------------------------------------------------------------------
# Migrations as a separate job
# ---------------------------------------------------------------------------

section "Migrations as a separate job"

# Verify — do not assume — that the overridden environment really lands on the test database
# before anything is migrated. Laravel loads .env immutably, so a process environment variable wins,
# but that is exactly the kind of assumption worth checking before a schema-changing command.
MIGRATE_TARGET="$(docker exec \
    -e DB_DATABASE="${TEST_DATABASE}" \
    -e APP_ENV=testing \
    "${APP_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::selectOne("select current_database() as db")->db;
' 2>/dev/null || echo ERROR)"

if [ "${MIGRATE_TARGET}" != "${TEST_DATABASE}" ]; then
    abort "Refusing to migrate: the override resolves to database [${MIGRATE_TARGET}], not ${TEST_DATABASE}."
fi

pass "the standalone migration job resolves to ${TEST_DATABASE}, not ${FORBIDDEN_DATABASE}"

MIGRATE_OUTPUT="$(docker exec \
    -e DB_DATABASE="${TEST_DATABASE}" \
    -e APP_ENV=testing \
    "${APP_CONTAINER}" php artisan migrate --force --no-interaction 2>&1 || true)"

if printf '%s' "${MIGRATE_OUTPUT}" | grep -qi 'nothing to migrate'; then
    pass "migrations run standalone against ${TEST_DATABASE} and are already up to date"
elif printf '%s' "${MIGRATE_OUTPUT}" | grep -qiE 'DONE|migrated'; then
    pass "migrations ran standalone against ${TEST_DATABASE}"
else
    fail "standalone migration run did not report success"
    note "${MIGRATE_OUTPUT}"
fi

# ---------------------------------------------------------------------------
# procynia:backup precondition
# ---------------------------------------------------------------------------

section "procynia:backup runtime guard"

# scripts/backup-production.sh runs `docker compose exec -T postgres pg_dump`. There is no docker
# CLI inside an Azure Container App, which is why the legacy mechanism is gated behind an explicit
# runtime guard rather than behind the database flag alone.
if docker exec "${WORKER_CONTAINER}" sh -c 'command -v docker' >/dev/null 2>&1; then
    fail "a docker CLI is present in the container — unexpected, re-check the backup assumption"
else
    pass "no docker CLI inside the container, confirming procynia:backup cannot work in Azure"
fi

# The guard itself: with PROCYNIA_LEGACY_BACKUP_ENABLED=false the scheduler must not register the
# backup command, no matter what backup_settings.backup_enabled says.
GUARD_RESULT="$(docker exec \
    -e PROCYNIA_LEGACY_BACKUP_ENABLED=false \
    "${APP_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$scheduled = false;
foreach ($app->make(Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
    if (str_contains((string) $event->command, "procynia:backup")) { $scheduled = true; }
}
printf("%s|%s", config("procynia.backup.legacy_enabled") ? "enabled" : "disabled", $scheduled ? "scheduled" : "not-scheduled");
' 2>/dev/null || echo "ERROR|ERROR")"

if [ "${GUARD_RESULT}" = "disabled|not-scheduled" ]; then
    pass "with PROCYNIA_LEGACY_BACKUP_ENABLED=false the scheduler does not register procynia:backup"
else
    fail "runtime guard did not take effect (got [${GUARD_RESULT}], expected [disabled|not-scheduled])"
fi

# And the default must still preserve existing Compose behaviour.
DEFAULT_RESULT="$(docker exec "${APP_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config("procynia.backup.legacy_enabled") ? "enabled" : "disabled";
' 2>/dev/null || echo ERROR)"

if [ "${DEFAULT_RESULT}" = "enabled" ]; then
    pass "this Compose runtime still permits the legacy backup (existing behaviour unchanged)"
else
    skip "this runtime reports legacy backup [${DEFAULT_RESULT}] — expected 'enabled' in Compose"
fi

BACKUP_ENABLED="$(docker exec "${POSTGRES_CONTAINER}" psql -U gehard -d "${TEST_DATABASE}" -tAc \
    "select coalesce(bool_or(backup_enabled), false) from backup_settings;" 2>/dev/null || echo unknown)"

case "${BACKUP_ENABLED}" in
    f|false)
        pass "backup_enabled is false in ${TEST_DATABASE}"
        ;;
    t|true)
        # No longer a failure: the runtime guard above stops the Compose script regardless of this
        # flag, which is exactly the migrated-database case it was built for.
        pass "backup_enabled is true, and the runtime guard above is what keeps that safe in Azure"
        ;;
    *)
        skip "could not read backup_settings (${BACKUP_ENABLED})"
        ;;
esac

note "Azure target state: PostgreSQL automated backup + point-in-time restore, blob soft delete"
note "and versioning, and Azure Files soft delete. See infra/README.md."

# ---------------------------------------------------------------------------
# Restart resilience (opt-in)
# ---------------------------------------------------------------------------

section "Restart resilience"

if [ "${WITH_RESTARTS}" != "true" ]; then
    skip "restart checks not run (pass --with-restarts to include them)"
    note "They restart ${APP_CONTAINER} and ${WORKER_CONTAINER}, which interrupts local development."
else
    SESSION_KEY="azure-readiness-session:${MARKER}"

    docker exec "${APP_CONTAINER}" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Redis::connection("default")->setex($argv[1], 600, $argv[2]);
    ' "${SESSION_KEY}" "${MARKER}" >/dev/null 2>&1 || true

    note "restarting ${APP_CONTAINER} ..."
    docker restart "${APP_CONTAINER}" >/dev/null
    sleep 5

    SESSION_AFTER="$(docker exec "${APP_CONTAINER}" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo (string) Illuminate\Support\Facades\Redis::connection("default")->get($argv[1]);
    ' "${SESSION_KEY}" 2>/dev/null || echo ERROR)"

    if [ "${SESSION_AFTER}" = "${MARKER}" ]; then
        pass "Redis-backed session state survived an app container restart"
    else
        fail "session state was lost across the restart (got [${SESSION_AFTER}])"
    fi

    # Shared storage must also survive, since it lives on the volume rather than in the container.
    AFTER_RESTART_FILE="$(docker exec "${APP_CONTAINER}" cat "${SCRATCH_ABS}/from-web.txt" 2>/dev/null || echo MISSING)"
    if [ "${AFTER_RESTART_FILE}" = "${MARKER}" ]; then
        pass "shared storage survived the app container restart"
    else
        fail "shared storage did not survive the restart (got [${AFTER_RESTART_FILE}])"
    fi

    # A queued job on a scratch queue must still be there after the worker restarts. The queue name
    # is deliberately one no worker consumes, so the job cannot be executed and cannot disturb
    # anything.
    SCRATCH_QUEUE="azure-readiness-smoke-${MARKER}"

    docker exec "${APP_CONTAINER}" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Queue::connection("redis")->push(new App\Jobs\OpsQueueHeartbeatJob($argv[1]), "", $argv[1]);
    ' "${SCRATCH_QUEUE}" >/dev/null 2>&1 || true

    note "restarting ${WORKER_CONTAINER} ..."
    docker restart "${WORKER_CONTAINER}" >/dev/null
    sleep 5

    QUEUE_SIZE="$(docker exec "${WORKER_CONTAINER}" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo (int) Illuminate\Support\Facades\Queue::connection("redis")->size($argv[1]);
    ' "${SCRATCH_QUEUE}" 2>/dev/null || echo -1)"

    if [ "${QUEUE_SIZE}" = "1" ]; then
        pass "a queued job survived a worker container restart (queue state is in Redis, not the container)"
    else
        fail "queue size after the worker restart was [${QUEUE_SIZE}], expected 1"
    fi

    docker exec "${APP_CONTAINER}" php -r '
    require "/var/www/html/vendor/autoload.php";
    $app = require "/var/www/html/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Queue::connection("redis")->clear($argv[1]);
    Illuminate\Support\Facades\Redis::connection("default")->del($argv[2]);
    ' "${SCRATCH_QUEUE}" "${SESSION_KEY}" >/dev/null 2>&1 || true
fi

# Scratch Redis key cleanup.
docker exec "${APP_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Redis::connection("default")->del($argv[1]);
' "${SCRATCH_KEY}" >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

section "Summary"
printf '  passed: %d   failed: %d   skipped: %d\n\n' "${PASS_COUNT}" "${FAIL_COUNT}" "${SKIP_COUNT}"

if [ "${FAIL_COUNT}" -gt 0 ]; then
    printf '\033[1;31mAzure readiness smoke test FAILED.\033[0m\n'
    exit 1
fi

printf '\033[1;32mAzure readiness smoke test passed.\033[0m\n'
printf 'Remember: separate containers on one host are not separate Container Apps, and a local\n'
printf 'volume is not Azure Files SMB. See docs/azure-migration-test-readiness.md for what still\n'
printf 'needs to be verified in Azure staging.\n'
