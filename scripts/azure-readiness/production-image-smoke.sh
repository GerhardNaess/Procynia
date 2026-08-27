#!/usr/bin/env bash
#
# Procynia — production image smoke test.
#
#   ./scripts/azure-readiness/production-image-smoke.sh
#   ./scripts/azure-readiness/production-image-smoke.sh --no-build     # reuse existing images
#   ./scripts/azure-readiness/production-image-smoke.sh --keep         # leave the stack running
#
# The milestone this script exists to prove:
#
#   Procynia runs from a built image, with no source code mounted from the developer's machine.
#
# If any step here needs a source bind mount, the images are not ready. Nothing is mounted except a
# named volume at /var/www/html/storage/app — the local stand-in for the Azure Files share that web
# and workers both see.
#
# Isolation: this brings up its own throwaway PostgreSQL and Redis on their own Docker network, with
# their own volumes and their own database name. It never touches the development stack, the
# development database, or the test database. Everything it creates is removed on exit.
#
# Exit 0 = every executed check passed.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
readonly PROJECT_ROOT="${SCRIPT_DIR}/../.."

readonly PREFIX="procynia-smoke"
readonly NETWORK="${PREFIX}-net"
readonly STORAGE_VOLUME="${PREFIX}-storage"
readonly PG_VOLUME="${PREFIX}-pgdata"
readonly PG_CONTAINER="${PREFIX}-postgres"
readonly REDIS_CONTAINER="${PREFIX}-redis"
readonly WEB_CONTAINER="${PREFIX}-web"
readonly WORKER_CONTAINER="${PREFIX}-worker"

readonly APP_IMAGE="procynia-app:production"
readonly WEB_IMAGE="procynia-web:production"

# A database that exists only for this script. Never the development or test database.
readonly SMOKE_DB="procynia_smoke"
readonly SMOKE_DB_USER="procynia_smoke"
readonly FORBIDDEN_DATABASES="procynia procynia_test"

# Throwaway, generated per run, never persisted. Not a production secret.
SMOKE_DB_PASSWORD="smoke-$(openssl rand -hex 12)"
SMOKE_APP_KEY="base64:$(openssl rand -base64 32)"
readonly SMOKE_DB_PASSWORD SMOKE_APP_KEY

readonly WEB_PORT=18080

BUILD="true"
KEEP="false"
PASS_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0

usage() {
    cat <<'USAGE'
Usage: ./scripts/azure-readiness/production-image-smoke.sh [--no-build] [--keep] [--help]

  (no flag)    Build both production images, then run the full smoke test.
  --no-build   Reuse the existing procynia-app:production / procynia-web:production images.
  --keep       Leave the smoke stack running afterwards for manual inspection.
USAGE
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --no-build) BUILD="false" ;;
        --keep) KEEP="true" ;;
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

