#!/usr/bin/env bash
# Starter Procynia lokalt etter strømbrudd/restart.
# App serveres via Docker/Nginx på port 8080 — php artisan serve kjøres ikke.
set -euo pipefail

cd /Applications/XAMPP/xamppfiles/htdocs/procynia

echo "Starter Docker Compose..."
docker compose up -d

echo ""
echo "Docker-status:"
docker compose ps

# Fjern eventuell gammel Vite hot-fil slik at Docker bruker bygde assets
# inntil Vite-serveren er oppe.
if [ -f public/hot ]; then
  echo ""
  echo "Fjerner gammel public/hot..."
  rm public/hot
fi

echo ""
echo "Starter Vite (npm run dev)..."
npm run dev &
VITE_PID=$!

cleanup() {
  echo ""
  echo "Stopper Vite..."
  kill "$VITE_PID" 2>/dev/null || true
}

trap cleanup EXIT INT TERM

echo ""
echo "Procynia kjører:"
echo "  App:  http://127.0.0.1:8080"
echo "  Vite: se terminal-output fra npm run dev"
echo ""
echo "Queue workers (starter via Docker Compose):"
echo "  queue"
echo "  queue-ai-requirements"
echo "  queue-enterprise-wiki"
echo ""
echo "Trykk Ctrl+C for å stoppe Vite."
wait
