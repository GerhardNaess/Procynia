#!/usr/bin/env bash
# Starter Procynia lokalt etter strømbrudd/restart.
# App serveres via Docker/Nginx på port 8080.
# php artisan serve skal ikke kjøres.
set -Eeuo pipefail

PROJECT_DIR="/Applications/XAMPP/xamppfiles/htdocs/procynia"
STARTUP_TIMEOUT_SECONDS=90
POLL_INTERVAL_SECONDS=2

REQUIRED_SERVICES=(
  postgres
  redis
  app
  web
  queue
  queue-ai-requirements
  queue-enterprise-wiki
  scheduler
)

WORKER_SERVICES=(
  queue
  queue-ai-requirements
  queue-enterprise-wiki
)

VITE_PID=""

cd "$PROJECT_DIR"

cleanup() {
  if [ -n "${VITE_PID:-}" ] && kill -0 "$VITE_PID" 2>/dev/null; then
    echo ""
    echo "Stopper Vite..."
    kill "$VITE_PID" 2>/dev/null || true
    wait "$VITE_PID" 2>/dev/null || true
  fi
}

handle_signal() {
  cleanup
  exit 130
}

trap cleanup EXIT
trap handle_signal INT TERM

service_exists() {
  local service="$1"

  docker compose config --services 2>/dev/null |
    grep -Fxq "$service"
}

container_id_for_service() {
  local service="$1"

  docker compose ps -q "$service" 2>/dev/null |
    head -n 1
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
      --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
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

worker_process_is_running() {
  local service="$1"

  docker compose top "$service" 2>/dev/null |
    grep -Eq 'queue:work|artisan horizon'
}

verify_worker() {
  local service="$1"

  if worker_process_is_running "$service"; then
    echo "  OK: $service har en aktiv queue-worker."
    return 0
  fi

  echo "  ADVARSEL: Fant ingen queue-worker i $service."
  echo "  Starter containeren på nytt..."

  docker compose restart "$service"

  if ! wait_for_service "$service"; then
    return 1
  fi

  sleep 2

  if worker_process_is_running "$service"; then
    echo "  OK: $service har nå en aktiv queue-worker."
    return 0
  fi

  show_service_failure \
    "$service" \
    "Containeren kjører, men queue-worker-prosessen mangler."

  echo ""
  echo "Prosesser i containeren:"
  docker compose top "$service" || true

  return 1
}

verify_worker_queue_name() {
  local service="$1"
  local expected_queue="$2"
  local process_output

  process_output="$(docker compose top "$service" 2>/dev/null || true)"

  if printf '%s\n' "$process_output" | grep -Fq "$expected_queue"; then
    echo "  OK: $service lytter på $expected_queue."
    return 0
  fi

  show_service_failure \
    "$service" \
    "Worker-prosessen ser ikke ut til å lytte på køen '$expected_queue'."

  echo ""
  echo "Prosesser i containeren:"
  printf '%s\n' "$process_output"

  return 1
}

echo "Kontrollerer Docker Compose-tjenester..."

for service in "${REQUIRED_SERVICES[@]}"; do
  if ! service_exists "$service"; then
    echo ""
    echo "FEIL: Påkrevd Docker Compose-tjeneste finnes ikke: $service"
    echo ""
    echo "Tilgjengelige tjenester:"
    docker compose config --services
    exit 1
  fi
done

echo ""
echo "Starter nødvendig Docker-stack..."

docker compose up -d \
  postgres \
  redis \
  app \
  web \
  queue \
  queue-ai-requirements \
  queue-enterprise-wiki \
  scheduler

echo ""
echo "Venter på at tjenestene skal bli klare..."

for service in "${REQUIRED_SERVICES[@]}"; do
  if ! wait_for_service "$service"; then
    exit 1
  fi
done

echo ""
echo "Kontrollerer queue-workerne..."

for service in "${WORKER_SERVICES[@]}"; do
  if ! verify_worker "$service"; then
    exit 1
  fi
done

echo ""
echo "Kontrollerer spesialkøene..."

if ! verify_worker_queue_name \
  "queue-ai-requirements" \
  "ai-requirements"; then
  exit 1
fi

if ! verify_worker_queue_name \
  "queue-enterprise-wiki" \
  "enterprise-wiki"; then
  exit 1
fi

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
echo ""
echo "Trykk Ctrl+C for å stoppe Vite."
echo "Docker-tjenestene fortsetter å kjøre."
echo ""

wait "$VITE_PID"