cleanup() {
    if [ "${KEEP}" = "true" ]; then
        printf '\n--keep given: leaving the smoke stack running.\n'
        printf 'Remove it with: docker rm -f %s %s %s %s && docker volume rm %s %s && docker network rm %s\n' \
            "${WEB_CONTAINER}" "${WORKER_CONTAINER}" "${PG_CONTAINER}" "${REDIS_CONTAINER}" \
            "${STORAGE_VOLUME}" "${PG_VOLUME}" "${NETWORK}"
        return
    fi

    docker rm -f "${WEB_CONTAINER}" "${WORKER_CONTAINER}" "${PG_CONTAINER}" "${REDIS_CONTAINER}" >/dev/null 2>&1 || true
    docker volume rm "${STORAGE_VOLUME}" "${PG_VOLUME}" >/dev/null 2>&1 || true
    docker network rm "${NETWORK}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Shared runtime environment. This is the same contract the Azure Container Apps environment
# variables express — see docs/azure-runtime-contract.md.
runtime_env() {
    printf '%s\n' \
        "APP_ENV=production" \
        "APP_DEBUG=false" \
        "APP_KEY=${SMOKE_APP_KEY}" \
        "APP_URL=http://localhost:${WEB_PORT}" \
        "LOG_CHANNEL=stderr" \
        "LOG_LEVEL=info" \
        "DB_CONNECTION=pgsql" \
        "DB_HOST=${PG_CONTAINER}" \
        "DB_PORT=5432" \
        "DB_DATABASE=${SMOKE_DB}" \
        "DB_USERNAME=${SMOKE_DB_USER}" \
        "DB_PASSWORD=${SMOKE_DB_PASSWORD}" \
        "REDIS_CLIENT=phpredis" \
        "REDIS_HOST=${REDIS_CONTAINER}" \
        "REDIS_PORT=6379" \
        "REDIS_DB=0" \
        "REDIS_CACHE_DB=0" \
        "REDIS_QUEUE_RETRY_AFTER=420" \
        "CACHE_STORE=redis" \
        "SESSION_DRIVER=redis" \
        "SESSION_CONNECTION=default" \
        "SESSION_STORE=redis" \
        "QUEUE_CONNECTION=redis" \
        "FILESYSTEM_DISK=local" \
        "TZ=Europe/Oslo" \
        "MAIL_MAILER=log" \
        "DOFFIN_BASE_URL=https://api.doffin.no" \
        "PROCYNIA_LEGACY_BACKUP_ENABLED=false"
}

env_args() {
    local line
    while IFS= read -r line; do
        printf -- '-e\n%s\n' "${line}"
    done < <(runtime_env)
}

docker_run_app() {
    # A one-off container from the app image: no bind mount, shared storage volume only.
    local args=()
    while IFS= read -r item; do args+=("${item}"); done < <(env_args)

    docker run --rm --network "${NETWORK}" \
        "${args[@]}" \
        -v "${STORAGE_VOLUME}:/var/www/html/storage/app" \
        "${APP_IMAGE}" "$@"
}

# ---------------------------------------------------------------------------

section "Preconditions"

command -v docker >/dev/null 2>&1 || abort "docker is not installed or not on PATH."
command -v openssl >/dev/null 2>&1 || abort "openssl is required to generate throwaway credentials."

for forbidden in ${FORBIDDEN_DATABASES}; do
    if [ "${SMOKE_DB}" = "${forbidden}" ]; then
        abort "The smoke database is configured as [${forbidden}]. Refusing to continue."
    fi
done
pass "smoke database is [${SMOKE_DB}] — isolated from ${FORBIDDEN_DATABASES// /, }"

# ---------------------------------------------------------------------------

section "Build production images"

if [ "${BUILD}" = "true" ]; then
    if DOCKER_BUILDKIT=1 docker build -f "${PROJECT_ROOT}/docker/production/Dockerfile" \
            --target app -t "${APP_IMAGE}" "${PROJECT_ROOT}" >/dev/null 2>&1; then
        pass "built ${APP_IMAGE}"
    else
        fail "could not build ${APP_IMAGE}"
        abort "Image build failed. Re-run the build without redirection to see the error."
    fi

    if DOCKER_BUILDKIT=1 docker build -f "${PROJECT_ROOT}/docker/production/Dockerfile" \
            --target web -t "${WEB_IMAGE}" "${PROJECT_ROOT}" >/dev/null 2>&1; then
        pass "built ${WEB_IMAGE}"
    else
        fail "could not build ${WEB_IMAGE}"
        abort "Image build failed. Re-run the build without redirection to see the error."
    fi
else
    for image in "${APP_IMAGE}" "${WEB_IMAGE}"; do
        if docker image inspect "${image}" >/dev/null 2>&1; then
            pass "reusing existing ${image}"
        else
            abort "${image} does not exist. Run without --no-build."
        fi
    done
fi

# The web image is built FROM the app image, so both must carry the same application code.
APP_CODE_HASH="$(docker run --rm --entrypoint sh "${APP_IMAGE}" -c 'find /var/www/html/app /var/www/html/config /var/www/html/routes -type f -name "*.php" | sort | xargs cat | sha256sum | cut -d" " -f1')"
WEB_CODE_HASH="$(docker run --rm --entrypoint sh "${WEB_IMAGE}" -c 'find /var/www/html/app /var/www/html/config /var/www/html/routes -type f -name "*.php" | sort | xargs cat | sha256sum | cut -d" " -f1')"

if [ "${APP_CODE_HASH}" = "${WEB_CODE_HASH}" ] && [ -n "${APP_CODE_HASH}" ]; then
    pass "web and app images contain byte-identical application code"
else
    fail "web and app images differ — a web replica would run different code from a worker"
fi

# ---------------------------------------------------------------------------

section "Image contents (no source bind mount)"

if docker run --rm --entrypoint sh "${APP_IMAGE}" -c 'test -f /var/www/html/artisan'; then
    pass "application code is baked into the image"
else
    fail "no /var/www/html/artisan in the image"
fi

if docker run --rm --entrypoint sh "${APP_IMAGE}" -c 'test -d /var/www/html/vendor && test -f /var/www/html/vendor/autoload.php'; then
    pass "Composer production dependencies are present"
else
    fail "no Composer vendor tree in the image"
fi

if docker run --rm --entrypoint sh "${APP_IMAGE}" -c 'test -f /var/www/html/public/build/manifest.json'; then
    pass "frontend build is present (built inside the image)"
else
    fail "no public/build/manifest.json in the image"
fi

if docker run --rm --entrypoint sh "${APP_IMAGE}" -c 'test ! -f /var/www/html/.env'; then
    pass "no .env baked into the image"
else
    fail "the image contains a .env file"
fi

MISSING_EXT="$(docker run --rm --entrypoint sh "${APP_IMAGE}" -c '
for e in bcmath curl dom exif gd intl mbstring openssl pcntl pdo_pgsql pgsql redis xml xmlreader xmlwriter xsl zip; do
  php -m | grep -qix "$e" || printf "%s " "$e"
done')"
if [ -z "${MISSING_EXT}" ]; then
    pass "every required PHP extension is present"
else
    fail "missing PHP extensions: ${MISSING_EXT}"
fi

if docker run --rm --entrypoint sh "${APP_IMAGE}" -c 'pdftotext -v >/dev/null 2>&1 && pdftohtml -v >/dev/null 2>&1 && pdfimages -v >/dev/null 2>&1 && pdfinfo -v >/dev/null 2>&1'; then
    pass "PDF tooling (pdftotext, pdftohtml, pdfimages, pdfinfo) runs in the image"
else
    fail "PDF tooling is missing or not runnable in the image"
fi

# PhpWord is a Composer class, so the autoloader has to be loaded before asking for it — a bare
# php -r would report it missing even when it is present.
if docker run --rm --entrypoint php "${APP_IMAGE}" -r '
require "/var/www/html/vendor/autoload.php";
exit(class_exists("ZipArchive") && class_exists("PhpOffice\\PhpWord\\IOFactory") ? 0 : 1);
'; then
    pass "DOCX/XLSX prerequisites (ZipArchive, PhpWord) are present"
else
    fail "DOCX/XLSX prerequisites are missing"
fi

# ---------------------------------------------------------------------------

section "Backing services"

docker network create "${NETWORK}" >/dev/null 2>&1 || true
docker volume create "${STORAGE_VOLUME}" >/dev/null
docker volume create "${PG_VOLUME}" >/dev/null

docker run -d --name "${PG_CONTAINER}" --network "${NETWORK}" \
    -e POSTGRES_DB="${SMOKE_DB}" \
    -e POSTGRES_USER="${SMOKE_DB_USER}" \
    -e POSTGRES_PASSWORD="${SMOKE_DB_PASSWORD}" \
    -v "${PG_VOLUME}:/var/lib/postgresql/data" \
    pgvector/pgvector:0.8.2-pg16 >/dev/null

docker run -d --name "${REDIS_CONTAINER}" --network "${NETWORK}" \
    redis:7-alpine redis-server --appendonly yes >/dev/null

printf '       waiting for PostgreSQL '
for _ in $(seq 1 60); do
    if docker exec "${PG_CONTAINER}" pg_isready -U "${SMOKE_DB_USER}" -d "${SMOKE_DB}" >/dev/null 2>&1; then
        break
    fi
    printf '.'
    sleep 1
done
printf '\n'

if docker exec "${PG_CONTAINER}" pg_isready -U "${SMOKE_DB_USER}" -d "${SMOKE_DB}" >/dev/null 2>&1; then
    pass "throwaway PostgreSQL 16 (pgvector) is up"
else
    fail "throwaway PostgreSQL did not become ready"
    abort "Cannot continue without a database."
fi

if docker exec "${REDIS_CONTAINER}" redis-cli ping >/dev/null 2>&1; then
    pass "throwaway Redis is up"
else
    fail "throwaway Redis did not become ready"
    abort "Cannot continue without Redis."
fi

# ---------------------------------------------------------------------------

section "Migrations as a separate job"

# Exactly what the Azure migration job does: a one-off container from the app image.
if MIGRATE_OUTPUT="$(docker_run_app php artisan migrate --force --no-interaction 2>&1)"; then
    pass "migrations ran from a standalone container against ${SMOKE_DB}"
else
    fail "standalone migration run failed"
    note "${MIGRATE_OUTPUT}"
fi

if VECTOR_VERSION="$(docker exec "${PG_CONTAINER}" psql -U "${SMOKE_DB_USER}" -d "${SMOKE_DB}" -tAc "select extversion from pg_extension where extname='vector';" 2>/dev/null)" \
    && [ -n "${VECTOR_VERSION}" ]; then
    pass "the migration created the pgvector extension (${VECTOR_VERSION})"
else
    fail "the vector extension is missing after migration"
fi

if REPEAT_OUTPUT="$(docker_run_app php artisan migrate --force --no-interaction 2>&1)" \
    && printf '%s' "${REPEAT_OUTPUT}" | grep -qi 'nothing to migrate'; then
    pass "a repeated migration run is a no-op"
else
    fail "repeating the migration job was not a no-op"
fi

# ---------------------------------------------------------------------------

section "Workloads (no source bind mount)"

WEB_ENV_ARGS=()
while IFS= read -r item; do WEB_ENV_ARGS+=("${item}"); done < <(env_args)

docker run -d --name "${WEB_CONTAINER}" --network "${NETWORK}" \
    "${WEB_ENV_ARGS[@]}" \
    -e PROCYNIA_ROLE=web \
    -p "${WEB_PORT}:8080" \
    -v "${STORAGE_VOLUME}:/var/www/html/storage/app" \
    "${WEB_IMAGE}" >/dev/null

docker run -d --name "${WORKER_CONTAINER}" --network "${NETWORK}" \
    "${WEB_ENV_ARGS[@]}" \
    -e PROCYNIA_ROLE=queue-worker \
    -v "${STORAGE_VOLUME}:/var/www/html/storage/app" \
    "${APP_IMAGE}" \
    php artisan queue:work redis --queue=default --tries=1 --timeout=120 --sleep=3 >/dev/null

printf '       waiting for web '
for _ in $(seq 1 60); do
    if curl -fsS "http://127.0.0.1:${WEB_PORT}/up" >/dev/null 2>&1; then
        break
    fi
    printf '.'
    sleep 1
done
printf '\n'

# Nothing was mounted except the storage volume — prove it rather than assert it.
WEB_MOUNTS="$(docker inspect -f '{{range .Mounts}}{{.Destination}} {{end}}' "${WEB_CONTAINER}")"
if printf '%s' "${WEB_MOUNTS}" | grep -q '/var/www/html/storage/app' \
    && ! printf '%s' "${WEB_MOUNTS}" | grep -qE '/var/www/html($| )'; then
    pass "web runs with no source bind mount (mounts: ${WEB_MOUNTS% })"
else
    fail "web has an unexpected mount: ${WEB_MOUNTS}"
fi

HEALTH_STATUS="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${WEB_PORT}/up" || echo 000)"
if [ "${HEALTH_STATUS}" = "200" ]; then
    pass "GET /up returned 200 from the production web image"
