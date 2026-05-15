#!/usr/bin/env bash
# Procynia produksjonsrestore
#
# Bruk:
#   ./scripts/restore-production-backup.sh <db-dump.sql> [storage-arkiv.tar.gz]
#
# Eksempel:
#   ./scripts/restore-production-backup.sh /backup/procynia/procynia_db_20260515_120000.sql
#   ./scripts/restore-production-backup.sh /backup/procynia/procynia_db_20260515_120000.sql \
#       /backup/procynia/procynia_storage_20260515_120000.tar.gz
#
# Scriptet:
#   1. Bekrefter at operatøren vet hva de gjør (interaktivt) — bruk FORCE=1 for å hoppe over
#   2. Stopper app, queue og scheduler
#   3. Restorer PostgreSQL-databasen fra SQL-dump
#   4. Restorer storage/app/ fra arkiv (valgfritt, kun hvis arkiv er angitt)
#   5. Starter tjenestene igjen
#   6. Kjører etterkontroll
#
# .env og secrets restores IKKE av dette scriptet. Se docs/operations/backup-restore.md.
#
# Krav:
#   - Docker Compose med tjenestene kjørende (postgres må være oppe)
#   - Lesetilgang til backup-filene
#   - Skriptet kjøres fra prosjektets rotkatalog

set -euo pipefail

# ---------------------------------------------------------------------------
# Konfigurasjon
# ---------------------------------------------------------------------------

DB_DUMP_FILE="${1:-}"
STORAGE_ARCHIVE="${2:-}"
FORCE="${FORCE:-0}"
LOG_FILE="/tmp/procynia_restore_$(date +%Y%m%d_%H%M%S).log"

# ---------------------------------------------------------------------------
# Hjelpefunksjoner
# ---------------------------------------------------------------------------

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$msg"
    echo "$msg" >> "$LOG_FILE"
}

fail() {
    log "FEIL: $*"
    exit 1
}

# ---------------------------------------------------------------------------
# Forutsetninger
# ---------------------------------------------------------------------------

touch "$LOG_FILE"

log "=== Procynia restore starter ==="

if [ -z "$DB_DUMP_FILE" ]; then
    fail "Ingen DB-dump angitt. Bruk: $0 <db-dump.sql> [storage-arkiv.tar.gz]"
fi

if [ ! -f "$DB_DUMP_FILE" ]; then
    fail "DB-dump ikke funnet: ${DB_DUMP_FILE}"
fi

if [ -n "$STORAGE_ARCHIVE" ] && [ ! -f "$STORAGE_ARCHIVE" ]; then
    fail "Storage-arkiv ikke funnet: ${STORAGE_ARCHIVE}"
fi

command -v docker >/dev/null 2>&1 || fail "docker er ikke installert eller ikke i PATH."

if [ ! -f ".env" ]; then
    fail ".env mangler. Kjør scriptet fra prosjektets rotkatalog."
fi

# Les nødvendige variabler fra .env
# shellcheck source=/dev/null
source <(grep -E '^(POSTGRES_USER|POSTGRES_DB|POSTGRES_PASSWORD)=' .env | sed 's/^/export /')

: "${POSTGRES_USER:?POSTGRES_USER er ikke satt i .env}"
: "${POSTGRES_DB:?POSTGRES_DB er ikke satt i .env}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD er ikke satt i .env}"

DB_DUMP_SIZE="$(du -sh "$DB_DUMP_FILE" 2>/dev/null | cut -f1)"

log "DB-dump:        ${DB_DUMP_FILE} (${DB_DUMP_SIZE})"
if [ -n "$STORAGE_ARCHIVE" ]; then
    STORAGE_ARCHIVE_SIZE="$(du -sh "$STORAGE_ARCHIVE" 2>/dev/null | cut -f1)"
    log "Storage-arkiv:  ${STORAGE_ARCHIVE} (${STORAGE_ARCHIVE_SIZE})"
else
    log "Storage-arkiv:  (ikke angitt — storage restores ikke)"
fi
log "Database:       ${POSTGRES_DB} @ postgres (Docker)"
log "Loggfil:        ${LOG_FILE}"

# ---------------------------------------------------------------------------
# Bekreftelse
# ---------------------------------------------------------------------------

