#!/usr/bin/env bash
# Starter Procynia lokalt etter strømbrudd, maskinrestart eller Docker-restart.
#
# Appen serveres via Docker/Nginx på port 8080.
# php artisan serve skal ikke kjøres.
#
# Viktig:
# - Eksisterende containere blir ikke gjenskapt.
# - Aktive queue-workere blir aldri restartet automatisk.
# - En worker-container restartes bare dersom containeren kjører,
#   men det ikke finnes noen aktiv queue-worker-prosess.
# - Vite kjører i denne terminalen og stoppes med Ctrl+C.

set -Eeuo pipefail

PROJECT_DIR="/Applications/XAMPP/xamppfiles/htdocs/procynia"
STARTUP_TIMEOUT_SECONDS=90
WORKER_TIMEOUT_SECONDS=30
POLL_INTERVAL_SECONDS=2

CORE_SERVICES=(
  postgres
  redis
  app
  web
  scheduler
)

WORKER_SERVICES=(
  queue
  queue-ai-requirements
  queue-enterprise-wiki
  queue-enterprise-wiki-pages
)

ALL_SERVICES=(
  "${CORE_SERVICES[@]}"
  "${WORKER_SERVICES[@]}"
)

SPECIAL_WORKER_SERVICES=(
  queue-ai-requirements
  queue-enterprise-wiki
  queue-enterprise-wiki-pages
)

SPECIAL_QUEUE_NAMES=(
  ai-requirements
  enterprise-wiki
  enterprise-wiki-pages
)

COMPOSE_SERVICES=""
VITE_PID=""

fail() {
  echo ""
  echo "FEIL: $1"
  exit 1
}

cleanup() {
  if [ -n "${VITE_PID:-}" ] &&
    kill -0 "$VITE_PID" 2>/dev/null; then
    echo ""
    echo "Stopper Vite..."

    kill "$VITE_PID" 2>/dev/null || true
    wait "$VITE_PID" 2>/dev/null || true
  fi
}

handle_signal() {
  exit 130
}

trap cleanup EXIT
trap handle_signal INT TERM

require_command() {
  local command_name="$1"

  if ! command -v "$command_name" >/dev/null 2>&1; then
    fail "Påkrevd kommando finnes ikke: $command_name"
  fi
}

load_compose_services() {
  if ! COMPOSE_SERVICES="$(
    docker compose config --services 2>/dev/null
  )"; then
    fail "Kunne ikke lese Docker Compose-konfigurasjonen."
  fi

  if [ -z "$COMPOSE_SERVICES" ]; then
    fail "Docker Compose-konfigurasjonen inneholder ingen tjenester."
  fi
}

service_exists() {
  local requested_service="$1"
  local configured_service

  while IFS= read -r configured_service; do
    if [ "$configured_service" = "$requested_service" ]; then
      return 0
    fi
  done <<<"$COMPOSE_SERVICES"

  return 1
}

container_id_for_service() {
  local service="$1"
  local container_ids

  container_ids="$(
    docker compose ps -q "$service" 2>/dev/null || true
  )"

  if [[ "$container_ids" == *$'\n'* ]]; then
    container_ids="${container_ids%%$'\n'*}"
  fi

  printf '%s' "$container_ids"
}

service_is_ready() {
  local service="$1"
  local container_id
  local running
  local health

  container_id="$(container_id_for_service "$service")"

  if [ -z "$container_id" ]; then
    return 1
  fi

  running="$(
    docker inspect \
      --format '{{.State.Running}}' \
      "$container_id" 2>/dev/null || true
  )"

  if [ "$running" != "true" ]; then
    return 1
  fi

  health="$(
    docker inspect \
      --format \
      '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
      "$container_id" 2>/dev/null || true
  )"

  case "$health" in
    none|healthy)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

show_service_failure() {
  local service="$1"
  local message="$2"

  echo ""
  echo "FEIL: $message"
  echo "Tjeneste: $service"

  echo ""
  echo "Docker-status:"
  docker compose ps "$service" || true

  echo ""
  echo "Siste logger fra $service:"
  docker compose logs --tail=100 "$service" || true
}

