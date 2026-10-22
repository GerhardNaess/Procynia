#!/usr/bin/env bash
# Isolated E2E environment — docs/testing/e2e-environment.md
#
# Usage:
#   scripts/e2e-env.sh up [--no-build]   build assets, create/migrate procynia_e2e, start e2e-app + e2e-web
#   scripts/e2e-env.sh status            show the containers, the database they use and the URL
#   scripts/e2e-env.sh down              stop and remove the E2E containers (data and storage are kept)
#   scripts/e2e-env.sh reset             down, then drop procynia_e2e and .e2e/storage (fresh start)
#
# Runs beside the development stack and never touches it: the development containers, the
# development database and the developer's Vite server are left exactly as they are. Run from the
# repository root. Requires the development stack's postgres container to be running.

set -euo pipefail

cd "$(dirname "$0")/.."

E2E_DB=procynia_e2e
E2E_ROLE=procynia_test_user
E2E_URL=http://127.0.0.1:8091
POSTGRES_CONTAINER=procynia-postgres
COMPOSE=(docker compose --profile e2e)

log() { printf '[e2e-env] %s\n' "$*"; }
fail() { printf '[e2e-env] FEIL: %s\n' "$*" >&2; exit 1; }

# psql as the database superuser, inside the postgres container (its own POSTGRES_USER).
psql_admin() {
    docker exec -i "$POSTGRES_CONTAINER" sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -At'
}

require_postgres() {
    docker inspect -f '{{.State.Running}}' "$POSTGRES_CONTAINER" 2>/dev/null | grep -q true \
        || fail "$POSTGRES_CONTAINER kjører ikke. Start utviklingsmiljøet først (docker compose up -d postgres)."
}

ensure_storage() {
    mkdir -p .e2e/storage/app/public .e2e/storage/app/private \
        .e2e/storage/framework/cache/data .e2e/storage/framework/sessions .e2e/storage/framework/views \
        .e2e/storage/logs
}

ensure_database() {
    if [ "$(echo "SELECT 1 FROM pg_database WHERE datname = '$E2E_DB'" | psql_admin)" != "1" ]; then
        log "oppretter databasen $E2E_DB (eier $E2E_ROLE)"
        echo "CREATE DATABASE $E2E_DB OWNER $E2E_ROLE" | psql_admin >/dev/null
    fi

    # pgvector needs a superuser to create; the migration then finds it in place, as in procynia_test.
    docker exec -i "$POSTGRES_CONTAINER" sh -c "psql -v ON_ERROR_STOP=1 -U \"\$POSTGRES_USER\" -d $E2E_DB -At -c 'CREATE EXTENSION IF NOT EXISTS vector'" >/dev/null

    # The safety the whole environment rests on: the role may not even connect to the development
    # database (DB_DATABASE in .env).
    local dev_db dev_access
    dev_db="$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d '"'"'"' ')"
    [ -n "$dev_db" ] || fail "fant ikke DB_DATABASE i .env."
    [ "$dev_db" != "$E2E_DB" ] || fail "utviklingsdatabasen heter $E2E_DB. Avbryter."
    dev_access="$(echo "SELECT has_database_privilege('$E2E_ROLE', '$dev_db', 'CONNECT')" | psql_admin)"
    if [ "$dev_access" != "f" ]; then
        fail "$E2E_ROLE kan koble til utviklingsdatabasen $dev_db. Avbryter."
    fi
}

wait_for_http() {
    for _ in $(seq 1 60); do
        if curl -fsS -o /dev/null "$E2E_URL/login"; then
            return 0
        fi
        sleep 1
    done
    fail "$E2E_URL/login svarte ikke innen 60 sekunder (se: docker logs procynia-e2e-app)."
}

cmd_up() {
    local build=1
    [ "${1:-}" = "--no-build" ] && build=0

    require_postgres
    ensure_storage
    ensure_database

    if [ "$build" = 1 ]; then
        log "bygger frontend (npm run build)"
        npm run build >/dev/null
    fi
    [ -f public/build/manifest.json ] || fail "public/build/manifest.json mangler. Kjør npm run build."

    log "starter e2e-app og e2e-web (utviklingscontainerne berøres ikke)"
    "${COMPOSE[@]}" up -d --no-deps e2e-app e2e-web >/dev/null

    log "migrerer $E2E_DB"
    "${COMPOSE[@]}" exec -T e2e-app php artisan migrate --force >/dev/null

    wait_for_http
    cmd_status
}

cmd_status() {
    "${COMPOSE[@]}" ps e2e-app e2e-web
    if docker inspect -f '{{.State.Running}}' procynia-e2e-app 2>/dev/null | grep -q true; then
        printf '[e2e-env] database: '
        "${COMPOSE[@]}" exec -T e2e-app php artisan tinker --execute="echo DB::connection()->getDatabaseName().' som '.config('database.connections.pgsql.username').', APP_ENV='.app()->environment();"
        printf '\n[e2e-env] url: %s\n' "$E2E_URL"
    fi
}

cmd_down() {
    log "stopper og fjerner e2e-web og e2e-app (database og .e2e/storage beholdes)"
    "${COMPOSE[@]}" rm -sf e2e-web e2e-app >/dev/null
}

cmd_reset() {
    cmd_down
    require_postgres
    log "sletter databasen $E2E_DB og .e2e/storage"
    echo "DROP DATABASE IF EXISTS $E2E_DB" | psql_admin >/dev/null
    rm -rf .e2e/storage
}

case "${1:-}" in
    up) shift; cmd_up "$@" ;;
    status) cmd_status ;;
    down) cmd_down ;;
    reset) cmd_reset ;;
    *) sed -n '2,12p' "$0"; exit 1 ;;
esac