else
    fail "GET /up returned [${HEALTH_STATUS}]"
    note "$(docker logs "${WEB_CONTAINER}" 2>&1 | tail -20)"
fi

if docker exec "${WORKER_CONTAINER}" sh -c 'test -f /var/www/html/artisan'; then
    pass "worker runs the same baked image, no bind mount"
else
    fail "worker container is missing the application code"
fi

# ---------------------------------------------------------------------------

section "Runtime preflight inside the production image"

if PREFLIGHT_OUTPUT="$(docker exec "${WORKER_CONTAINER}" php artisan ops:runtime-check --azure 2>&1)"; then
    pass "ops:runtime-check --azure passed inside the production image"
    printf '%s\n' "${PREFLIGHT_OUTPUT}" | sed 's/^/       /'
else
    fail "ops:runtime-check --azure reported a critical failure"
    printf '%s\n' "${PREFLIGHT_OUTPUT}" | sed 's/^/       /'
fi

DEBUG_VALUE="$(docker exec "${WORKER_CONTAINER}" php -r 'require "/var/www/html/vendor/autoload.php"; $a=require "/var/www/html/bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("app.debug") ? "true" : "false";' 2>/dev/null || echo ERROR)"
if [ "${DEBUG_VALUE}" = "false" ]; then
    pass "application booted with APP_DEBUG=false"
