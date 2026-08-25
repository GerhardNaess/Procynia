// Key Vault, one per environment.
//
// Secret handling contract:
//   * The vault uses Azure RBAC, not access policies.
//   * The workload managed identity gets "Key Vault Secrets User" (read only).
//     Container Apps resolve their secrets from here at revision start.
//   * The deploying principal optionally gets "Key Vault Secrets Officer" so
//     that "az keyvault secret set" works right after the platform deployment.
//   * Only DB-PASSWORD is seeded from Bicep, because Azure PostgreSQL cannot be
//     created without an administrator password anyway. Every other secret is
//     set out of band (see infra/README.md) and merely *referenced* by the
//     Container Apps.
//   * No secret value is ever emitted as an output.

@description('Key Vault name. Globally unique, 3-24 characters.')
@minLength(3)
@maxLength(24)
param keyVaultName string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

@description('Soft delete retention in days.')
@minValue(7)
@maxValue(90)
param softDeleteRetentionInDays int = 90

@description('Purge protection. Cannot be turned off once enabled, so staging normally leaves it disabled and production enables it.')
param enablePurgeProtection bool = false

@description('Principal id of the workload identity that reads secrets.')
param readerPrincipalId string

@description('Object id of the human or service principal running the deployment. Leave empty to skip the Secrets Officer assignment and grant it manually.')
param deployerPrincipalId string = ''

@description('Name of the Key Vault secret holding the PostgreSQL administrator password.')
param databasePasswordSecretName string = 'DB-PASSWORD'

@description('PostgreSQL administrator password. Supplied at deployment time, never stored in the repository.')
@secure()
param databasePassword string

var secretsUserRoleDefinitionId = '4633458b-17de-408a-b874-0445c86b69e6'
var secretsOfficerRoleDefinitionId = 'b86a8fe4-44ce-4948-aee5-eccb2c155cd7'

resource vault 'Microsoft.KeyVault/vaults@2024-11-01' = {
  name: keyVaultName
  location: location
  tags: tags
  properties: {
    sku: {
      family: 'A'
      name: 'standard'
    }
    tenantId: tenant().tenantId
    enableRbacAuthorization: true
    enableSoftDelete: true
    softDeleteRetentionInDays: softDeleteRetentionInDays
    enablePurgeProtection: enablePurgeProtection ? true : null
    publicNetworkAccess: 'Enabled'
    networkAcls: {
      bypass: 'AzureServices'
      defaultAction: 'Allow'
    }
  }
}

resource secretsUser 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: vault
  name: guid(vault.id, readerPrincipalId, secretsUserRoleDefinitionId)
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', secretsUserRoleDefinitionId)
    principalId: readerPrincipalId
    principalType: 'ServicePrincipal'
  }
}

resource secretsOfficer 'Microsoft.Authorization/roleAssignments@2022-04-01' = if (!empty(deployerPrincipalId)) {
  scope: vault
  name: guid(vault.id, deployerPrincipalId, secretsOfficerRoleDefinitionId)
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', secretsOfficerRoleDefinitionId)
    principalId: deployerPrincipalId
  }
}

resource databasePasswordSecret 'Microsoft.KeyVault/vaults/secrets@2024-11-01' = {
  parent: vault
  name: databasePasswordSecretName
  properties: {
    value: databasePassword
    contentType: 'PostgreSQL administrator password'
  }
  dependsOn: [
    secretsOfficer
  ]
}

@description('Key Vault resource id.')
output keyVaultId string = vault.id

@description('Key Vault name.')
output keyVaultName string = vault.name

@description('Key Vault base URI. Ends with a trailing slash.')
output keyVaultUri string = vault.properties.vaultUri
