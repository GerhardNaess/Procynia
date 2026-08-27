#!/usr/bin/env bash
#
# Procynia — Azure context check.
#
#   ./scripts/azure-bootstrap/check-azure-context.sh
#
# Read-only. Creates nothing, changes nothing, deploys nothing. It answers one question before any
# Azure work starts: "am I logged in, and is the active subscription the one I think it is?"
#
# It deliberately does NOT pick a subscription for you. Selecting "the first one" is how staging work
# ends up in a production subscription. If more than one subscription is visible and
# PROCYNIA_SUBSCRIPTION is not set, this script stops and asks.
#
# Environment:
#   PROCYNIA_SUBSCRIPTION    Subscription id or name to activate. Required when more than one exists.
#   PROCYNIA_AZURE_LOCATION  Region to report on (default norwayeast).
#
# Exit codes: 0 = context is usable, 1 = something is missing, 2 = usage/tooling problem.

set -euo pipefail

readonly DEFAULT_LOCATION="norwayeast"

# Resource providers the Procynia Bicep needs registered on the subscription.
readonly REQUIRED_PROVIDERS="
Microsoft.App
Microsoft.ContainerRegistry
Microsoft.DBforPostgreSQL
Microsoft.Cache
Microsoft.KeyVault
Microsoft.Storage
Microsoft.OperationalInsights
Microsoft.Insights
Microsoft.ManagedIdentity
"

section() { printf '\n\033[1;34m── %s\033[0m\n' "$*"; }
pass()    { printf '  \033[1;32mPASS\033[0m %s\n' "$*"; }
fail()    { printf '  \033[1;31mFAIL\033[0m %s\n' "$*"; }
warn()    { printf '  \033[1;33mWARN\033[0m %s\n' "$*"; }
note()    { printf '       %s\n' "$*"; }

PROBLEMS=0
record_problem() { PROBLEMS=$((PROBLEMS + 1)); }

# ---------------------------------------------------------------------------

section "Azure CLI"

if ! command -v az >/dev/null 2>&1; then
    fail "Azure CLI ('az') is not installed."
    note "macOS:  brew install azure-cli"
    note "Then:   az bicep install"
    note ""
    note "Nothing below can run without it. See docs/azure-bootstrap.md."
    exit 2
fi

AZ_VERSION="$(az version --query '"azure-cli"' -o tsv 2>/dev/null || echo unknown)"
pass "azure-cli ${AZ_VERSION}"

if az bicep version >/dev/null 2>&1; then
    pass "bicep $(az bicep version 2>&1 | head -1 | sed 's/.*version //' | awk '{print $1}')"
else
    fail "Bicep is not installed for the Azure CLI. Run: az bicep install"
    record_problem
fi

# ---------------------------------------------------------------------------

section "Sign-in"

if ! az account show >/dev/null 2>&1; then
    fail "Not signed in to Azure."
    note "Run: az login"
    note "If the account belongs to more than one tenant: az login --tenant <tenant-id>"
    exit 1
fi

SIGNED_IN_USER="$(az account show --query user.name -o tsv 2>/dev/null || echo unknown)"
pass "signed in as ${SIGNED_IN_USER}"

# ---------------------------------------------------------------------------

section "Subscription selection"

SUBSCRIPTION_COUNT="$(az account list --query 'length(@)' -o tsv 2>/dev/null || echo 0)"

if [ "${SUBSCRIPTION_COUNT}" = "0" ]; then
    fail "The signed-in account can see no subscriptions."
    note "A tenant without a subscription cannot host anything. See docs/azure-bootstrap.md, phase 2."
    exit 1
fi

note "subscriptions visible: ${SUBSCRIPTION_COUNT}"

if [ -n "${PROCYNIA_SUBSCRIPTION:-}" ]; then
    if az account set --subscription "${PROCYNIA_SUBSCRIPTION}" >/dev/null 2>&1; then
        pass "activated the subscription named by PROCYNIA_SUBSCRIPTION"
    else
        fail "PROCYNIA_SUBSCRIPTION is set, but that subscription could not be activated."
        note "Check the id or name against: az account list -o table"
        exit 1
    fi