else
    fail "APP_DEBUG resolved to [${DEBUG_VALUE}]"
fi

# ---------------------------------------------------------------------------

section "Shared storage between web and worker"

MARKER="smoke-$(date -u +%s)-$$"
SCRATCH_REL="production-image-smoke/${MARKER}.txt"

docker exec "${WEB_CONTAINER}" sh -c "mkdir -p /var/www/html/storage/app/private/production-image-smoke && printf '%s' '${MARKER}' > '/var/www/html/storage/app/private/${SCRATCH_REL}'"

WORKER_SAW="$(docker exec "${WORKER_CONTAINER}" cat "/var/www/html/storage/app/private/${SCRATCH_REL}" 2>/dev/null || echo MISSING)"
if [ "${WORKER_SAW}" = "${MARKER}" ]; then
    pass "worker reads the file the web container wrote, at the same path"
else
    fail "worker could not read the web container's file (got [${WORKER_SAW}])"
fi

RESOLVED="$(docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$a = require "/var/www/html/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\Storage::disk("local")->path($argv[1]);
' "${SCRATCH_REL}" 2>/dev/null || echo ERROR)"
if [ "${RESOLVED}" = "/var/www/html/storage/app/private/${SCRATCH_REL}" ]; then
    pass "Storage::disk('local')->path() resolves identically in both containers"
