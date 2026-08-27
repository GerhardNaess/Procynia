#!/usr/bin/env bash
#
# Procynia — Azure prerequisite verification.
#
#   ./scripts/azure-bootstrap/verify-azure-prerequisites.sh
#
# Read-only. Creates nothing. Run it after check-azure-context.sh, before the first
# `./infra/deploy.sh staging --apply`.
#
# It answers the questions that cannot be answered offline: is every service Procynia needs actually
# available in the chosen region on THIS subscription, and are the specific SKUs the staging
# parameters ask for actually offered there?
#
# SKUs are read out of infra/environments/staging.bicepparam rather than hardcoded, so this script
# cannot drift away from what would really be deployed.
#
# Environment:
#   PROCYNIA_SUBSCRIPTION    Subscription id or name (see check-azure-context.sh).
#   PROCYNIA_AZURE_LOCATION  Region to verify (default norwayeast).
#
# Exit codes: 0 = all verified, 1 = at least one prerequisite missing, 2 = tooling/context problem.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
readonly PARAM_FILE="${SCRIPT_DIR}/../../infra/environments/staging.bicepparam"
readonly DEFAULT_LOCATION="norwayeast"

section() { printf '\n\033[1;34m── %s\033[0m\n' "$*"; }
pass()    { printf '  \033[1;32mPASS\033[0m %s\n' "$*"; }
fail()    { printf '  \033[1;31mFAIL\033[0m %s\n' "$*"; }
warn()    { printf '  \033[1;33mWARN\033[0m %s\n' "$*"; }
note()    { printf '       %s\n' "$*"; }

PROBLEMS=0
record_problem() { PROBLEMS=$((PROBLEMS + 1)); }

command -v az >/dev/null 2>&1 || {
    printf '\033[1;31m[x]\033[0m Azure CLI is not installed. Run check-azure-context.sh first.\n' >&2
    exit 2
}
az account show >/dev/null 2>&1 || {
    printf '\033[1;31m[x]\033[0m Not signed in. Run check-azure-context.sh first.\n' >&2
    exit 2
}
[ -f "${PARAM_FILE}" ] || {
    printf '\033[1;31m[x]\033[0m Missing %s\n' "${PARAM_FILE}" >&2
    exit 2
}

if [ -n "${PROCYNIA_SUBSCRIPTION:-}" ]; then
    az account set --subscription "${PROCYNIA_SUBSCRIPTION}" >/dev/null
fi

LOCATION="${PROCYNIA_AZURE_LOCATION:-${DEFAULT_LOCATION}}"
SUBSCRIPTION_NAME="$(az account show --query name -o tsv)"

# Read the SKUs that would actually be deployed.
param_value() {
    sed -n "s/^param $1 = '\{0,1\}\([^']*\)'\{0,1\}[[:space:]]*$/\1/p" "${PARAM_FILE}" | head -1
}

PG_SKU="$(param_value postgresSkuName)"
PG_TIER="$(param_value postgresSkuTier)"
PG_VERSION="$(param_value postgresVersion)"
PG_STORAGE="$(param_value postgresStorageSizeGb)"
REDIS_SKU="$(param_value redisSku)"
STORAGE_SKU="$(param_value storageSku)"
ACR_SKU="$(param_value containerRegistrySku)"

printf '\n  Subscription: %s\n  Region:       %s\n' "${SUBSCRIPTION_NAME}" "${LOCATION}"
printf '  Staging SKUs read from staging.bicepparam:\n'
printf '    PostgreSQL %s %s (v%s, %s GiB)\n' "${PG_TIER}" "${PG_SKU}" "${PG_VERSION}" "${PG_STORAGE}"
printf '    Redis      %s\n' "${REDIS_SKU}"
printf '    Storage    %s\n' "${STORAGE_SKU}"
printf '    ACR        %s\n' "${ACR_SKU}"

# ---------------------------------------------------------------------------

section "Service availability in ${LOCATION}"

# The provider's per-resource-type location list is the authoritative answer for this subscription.
# Provider output uses display names ("Norway East"); normalise both sides for comparison.
normalise() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -d ' '; }

check_resource_type() {
    local namespace="$1" resource_type="$2" label="$3"
    local locations normalised_target found

    locations="$(az provider show --namespace "${namespace}" \
        --query "resourceTypes[?resourceType=='${resource_type}'].locations | [0]" -o tsv 2>/dev/null || echo '')"

    if [ -z "${locations}" ]; then
        fail "${label}: could not read locations for ${namespace}/${resource_type}"
        record_problem
        return
    fi

    normalised_target="$(normalise "${LOCATION}")"
    found="no"

    while IFS= read -r candidate; do
        [ -z "${candidate}" ] && continue
        if [ "$(normalise "${candidate}")" = "${normalised_target}" ]; then
            found="yes"
            break
        fi
    done <<< "${locations}"

    if [ "${found}" = "yes" ]; then
        pass "${label}"
    else
        fail "${label} is NOT available in ${LOCATION}"
        note "Available: $(printf '%s' "${locations}" | tr '\n' ',' | sed 's/,$//')"
        record_problem
    fi
}