elif [ "${SUBSCRIPTION_COUNT}" != "1" ]; then
    # Never guess. Picking "the first one" is how staging work lands in a production subscription.
    fail "More than one subscription is visible and PROCYNIA_SUBSCRIPTION is not set."
    note "This script will not choose for you. Pick one explicitly:"
    note ""
    az account list --query '[].{name:name, id:id, state:state}' -o table 2>/dev/null | sed 's/^/       /'
    note ""
    note 'Then: export PROCYNIA_SUBSCRIPTION="<id or name>"'
    exit 1
else
    warn "using the only visible subscription; set PROCYNIA_SUBSCRIPTION to make the choice explicit"
fi

TENANT_ID="$(az account show --query tenantId -o tsv)"
SUBSCRIPTION_ID="$(az account show --query id -o tsv)"
SUBSCRIPTION_NAME="$(az account show --query name -o tsv)"
SUBSCRIPTION_STATE="$(az account show --query state -o tsv)"

pass "subscription: ${SUBSCRIPTION_NAME}"
note "subscription id: ${SUBSCRIPTION_ID}"
note "tenant id:       ${TENANT_ID}"

if [ "${SUBSCRIPTION_STATE}" = "Enabled" ]; then
    pass "subscription state: Enabled"
else
    fail "subscription state is [${SUBSCRIPTION_STATE}], not Enabled. Billing may not be active."
    record_problem
fi

# ---------------------------------------------------------------------------

section "Region"

LOCATION="${PROCYNIA_AZURE_LOCATION:-${DEFAULT_LOCATION}}"

if az account list-locations --query "[?name=='${LOCATION}'].name" -o tsv 2>/dev/null | grep -q .; then
    pass "region [${LOCATION}] is available to this subscription"
else
    fail "region [${LOCATION}] is not available to this subscription."
    note "Available regions: az account list-locations --query '[].name' -o tsv"
    record_problem
fi

# ---------------------------------------------------------------------------

section "Resource providers"

for provider in ${REQUIRED_PROVIDERS}; do
    STATE="$(az provider show --namespace "${provider}" --query registrationState -o tsv 2>/dev/null || echo Unknown)"

    case "${STATE}" in
        Registered)
            pass "${provider}"
            ;;
        Registering)
            warn "${provider} is still registering — wait and re-run"
            record_problem
            ;;
        *)
            fail "${provider} is [${STATE}]"
            note "Register it: az provider register --namespace ${provider}"
            record_problem
            ;;
    esac
done

# ---------------------------------------------------------------------------

section "Permissions"

# Bicep creates role assignments, which needs more than Contributor.
PRINCIPAL_ID="$(az ad signed-in-user show --query id -o tsv 2>/dev/null || echo '')"

if [ -z "${PRINCIPAL_ID}" ]; then
    warn "could not resolve the signed-in principal (Graph permission may be missing)"
    note "deploy.sh can still run, but it cannot grant Key Vault Secrets Officer automatically."
else
    pass "signed-in principal resolved"

    ROLES="$(az role assignment list --assignee "${PRINCIPAL_ID}" --scope "/subscriptions/${SUBSCRIPTION_ID}" \
        --query '[].roleDefinitionName' -o tsv 2>/dev/null || echo '')"

    if printf '%s' "${ROLES}" | grep -q 'Owner'; then
        pass "Owner on the subscription — sufficient for the role assignments Bicep creates"
    elif printf '%s' "${ROLES}" | grep -q 'User Access Administrator'; then
        pass "User Access Administrator present alongside: ${ROLES//$'\n'/, }"
    else
        fail "neither Owner nor User Access Administrator at subscription scope."
        note "Roles found: ${ROLES:-none}"
        note "The Bicep creates AcrPull, Key Vault Secrets User and Storage data role assignments,"
        note "which Contributor alone cannot do."
        record_problem
    fi
fi

# ---------------------------------------------------------------------------

section "Summary"

printf '\n'
printf '  Tenant:       %s\n' "${TENANT_ID}"
printf '  Subscription: %s (%s)\n' "${SUBSCRIPTION_NAME}" "${SUBSCRIPTION_ID}"
printf '  Region:       %s\n' "${LOCATION}"
printf '\n'

if [ "${PROBLEMS}" -gt 0 ]; then
    printf '\033[1;31m%d problem(s) found. Azure context is not ready.\033[0m\n' "${PROBLEMS}"
    exit 1
fi

printf '\033[1;32mAzure context is usable.\033[0m\n'
printf 'Next: ./scripts/azure-bootstrap/verify-azure-prerequisites.sh\n'