wait_for_service() {
  local service="$1"
  local deadline

  deadline=$((SECONDS + STARTUP_TIMEOUT_SECONDS))

  while [ "$SECONDS" -lt "$deadline" ]; do
    if service_is_ready "$service"; then
      echo "  OK: $service"
      return 0
    fi

    sleep "$POLL_INTERVAL_SECONDS"
  done

  show_service_failure \
    "$service" \
    "Tjenesten ble ikke running/healthy innen ${STARTUP_TIMEOUT_SECONDS} sekunder."

  return 1
}

worker_process_output() {
  local service="$1"

  docker compose top "$service" 2>/dev/null || true
}

worker_process_is_running() {
  local service="$1"
  local process_output

  process_output="$(worker_process_output "$service")"

  grep -Eq \
    'queue:work|artisan[[:space:]]+horizon' \
    <<<"$process_output"
}

wait_for_worker_process() {
  local service="$1"
  local deadline

  deadline=$((SECONDS + WORKER_TIMEOUT_SECONDS))

  while [ "$SECONDS" -lt "$deadline" ]; do
    if worker_process_is_running "$service"; then
      return 0
    fi

    sleep "$POLL_INTERVAL_SECONDS"
  done

  return 1
}

verify_or_recover_worker() {
  local service="$1"

  if worker_process_is_running "$service"; then
    echo "  OK: $service har en aktiv queue-worker."
    return 0
  fi

  echo "  ADVARSEL: Fant ingen aktiv queue-worker i $service."
  echo "  Venter kort for å utelukke at worker-prosessen fortsatt starter..."

  if wait_for_worker_process "$service"; then
    echo "  OK: $service har nå en aktiv queue-worker."
    return 0
  fi

  # Det finnes ingen worker-prosess. Dermed finnes det heller ingen aktiv
  # queue-jobb i denne containeren som kan bli avbrutt av en restart.
  if service_is_ready "$service"; then
    echo "  Restarter $service fordi containeren kjører uten worker-prosess..."

    docker compose restart "$service"
  else
    echo "  Starter manglende eller stoppet tjeneste: $service"

    docker compose up -d --no-recreate "$service"
  fi

  if ! wait_for_service "$service"; then
    return 1
  fi

  if ! wait_for_worker_process "$service"; then
    show_service_failure \
      "$service" \
      "Containeren kjører, men ingen queue-worker startet innen ${WORKER_TIMEOUT_SECONDS} sekunder."

    echo ""
    echo "Prosesser i containeren:"
    docker compose top "$service" || true

    return 1
  fi

  echo "  OK: $service har nå en aktiv queue-worker."
}

verify_worker_queue_name() {
  local service="$1"
  local expected_queue="$2"
  local process_output
  local container_id
  local configured_command

  process_output="$(worker_process_output "$service")"
  container_id="$(container_id_for_service "$service")"
  configured_command=""

  if [ -n "$container_id" ]; then
    configured_command="$(
      docker inspect \
        --format '{{json .Config.Entrypoint}} {{json .Config.Cmd}}' \
        "$container_id" 2>/dev/null || true
    )"
  fi

  if grep -Fq "$expected_queue" <<<"$process_output"; then
    echo "  OK: $service lytter på $expected_queue."
    return 0
  fi

  if grep -Fq "$expected_queue" <<<"$configured_command"; then
    echo "  OK: $service er konfigurert for $expected_queue."
    return 0
  fi

  show_service_failure \
    "$service" \
    "Worker-prosessen ser ikke ut til å lytte på køen '$expected_queue'."

  echo ""
  echo "Prosesser i containeren:"
  printf '%s\n' "$process_output"

  echo ""
  echo "Konfigurert containerkommando:"
  printf '%s\n' "$configured_command"

  return 1
}

show_restart_policy_warning() {
  local service="$1"
  local container_id
  local restart_policy

  container_id="$(container_id_for_service "$service")"

  if [ -z "$container_id" ]; then
    return 0
  fi

  restart_policy="$(
    docker inspect \
      --format '{{.HostConfig.RestartPolicy.Name}}' \
      "$container_id" 2>/dev/null || true
  )"

  case "$restart_policy" in
    always|unless-stopped)
      echo "  OK: $service har restart-policy '$restart_policy'."
      ;;
    *)
      echo \
        "  ADVARSEL: $service har restart-policy '${restart_policy:-ingen}'."
      echo \
        "             Compose-tjenesten bør bruke restart: unless-stopped."
      ;;
  esac
}

