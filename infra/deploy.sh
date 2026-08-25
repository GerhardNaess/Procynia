#!/usr/bin/env bash
#
# Procynia - Azure infrastructure deployment.
#
#   ./infra/deploy.sh staging                 validate + what-if (read only)
#   ./infra/deploy.sh staging --apply         validate + what-if + deploy platform
#   ./infra/deploy.sh staging --apply --with-apps
#                                             ... including the Container Apps
#
# Without --apply the script never changes anything except creating the resource
# group, and even that requires --apply. It never deletes a resource, never runs a
# complete-mode deployment and never prints a secret value.
#
# Required environment:
#   PROCYNIA_PG_ADMIN_PASSWORD   PostgreSQL administrator password.
#
# Optional environment:
#   PROCYNIA_SUBSCRIPTION        Subscription id or name to select first.
#   PROCYNIA_AZURE_LOCATION      Overrides the region (default norwayeast).
#   PROCYNIA_IMAGE_TAG           Image tag used when deploying the workloads.
#   PROCYNIA_RESOURCE_GROUP      Overrides the derived resource group name.
#   PROCYNIA_DEPLOYER_OBJECT_ID  Skips the "az ad signed-in-user show" lookup.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
readonly TEMPLATE_FILE="${SCRIPT_DIR}/main.bicep"
readonly DEFAULT_LOCATION="norwayeast"

log()   { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn()  { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
fail()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'USAGE'
Usage: ./infra/deploy.sh <staging|production> [--apply] [--with-apps] [--yes]

  (no flag)     Validate the template and show what-if. Changes nothing.
  --apply       Perform the deployment after validation and what-if.
  --with-apps   Include the Container Apps (web, queue workers, scheduler).
                Requires images in ACR and the Key Vault secrets to be seeded.
  --yes         Skip the interactive confirmation before applying.

Deployment is always explicit per environment; there is no default.
USAGE
}

ENVIRONMENT=""
APPLY="false"
WITH_APPS="false"
ASSUME_YES="false"

while [ "$#" -gt 0 ]; do
    case "$1" in
        staging|production)
            [ -z "${ENVIRONMENT}" ] || fail "Environment already set to '${ENVIRONMENT}'."
            ENVIRONMENT="$1"
            ;;
        --apply)     APPLY="true" ;;
        --with-apps) WITH_APPS="true" ;;
        --yes|-y)    ASSUME_YES="true" ;;
        -h|--help)   usage; exit 0 ;;
        *)           usage; fail "Unknown argument: $1" ;;
    esac
    shift
done

[ -n "${ENVIRONMENT}" ] || { usage; fail "No environment given. Pass 'staging' or 'production'."; }

readonly PARAM_FILE="${SCRIPT_DIR}/environments/${ENVIRONMENT}.bicepparam"
[ -f "${PARAM_FILE}" ] || fail "Missing parameter file: ${PARAM_FILE}"
[ -f "${TEMPLATE_FILE}" ] || fail "Missing template: ${TEMPLATE_FILE}"

# --- Prerequisites ---------------------------------------------------------

command -v az >/dev/null 2>&1 || fail "Azure CLI ('az') not found. See infra/README.md."

if ! az bicep version >/dev/null 2>&1; then
    fail "Bicep CLI not available. Run: az bicep install"
fi

if ! az account show >/dev/null 2>&1; then
    fail "Not logged in to Azure. Run: az login"
fi

if [ -n "${PROCYNIA_SUBSCRIPTION:-}" ]; then
    log "Selecting subscription: ${PROCYNIA_SUBSCRIPTION}"
    az account set --subscription "${PROCYNIA_SUBSCRIPTION}"
fi

SUBSCRIPTION_NAME="$(az account show --query name -o tsv)"
SUBSCRIPTION_ID="$(az account show --query id -o tsv)"
TENANT_ID="$(az account show --query tenantId -o tsv)"

LOCATION="${PROCYNIA_AZURE_LOCATION:-${DEFAULT_LOCATION}}"
RESOURCE_GROUP="${PROCYNIA_RESOURCE_GROUP:-rg-procynia-${ENVIRONMENT}-${LOCATION}}"
DEPLOYMENT_NAME="procynia-${ENVIRONMENT}-$(date -u +%Y%m%d%H%M%S)"

# --- Secret prerequisites --------------------------------------------------
# The value is never echoed, only its presence is asserted.

if [ -z "${PROCYNIA_PG_ADMIN_PASSWORD:-}" ]; then
    fail "PROCYNIA_PG_ADMIN_PASSWORD is not set. Azure PostgreSQL cannot be created without an administrator password."
fi
if [ "${#PROCYNIA_PG_ADMIN_PASSWORD}" -lt 12 ]; then
    fail "PROCYNIA_PG_ADMIN_PASSWORD is shorter than the 12 character minimum enforced by main.bicep."
fi

if [ "${WITH_APPS}" = "true" ] && [ "${ENVIRONMENT}" = "production" ] && [ -z "${PROCYNIA_IMAGE_TAG:-}" ]; then
    fail "PROCYNIA_IMAGE_TAG must be set to an immutable tag or digest when deploying production workloads."
fi

