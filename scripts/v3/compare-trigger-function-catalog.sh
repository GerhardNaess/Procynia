#!/usr/bin/env bash
# V3 punkt (3): sammenligner triggere og funksjoner i en database med den lokalt verifiserte
# referansen (docs/testing/v3-trigger-function-catalog.txt).
#
# Bare lesing: spørringen kjøres i en READ ONLY-transaksjon, og PGOPTIONS gjør i tillegg hele
# sesjonen skrivebeskyttet.
#
# Bruk:
#   scripts/v3/compare-trigger-function-catalog.sh "<libpq-tilkobling>"
#   f.eks. "host=<server>.postgres.database.azure.com dbname=procynia user=<bruker> sslmode=require"
#   Uten argument brukes PGHOST/PGDATABASE/PGUSER/... fra miljøet.
#
# Exit 0: identisk. Exit 1: avvik (diff skrives ut). Exit 2: psql feilet.
#
# Krever en PostgreSQL 16-server (klientversjonen spiller ingen rolle) og databasen migrert til samme commit som referansen.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
REFERENCE="$ROOT/docs/testing/v3-trigger-function-catalog.txt"
QUERY="$ROOT/scripts/v3/trigger-function-catalog.sql"

actual="$(mktemp)"
expected="$(mktemp)"
trap 'rm -f "$actual" "$expected"' EXIT

export PGOPTIONS="${PGOPTIONS:-} -c default_transaction_read_only=on"

if ! psql ${1:+"$1"} -X -q -At -v ON_ERROR_STOP=1 -f "$QUERY" > "$actual"; then
    echo "FEIL: psql feilet. Ingenting er sammenlignet." >&2
    exit 2
fi

grep -v '^#' "$REFERENCE" > "$expected"

if diff -u "$expected" "$actual"; then
    echo "OK: $(grep -c '^trigger|' "$actual") triggere og $(grep -c '^function|' "$actual") funksjoner er identiske med referansen."
    exit 0
fi

echo "AVVIK: databasen skiller seg fra referansen (− referanse, + database). Stopp V3 og vurder avviket." >&2
exit 1