echo "Kontrollerer lokalt miljø..."

require_command docker
require_command npm

if [ ! -d "$PROJECT_DIR" ]; then
  fail "Prosjektmappen finnes ikke: $PROJECT_DIR"
fi

cd "$PROJECT_DIR"

if ! docker info >/dev/null 2>&1; then
  fail "Docker kjører ikke. Start Docker Desktop og kjør skriptet på nytt."
fi

if ! docker compose version >/dev/null 2>&1; then
  fail "Docker Compose er ikke tilgjengelig."
fi

if ! docker compose config --quiet; then
  fail "Docker Compose-konfigurasjonen er ugyldig."
fi

load_compose_services

echo ""
echo "Kontrollerer Docker Compose-tjenester..."

for service in "${ALL_SERVICES[@]}"; do
  if ! service_exists "$service"; then
    echo ""
    echo "FEIL: Påkrevd Docker Compose-tjeneste finnes ikke: $service"

    echo ""
    echo "Tilgjengelige tjenester:"
    printf '%s\n' "$COMPOSE_SERVICES"

    exit 1
  fi
done

echo "  OK: Alle påkrevde tjenester finnes."

echo ""
echo "Starter manglende tjenester uten å gjenskape eksisterende containere..."

# --no-recreate er viktig:
# En eksisterende worker kan være midt i en langvarig jobb og skal ikke
# avbrytes bare fordi oppstartsskriptet kjøres på nytt.
docker compose up -d --no-recreate "${ALL_SERVICES[@]}"

echo ""
echo "Venter på kjernetjenestene..."

for service in "${CORE_SERVICES[@]}"; do
  if ! wait_for_service "$service"; then
    exit 1
  fi
done

echo ""
echo "Venter på worker-containerne..."

for service in "${WORKER_SERVICES[@]}"; do
  if ! wait_for_service "$service"; then
    exit 1
  fi
done

echo ""
echo "Kontrollerer queue-workerne uten å avbryte aktive jobber..."

for service in "${WORKER_SERVICES[@]}"; do
  if ! verify_or_recover_worker "$service"; then
    exit 1
  fi
done

echo ""
echo "Kontrollerer spesialkøene..."

special_worker_count="${#SPECIAL_WORKER_SERVICES[@]}"
index=0

while [ "$index" -lt "$special_worker_count" ]; do
  service="${SPECIAL_WORKER_SERVICES[$index]}"
  expected_queue="${SPECIAL_QUEUE_NAMES[$index]}"

  if ! verify_worker_queue_name "$service" "$expected_queue"; then
    exit 1
  fi

  index=$((index + 1))
done

echo ""
echo "Kontrollerer restart-policy for workerne..."

for service in "${WORKER_SERVICES[@]}"; do
  show_restart_policy_warning "$service"
done

echo ""
echo "Docker-status:"
docker compose ps

# Fjern eventuell gammel Vite hot-fil slik at Docker bruker bygde assets
# inntil Vite-serveren er oppe.
if [ -f public/hot ]; then
  echo ""
  echo "Fjerner gammel public/hot..."
  rm -f public/hot
fi

echo ""
echo "Starter Vite..."

npm run dev &
VITE_PID=$!

sleep 2

if ! kill -0 "$VITE_PID" 2>/dev/null; then
  echo ""
  echo "FEIL: Vite stoppet umiddelbart etter oppstart."

  wait "$VITE_PID" || true
  exit 1
fi

echo ""
echo "Procynia kjører:"
echo "  App:  http://127.0.0.1:8080"
echo "  Vite: aktiv i denne terminalen"

echo ""
echo "Verifiserte queue-workers:"
echo "  queue"
echo "  queue-ai-requirements -> ai-requirements"
echo "  queue-enterprise-wiki -> enterprise-wiki"
echo "  queue-enterprise-wiki-pages -> enterprise-wiki-pages"

echo ""
echo "Oppstartsskriptet har ikke restartet aktive queue-workere."
echo "Trykk Ctrl+C for å stoppe Vite."
echo "Docker-tjenestene fortsetter å kjøre."
echo ""

wait "$VITE_PID"
