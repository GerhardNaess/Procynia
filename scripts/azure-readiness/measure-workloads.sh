#!/usr/bin/env bash
#
# Procynia — workload resource measurement, for Azure sizing.
#
#   ./scripts/azure-readiness/measure-workloads.sh
#
# The question this answers is narrow and practical: how much CPU and memory should the first Azure
# Container Apps workers be given? It is not a benchmark and makes no claim about throughput.
#
# What it measures, using the production image and real code paths:
#
#   * framework boot        — the floor every worker replica pays before doing any work
#   * PDF text extraction   — real pdftotext, on generated PDFs of increasing size
#   * DOCX parsing          — real PhpWord, on a generated document
#   * peak memory per path  — via memory_get_peak_usage(true), i.e. actual allocator pages
#
# What it does NOT measure, and why: the AI-bound stages of an ai-requirements run and an
# enterprise-wiki run are dominated by OpenAI round-trips. Measuring those honestly means paying for
# real completions against real tender documents. That needs an explicit decision, so this script
# stops short of it and prints what such a measurement would require.
#
# Nothing here touches a database, Redis, or any existing container. Every fixture is generated in a
# throwaway container and discarded with it.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
readonly PROJECT_ROOT="${SCRIPT_DIR}/../.."
readonly APP_IMAGE="procynia-app:production"

readonly RESULTS_FILE="${PROJECT_ROOT}/docs/azure-workload-sizing.md"

section() { printf '\n\033[1;34m── %s\033[0m\n' "$*"; }
note()    { printf '       %s\n' "$*"; }
abort()   { printf '\n\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 2; }

command -v docker >/dev/null 2>&1 || abort "docker is not installed or not on PATH."
docker image inspect "${APP_IMAGE}" >/dev/null 2>&1 \
    || abort "${APP_IMAGE} does not exist. Build it first: ./scripts/azure-readiness/production-image-smoke.sh"

section "Measuring inside ${APP_IMAGE}"
note "Each measurement runs in its own container, so nothing carries over between them."


# The measurement program lives in its own file next to this script and is mounted read-only into a
# throwaway container. Only the measurement harness is mounted — the application itself still comes
# entirely from the image, which is the property the smoke test exists to protect.
readonly MEASURE_SCRIPT="${SCRIPT_DIR}/measure-workloads.php"

run_measurement() {
    local mode="$1"
    docker run --rm --entrypoint php \
        -e APP_ENV=production \
        -e APP_DEBUG=false \
        -e APP_KEY=base64:bWVhc3VyZW1lbnQtb25seS1rZXktMDAwMDAwMDAwMDA= \
        -e LOG_CHANNEL=stderr \
        -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
        -e DB_CONNECTION=pgsql \
        -e PROCYNIA_OPTIMIZE_ON_BOOT=false \
        -v "${MEASURE_SCRIPT}:/tmp/measure-workloads.php:ro" \
        "${APP_IMAGE}" /tmp/measure-workloads.php "${mode}"
}

section "Framework boot"
BOOT_RESULT="$(run_measurement boot || true)"
printf '%s\n' "${BOOT_RESULT}" | awk -F'\t' 'NF>=3 {printf "  %-24s %8.3fs   peak %6.1f MB   %s\n", $1, $2, $3, $4}'

section "PDF extraction (real pdftotext)"
PDF_RESULT="$(run_measurement pdf || true)"
printf '%s\n' "${PDF_RESULT}" | awk -F'\t' 'NF>=3 {printf "  %-24s %8.3fs   peak %6.1f MB   %s\n", $1, $2, $3, $4}'

section "DOCX extraction (real PhpWord)"
DOCX_RESULT="$(run_measurement docx || true)"
printf '%s\n' "${DOCX_RESULT}" | awk -F'\t' 'NF>=3 {printf "  %-24s %8.3fs   peak %6.1f MB   %s\n", $1, $2, $3, $4}'

section "Timeout contract (declared, not measured)"
printf '  %-38s %s\n' "PHP max_execution_time (image)" "$(docker run --rm --entrypoint php "${APP_IMAGE}" -r 'echo ini_get("max_execution_time") ?: "0 (unlimited)";')"
printf '  %-38s %s\n' "PHP memory_limit (image)" "$(docker run --rm --entrypoint php "${APP_IMAGE}" -r 'echo ini_get("memory_limit");')"
printf '  %-38s %s\n' "OpenAI default request timeout" "120s (createResponse), 180s (get/post)"
printf '  %-38s %s\n' "OpenAI connect timeout" "min(timeout, 10)s"
printf '  %-38s %s\n' "ai-requirements worker --timeout" "2100s, retry_after 2700s"
printf '  %-38s %s\n' "enterprise-wiki worker --timeout" "1860s, retry_after 2100s"
printf '  %-38s %s\n' "Azure termination grace (max)" "600s"

section "Not measured — needs an explicit decision"
note "A representative ai-requirements run and enterprise-wiki run are dominated by OpenAI"
note "round-trips, not by local CPU. Measuring them honestly requires real completions against"
note "real tender documents, which costs money. That is deliberately not done here."
note ""
note "To measure them, the following would be needed:"
note "  1. Approval to spend on real OpenAI calls (order of magnitude: a full Wiki run is"
note "     tens of gpt-4.1-mini calls; an answer-draft run uses gpt-5)."
note "  2. A representative tender document set that may be processed in a throwaway database."
note "  3. ENTERPRISE_WIKI_AI_ENABLED=true in the measurement environment."
note ""
note "Until then the AI worker sizing in ${RESULTS_FILE##*/} is an ESTIMATE, and is labelled as one."

section "Done"
note "Findings are written up in docs/azure-workload-sizing.md."