check_resource_type Microsoft.App              managedEnvironments "Container Apps Environment"
check_resource_type Microsoft.App              containerApps       "Container Apps"
check_resource_type Microsoft.ContainerRegistry registries         "Container Registry"
check_resource_type Microsoft.DBforPostgreSQL  flexibleServers     "PostgreSQL Flexible Server"
check_resource_type Microsoft.Cache            redisEnterprise     "Azure Managed Redis (redisEnterprise)"
check_resource_type Microsoft.Storage          storageAccounts     "Storage Account"
check_resource_type Microsoft.KeyVault         vaults              "Key Vault"
check_resource_type Microsoft.OperationalInsights workspaces       "Log Analytics"
check_resource_type Microsoft.Insights         components          "Application Insights"
check_resource_type Microsoft.ManagedIdentity  userAssignedIdentities "Managed Identity"

# ---------------------------------------------------------------------------

section "PostgreSQL Flexible Server"

PG_SKUS_JSON="$(az postgres flexible-server list-skus --location "${LOCATION}" -o json 2>/dev/null || echo '[]')"

if [ "${PG_SKUS_JSON}" = "[]" ]; then
    warn "could not list PostgreSQL SKUs (the CLI may need: az extension add --name rdbms-connect)"
    note "Verify manually: az postgres flexible-server list-skus --location ${LOCATION} -o table"
    record_problem
else
    if printf '%s' "${PG_SKUS_JSON}" | grep -q "\"${PG_VERSION}\""; then
        pass "PostgreSQL ${PG_VERSION} is offered in ${LOCATION}"
    else
        fail "PostgreSQL ${PG_VERSION} is NOT offered in ${LOCATION}"
        record_problem
    fi

    if printf '%s' "${PG_SKUS_JSON}" | grep -q "\"${PG_SKU}\""; then
        pass "compute SKU ${PG_SKU} (${PG_TIER}) is available"
    else
        fail "compute SKU ${PG_SKU} is NOT available in ${LOCATION}"
        note "List what is: az postgres flexible-server list-skus --location ${LOCATION} -o table"
        note "Do not silently move to a larger SKU — report the nearest option and its cost first."
        record_problem
    fi
fi

note "azure.extensions=VECTOR is a server parameter, not a regional capability. It is set"
note "declaratively by infra/modules/postgres.bicep and can only be confirmed after deployment."

# ---------------------------------------------------------------------------

section "Azure Managed Redis"

REDIS_SKUS="$(az redisenterprise list-skus --location "${LOCATION}" -o tsv 2>/dev/null || echo '')"

if [ -z "${REDIS_SKUS}" ]; then
    warn "could not list Managed Redis SKUs from this CLI version"
    note "Managed Redis SKU availability is verified by the what-if in ./infra/deploy.sh staging."
    note "Expected SKU: ${REDIS_SKU}. If what-if rejects it, the nearest alternatives are"
    note "Balanced_B1 (HA-capable) and Balanced_B3."
else
    if printf '%s' "${REDIS_SKUS}" | grep -q "${REDIS_SKU}"; then
        pass "Managed Redis SKU ${REDIS_SKU} is available in ${LOCATION}"
    else
        fail "Managed Redis SKU ${REDIS_SKU} is NOT available in ${LOCATION}"
        note "Available: ${REDIS_SKUS//$'\n'/, }"
        record_problem
    fi
fi

note "Clustering policy must stay EnterpriseCluster: Procynia uses phpredis, which is not"
note "cluster-aware and cannot follow MOVED redirects from an OSSCluster endpoint."

# ---------------------------------------------------------------------------

section "Quota"

CORE_QUOTA="$(az vm list-usage --location "${LOCATION}" \
    --query "[?contains(localName, 'Total Regional')].{current:currentValue, limit:limit}" -o tsv 2>/dev/null || echo '')"

if [ -n "${CORE_QUOTA}" ]; then
    note "regional vCPU usage/limit: ${CORE_QUOTA//$'\t'/ of }"
    note "(Container Apps Consumption does not draw on this, but PostgreSQL compute does.)"
else
    warn "could not read regional quota"
fi

# ---------------------------------------------------------------------------

section "Summary"

printf '\n'
if [ "${PROBLEMS}" -gt 0 ]; then
    printf '\033[1;31m%d prerequisite problem(s) found.\033[0m\n' "${PROBLEMS}"
    printf 'Do not run ./infra/deploy.sh staging --apply until these are resolved.\n'
    exit 1
fi

printf '\033[1;32mAll prerequisites verified in %s.\033[0m\n' "${LOCATION}"
printf 'Next: ./infra/deploy.sh staging     (validate + what-if, no changes)\n'