if [ "$FORCE" != "1" ]; then
    echo ""
    echo "ADVARSEL: Dette vil overskrive produksjonsdatabasen '${POSTGRES_DB}'."
    if [ -n "$STORAGE_ARCHIVE" ]; then
        echo "          storage/app/ vil også bli overskrevet."
    fi
    echo ""
    echo "Sørg for at en ny backup er tatt FØR du fortsetter."
    echo ""
    read -r -p "Skriv 'ja' for å fortsette: " BEKREFTELSE
    echo ""

    if [ "$BEKREFTELSE" != "ja" ]; then
        log "Avbrutt av operatør."
        exit 1
    fi
fi

# ---------------------------------------------------------------------------
# 1. Stopp applikasjonstjenester
# ---------------------------------------------------------------------------

log "Stopper app, queue og scheduler ..."

docker compose stop app queue scheduler
log "Tjenester stoppet."

# ---------------------------------------------------------------------------
# 2. PostgreSQL-restore
# ---------------------------------------------------------------------------

log "Restorer PostgreSQL-database ..."

# Avslutter eksisterende tilkoblinger og dropper/gjenskaper databasen
PGPASSWORD="${POSTGRES_PASSWORD}" \
    docker compose exec -T postgres \
    psql -U "${POSTGRES_USER}" -d postgres \
    -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${POSTGRES_DB}' AND pid <> pg_backend_pid();" \
    >> "$LOG_FILE" 2>&1

PGPASSWORD="${POSTGRES_PASSWORD}" \
    docker compose exec -T postgres \
    psql -U "${POSTGRES_USER}" -d postgres \
    -c "DROP DATABASE IF EXISTS \"${POSTGRES_DB}\";" \
    >> "$LOG_FILE" 2>&1

PGPASSWORD="${POSTGRES_PASSWORD}" \
    docker compose exec -T postgres \
    psql -U "${POSTGRES_USER}" -d postgres \
    -c "CREATE DATABASE \"${POSTGRES_DB}\" OWNER \"${POSTGRES_USER}\";" \
    >> "$LOG_FILE" 2>&1

PGPASSWORD="${POSTGRES_PASSWORD}" \
    docker compose exec -T postgres \
    psql -U "${POSTGRES_USER}" "${POSTGRES_DB}" \
    < "$DB_DUMP_FILE" \
    >> "$LOG_FILE" 2>&1

log "PostgreSQL-restore ferdig."

# ---------------------------------------------------------------------------
# 3. Storage-restore (valgfritt)
# ---------------------------------------------------------------------------

if [ -n "$STORAGE_ARCHIVE" ]; then
    log "Restorer storage/app/ ..."

    if [ -d "storage/app" ]; then
        log "Tar backup av eksisterende storage/app/ til /tmp/procynia_storage_pre_restore_$(date +%Y%m%d_%H%M%S).tar.gz"
        STORAGE_PRE_BACKUP="/tmp/procynia_storage_pre_restore_$(date +%Y%m%d_%H%M%S).tar.gz"
        tar -czf "$STORAGE_PRE_BACKUP" -C "storage" "app"
        log "Eksisterende storage/app/ arkivert til ${STORAGE_PRE_BACKUP}"
    fi

    rm -rf storage/app
    mkdir -p storage/app
    tar -xzf "$STORAGE_ARCHIVE" -C "storage"
    log "Storage-restore ferdig."
else
    log "Storage-restore hoppet over (ikke angitt)."
fi

# ---------------------------------------------------------------------------
# 4. Start applikasjonstjenester
# ---------------------------------------------------------------------------

log "Starter app, queue og scheduler ..."

docker compose start app queue scheduler
log "Tjenester startet."

# ---------------------------------------------------------------------------
# 5. Etterkontroll
# ---------------------------------------------------------------------------

log "Venter 10 sekunder på oppstart ..."
sleep 10

log "Kjører docker compose ps ..."
docker compose ps >> "$LOG_FILE" 2>&1
docker compose ps

# ---------------------------------------------------------------------------
# 6. Oppsummering
# ---------------------------------------------------------------------------

log "=== Restore fullført ==="
log "DB-dump restorert fra:  ${DB_DUMP_FILE}"
if [ -n "$STORAGE_ARCHIVE" ]; then
    log "Storage restorert fra:  ${STORAGE_ARCHIVE}"
fi
log "Loggfil:                ${LOG_FILE}"
log ""
log "VIKTIG: Kjør manuell etterkontroll etter restore."
log "Se docs/operations/backup-restore.md for sjekkliste."