if [ -z "${PROCYNIA_DEPLOYER_OBJECT_ID:-}" ]; then
    if PROBED_ID="$(az ad signed-in-user show --query id -o tsv 2>/dev/null)" && [ -n "${PROBED_ID}" ]; then
        PROCYNIA_DEPLOYER_OBJECT_ID="${PROBED_ID}"
    else
        warn "Could not resolve the signed-in principal object id."
        warn "Key Vault Secrets Officer will not be granted automatically; assign it manually before seeding secrets."
        PROCYNIA_DEPLOYER_OBJECT_ID=""
    fi
fi

export PROCYNIA_AZURE_LOCATION="${LOCATION}"
export PROCYNIA_DEPLOYER_OBJECT_ID
export PROCYNIA_PG_ADMIN_PASSWORD

# --- Context -----------------------------------------------------------------

cat <<CONTEXT

  Environment      ${ENVIRONMENT}
  Subscription     ${SUBSCRIPTION_NAME} (${SUBSCRIPTION_ID})
  Tenant           ${TENANT_ID}
  Region           ${LOCATION}
  Resource group   ${RESOURCE_GROUP}
  Template         ${TEMPLATE_FILE}
  Parameters       ${PARAM_FILE}
  Deploy workloads ${WITH_APPS}
  Mode             $([ "${APPLY}" = "true" ] && echo "APPLY" || echo "validate + what-if only")

CONTEXT

INLINE_PARAMS=("deployWorkloads=${WITH_APPS}")
if [ "${WITH_APPS}" = "true" ] && [ -n "${PROCYNIA_IMAGE_TAG:-}" ]; then
    INLINE_PARAMS+=("imageTag=${PROCYNIA_IMAGE_TAG}")
fi

# --- Resource group ---------------------------------------------------------

if az group show --name "${RESOURCE_GROUP}" >/dev/null 2>&1; then
    log "Resource group '${RESOURCE_GROUP}' already exists."
elif [ "${APPLY}" = "true" ]; then
    log "Creating resource group '${RESOURCE_GROUP}' in ${LOCATION}."
    az group create \
        --name "${RESOURCE_GROUP}" \
        --location "${LOCATION}" \
        --tags application=procynia environment="${ENVIRONMENT}" managed-by=bicep \
        --output none
else
    warn "Resource group '${RESOURCE_GROUP}' does not exist."
    warn "Validation and what-if need it, so they are skipped. Re-run with --apply to create it."
    exit 0
fi

# --- Validate ---------------------------------------------------------------

log "Validating template (az deployment group validate)."
if ! az deployment group validate \
        --resource-group "${RESOURCE_GROUP}" \
        --name "${DEPLOYMENT_NAME}" \
        --template-file "${TEMPLATE_FILE}" \
        --parameters "${PARAM_FILE}" \
        --parameters "${INLINE_PARAMS[@]}" \
        --output none; then
    fail "Validation failed. Nothing was deployed."
fi
log "Validation passed."

# --- What-if ----------------------------------------------------------------

log "Running what-if (no changes are made)."
if ! az deployment group what-if \
        --resource-group "${RESOURCE_GROUP}" \
        --name "${DEPLOYMENT_NAME}" \
        --template-file "${TEMPLATE_FILE}" \
        --parameters "${PARAM_FILE}" \
        --parameters "${INLINE_PARAMS[@]}" \
        --result-format FullResourcePayloads; then
    fail "What-if failed. Nothing was deployed."
fi

if [ "${APPLY}" != "true" ]; then
    log "Validate + what-if complete. Re-run with --apply to deploy."
    exit 0
fi

# --- Confirm ----------------------------------------------------------------

if [ "${ASSUME_YES}" != "true" ]; then
    printf '\nDeploy the changes above to %s / %s? Type the environment name to continue: ' \
        "${SUBSCRIPTION_NAME}" "${RESOURCE_GROUP}"
    read -r CONFIRMATION
    [ "${CONFIRMATION}" = "${ENVIRONMENT}" ] || fail "Confirmation did not match '${ENVIRONMENT}'. Aborted."
fi

# --- Deploy -----------------------------------------------------------------
# Incremental mode only. Complete mode would delete resources that are not in the
# template and is deliberately never used here.

log "Deploying (incremental mode)."
az deployment group create \
    --resource-group "${RESOURCE_GROUP}" \
    --name "${DEPLOYMENT_NAME}" \
    --mode Incremental \
    --template-file "${TEMPLATE_FILE}" \
    --parameters "${PARAM_FILE}" \
    --parameters "${INLINE_PARAMS[@]}" \
    --output none

log "Deployment '${DEPLOYMENT_NAME}' finished. Outputs:"
az deployment group show \
    --resource-group "${RESOURCE_GROUP}" \
    --name "${DEPLOYMENT_NAME}" \
    --query "properties.outputs" \
    --output json

if [ "${WITH_APPS}" != "true" ]; then
    cat <<'NEXT'

Platform is in place. Before deploying the workloads:

  1. Seed the Key Vault secrets listed in the
     keyVaultSecretsRequiringOperatorInput output (see infra/README.md).
  2. Build and push the web and app images to the registry named in the
     containerRegistryName output.
  3. Run the database migration job. It is what creates the pgvector extension.
  4. Re-run this script with --apply --with-apps.

NEXT
fi