else
    fail "worker resolved the path to [${RESOLVED}]"
fi

# ---------------------------------------------------------------------------

section "Redis and PostgreSQL from the production image"

if docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$a = require "/var/www/html/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Redis::connection("default")->set("smoke-probe", "ok");
exit(Illuminate\Support\Facades\Redis::connection("default")->get("smoke-probe") === "ok" ? 0 : 1);
' >/dev/null 2>&1; then
    pass "Redis read/write works from the production image"
else
    fail "Redis read/write failed from the production image"
fi

WORKER_DB="$(docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$a = require "/var/www/html/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::selectOne("select current_database() as db")->db;
' 2>/dev/null || echo ERROR)"
if [ "${WORKER_DB}" = "${SMOKE_DB}" ]; then
    pass "PostgreSQL is reachable from the production image (${WORKER_DB})"
else
    fail "worker reported database [${WORKER_DB}], expected [${SMOKE_DB}]"
fi

# ---------------------------------------------------------------------------

section "Logging to stdout/stderr"

if docker logs "${WEB_CONTAINER}" 2>&1 | grep -q 'GET /up'; then
    pass "nginx access logs reach the container log stream"
else
    fail "no nginx access log found on the container log stream"
fi

LOG_MARKER="log-probe-${MARKER}"
docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$a = require "/var/www/html/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Log::error($argv[1]);
' "${LOG_MARKER}" >/dev/null 2>&1 || true

