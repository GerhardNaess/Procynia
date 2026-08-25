#!/usr/bin/env bash
#
# Procynia - local infrastructure validation.
#
#   ./infra/validate.sh
#
# This is the offline check. It needs no Azure login, no subscription and creates
# nothing. It proves the templates compile, the parameter files satisfy the
# template contract, and the required parameters and environment inputs are
# present.
#
# What it cannot check, because it never talks to ARM:
#   * whether a SKU is available in the target region
#   * quota, name collisions on globally unique names, RBAC permissions
#   * the actual effect of a change on existing resources
# Those need "./infra/deploy.sh <env>", which runs
# "az deployment group validate" and "az deployment group what-if" against the
# real subscription without deploying.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
readonly ENVIRONMENTS="staging production"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ok\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

# --- Locate a Bicep compiler -------------------------------------------------

BICEP_CMD=""
BICEP_VERSION_ARG=""
if command -v bicep >/dev/null 2>&1; then
    BICEP_CMD="bicep"
    BICEP_VERSION_ARG="--version"
elif command -v az >/dev/null 2>&1 && az bicep version >/dev/null 2>&1; then
    BICEP_CMD="az bicep"
    BICEP_VERSION_ARG="version"
else
    fail "No Bicep compiler found. Install the Azure CLI and run 'az bicep install', or install the standalone Bicep CLI."
fi

log "Using Bicep: $(${BICEP_CMD} ${BICEP_VERSION_ARG} 2>&1 | head -1)"

# Placeholder values so that readEnvironmentVariable() resolves during a local
# build. These are never used for a deployment; deploy.sh requires the real
# PostgreSQL password to be present in the environment.
export PROCYNIA_PG_ADMIN_PASSWORD="${PROCYNIA_PG_ADMIN_PASSWORD:-validate-only-placeholder}"
export PROCYNIA_IMAGE_TAG="${PROCYNIA_IMAGE_TAG:-latest}"
export PROCYNIA_AZURE_LOCATION="${PROCYNIA_AZURE_LOCATION:-norwayeast}"
export PROCYNIA_DEPLOYER_OBJECT_ID="${PROCYNIA_DEPLOYER_OBJECT_ID:-}"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "${BUILD_DIR}"' EXIT

# --- Compile the template and every module -----------------------------------

log "Building main.bicep"
${BICEP_CMD} build "${SCRIPT_DIR}/main.bicep" --outfile "${BUILD_DIR}/main.json" \
    || fail "main.bicep failed to build."
ok "main.bicep"

log "Building modules"
for module in "${SCRIPT_DIR}"/modules/*.bicep; do
    name="$(basename "${module}")"
    ${BICEP_CMD} build "${module}" --outfile "${BUILD_DIR}/module-${name}.json" \
        || fail "${name} failed to build."
    ok "${name}"
done

# --- Compile the parameter files ---------------------------------------------

log "Building parameter files"
for environment in ${ENVIRONMENTS}; do
    param_file="${SCRIPT_DIR}/environments/${environment}.bicepparam"
    [ -f "${param_file}" ] || fail "Missing parameter file: ${param_file}"
    ${BICEP_CMD} build-params "${param_file}" --outfile "${BUILD_DIR}/${environment}.parameters.json" \
        || fail "${environment}.bicepparam failed to build."
    ok "${environment}.bicepparam"
done

# --- Check that every required template parameter is supplied ----------------
# A parameter with no default in main.bicep must be present in each .bicepparam,
# otherwise the failure only surfaces at deployment time.

required_params="$(
    python3 - "${BUILD_DIR}/main.json" <<'PY'
import json, sys
with open(sys.argv[1]) as handle:
    template = json.load(handle)
for name, spec in template.get("parameters", {}).items():
    if "defaultValue" not in spec:
        print(name)
PY
)"

log "Checking required parameters"
for environment in ${ENVIRONMENTS}; do
    supplied="$(
        python3 - "${BUILD_DIR}/${environment}.parameters.json" <<'PY'
import json, sys
with open(sys.argv[1]) as handle:
    document = json.load(handle)
print("\n".join(document.get("parameters", {}).keys()))
PY
    )"
    missing=""
    for parameter in ${required_params}; do
        case "
${supplied}
" in
            *"
${parameter}
"*) ;;
            *) missing="${missing} ${parameter}" ;;
        esac
    done
    if [ -n "${missing}" ]; then
        fail "${environment}.bicepparam is missing required parameter(s):${missing}"
    fi
    ok "${environment}: all required parameters supplied"
done

# --- Warn about placeholder secrets -----------------------------------------

if [ "${PROCYNIA_PG_ADMIN_PASSWORD}" = "validate-only-placeholder" ]; then
    warn "PROCYNIA_PG_ADMIN_PASSWORD was not set; a placeholder was used for the build."
    warn "deploy.sh will refuse to run until the real value is exported."
fi

# --- Shell scripts ----------------------------------------------------------

log "Checking shell scripts"
for script in "${SCRIPT_DIR}"/*.sh; do
    name="$(basename "${script}")"
    bash -n "${script}" || fail "${name} has a syntax error."
    ok "${name} (bash -n)"
done

if command -v shellcheck >/dev/null 2>&1; then
    for script in "${SCRIPT_DIR}"/*.sh; do
        shellcheck "${script}" || fail "shellcheck reported problems in $(basename "${script}")."
        ok "$(basename "${script}") (shellcheck)"
    done
else
    warn "shellcheck not installed; only 'bash -n' was run."
fi

printf '\n\033[1;32mLocal validation passed.\033[0m Run ./infra/deploy.sh <environment> for Azure side validation and what-if.\n'
