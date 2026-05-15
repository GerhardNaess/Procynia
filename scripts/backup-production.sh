#!/usr/bin/env bash
# Procynia produksjonsbackup
#
# Bruk:
#   ./scripts/backup-production.sh [backup-katalog]
#
# Standard backup-katalog: /backup/procynia
#
# Scriptet tar:
#   1. PostgreSQL-dump (SQL-tekstformat)
#   2. Komprimert arkiv av storage/app/
#
# .env og secrets lagres IKKE av dette scriptet. Se docs/operations/backup-restore.md.
#
# Krav:
#   - Docker Compose med tjenestene kjørende
#   - Skrivetilgang til backup-katalogen
#   - Skriptet kjøres fra prosjektets rotkatalog

set -euo pipefail

# ---------------------------------------------------------------------------
# Konfigurasjon
# ---------------------------------------------------------------------------

BACKUP_DIR="${1:-/backup/procynia}"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
DB_DUMP_FILE="${BACKUP_DIR}/procynia_db_${TIMESTAMP}.sql"
STORAGE_ARCHIVE="${BACKUP_DIR}/procynia_storage_${TIMESTAMP}.tar.gz"
LOG_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.log"

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

# ---------------------------------------------------------------------------
# Opprett backup-katalog
# ---------------------------------------------------------------------------

mkdir -p "$BACKUP_DIR"
touch "$LOG_FILE"

log "=== Procynia backup starter ==="
log "Backup-katalog: ${BACKUP_DIR}"
log "Tidsstempel: ${TIMESTAMP}"
log "Database: ${POSTGRES_DB} @ postgres (Docker)"

# ---------------------------------------------------------------------------
# 1. PostgreSQL-dump
# ---------------------------------------------------------------------------

log "Tar PostgreSQL-dump ..."

PGPASSWORD="${POSTGRES_PASSWORD}" \
    docker compose exec -T postgres \
    pg_dump -U "${POSTGRES_USER}" "${POSTGRES_DB}" \
    > "$DB_DUMP_FILE"

DB_SIZE="$(du -sh "$DB_DUMP_FILE" 2>/dev/null | cut -f1)"
log "PostgreSQL-dump ferdig: ${DB_DUMP_FILE} (${DB_SIZE})"

# ---------------------------------------------------------------------------
# 2. Storage-arkiv
# ---------------------------------------------------------------------------

log "Pakker storage/app/ ..."

if [ -d "storage/app" ]; then
    tar -czf "$STORAGE_ARCHIVE" -C "storage" "app"
    STORAGE_SIZE="$(du -sh "$STORAGE_ARCHIVE" 2>/dev/null | cut -f1)"
    log "Storage-arkiv ferdig: ${STORAGE_ARCHIVE} (${STORAGE_SIZE})"
else
    log "ADVARSEL: storage/app/ finnes ikke. Storage ikke sikkerhetskopiert."
fi

# ---------------------------------------------------------------------------
# 3. Oppsummering
# ---------------------------------------------------------------------------

log "=== Backup fullført ==="
log "Dumpfil:   ${DB_DUMP_FILE}"
log "Arkivfil:  ${STORAGE_ARCHIVE}"
log "Loggfil:   ${LOG_FILE}"
log ""
log "VIKTIG: .env og secrets er ikke inkludert i backupen."
log "Sørg for at .env er lagret i godkjent secret manager eller sikker serverlokasjon."
log "Flytt backup-filer til eksternt lager. Ikke la dem ligge ubeskyttet på serveren."