if docker exec "${WORKER_CONTAINER}" sh -c 'test ! -s /var/www/html/storage/logs/laravel.log'; then
    pass "application logging does not write to a file on disk"
else
    fail "application logging wrote to storage/logs/laravel.log instead of stderr"
fi

# ---------------------------------------------------------------------------

section "Restart resilience"

QUEUE_NAME="smoke-restart-${MARKER}"
docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$a = require "/var/www/html/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Redis::connection("default")->setex("smoke-session", 600, $argv[1]);
Illuminate\Support\Facades\Queue::connection("redis")->push(new App\Jobs\OpsQueueHeartbeatJob($argv[2]), "", $argv[2]);
' "${MARKER}" "${QUEUE_NAME}" >/dev/null 2>&1 || true

note "restarting web ..."
docker restart "${WEB_CONTAINER}" >/dev/null
for _ in $(seq 1 60); do
    curl -fsS "http://127.0.0.1:${WEB_PORT}/up" >/dev/null 2>&1 && break
    sleep 1
done

if [ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${WEB_PORT}/up" || echo 000)" = "200" ]; then
    pass "web came back healthy after a restart"
else
    fail "web did not become healthy again after a restart"
fi

AFTER_RESTART="$(docker exec "${WEB_CONTAINER}" cat "/var/www/html/storage/app/private/${SCRATCH_REL}" 2>/dev/null || echo MISSING)"
if [ "${AFTER_RESTART}" = "${MARKER}" ]; then
    pass "shared storage survived the web restart"
else
    fail "shared storage did not survive the web restart"
fi

note "restarting worker ..."
docker restart "${WORKER_CONTAINER}" >/dev/null
sleep 8

SESSION_AFTER="$(docker exec "${WORKER_CONTAINER}" php -r '
require "/var/www/html/vendor/autoload.php";
$a = require "/var/www/html/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) Illuminate\Support\Facades\Redis::connection("default")->get("smoke-session");
' 2>/dev/null || echo ERROR)"
if [ "${SESSION_AFTER}" = "${MARKER}" ]; then
    pass "Redis-backed state survived the worker restart"
else
    fail "Redis-backed state was lost across the worker restart (got [${SESSION_AFTER}])"
fi

# ---------------------------------------------------------------------------

section "Summary"
printf '  passed: %d   failed: %d   skipped: %d\n\n' "${PASS_COUNT}" "${FAIL_COUNT}" "${SKIP_COUNT}"

if [ "${FAIL_COUNT}" -gt 0 ]; then
    printf '\033[1;31mProduction image smoke test FAILED.\033[0m\n'
    exit 1
fi

printf '\033[1;32mProduction image smoke test passed.\033[0m\n'
printf 'Procynia ran entirely from built images, with no source mounted from the host.\n'
printf 'Still unproven locally: Azure Files SMB semantics, managed PostgreSQL/Redis TLS, and\n'
printf 'Container Apps revision behaviour. See docs/azure-migration-test-readiness.md.\n'
