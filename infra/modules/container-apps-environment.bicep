// Container Apps Environment: the shared runtime for web, the queue workers and
// the scheduler, plus the Azure Files mount they all share.
//
// This is a separate module from container-apps.bicep on purpose. The
// environment, its log wiring and its storage definition are platform: they are
// created on the first deployment, before any container image exists. The
// workloads are deployed in a second pass once images are pushed and secrets are
// seeded.
//
// Both the Log Analytics shared key and the storage account key are resolved
// here through "existing" references, so neither value has to travel through a
// module output.

@description('Container Apps Environment name.')
param environmentName string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

@description('Log Analytics workspace name used as the container log sink. Must be in the same resource group.')
param logAnalyticsWorkspaceName string

@description('Storage account holding the Azure Files share. Must be in the same resource group.')
param storageAccountName string

@description('Azure Files share mounted into every Procynia container.')
param fileShareName string

@description('Name of the environment storage definition referenced by the container volumes.')
param storageDefinitionName string = 'procynia-app-storage'

@description('Zone redundancy. Requires VNet integration, so it stays disabled until the private networking phase.')
param zoneRedundant bool = false

resource logAnalytics 'Microsoft.OperationalInsights/workspaces@2023-09-01' existing = {
  name: logAnalyticsWorkspaceName
}

resource storageAccount 'Microsoft.Storage/storageAccounts@2024-01-01' existing = {
  name: storageAccountName
}

resource managedEnvironment 'Microsoft.App/managedEnvironments@2025-01-01' = {
  name: environmentName
  location: location
  tags: tags
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: logAnalytics.properties.customerId
        sharedKey: logAnalytics.listKeys().primarySharedKey
      }
    }
    zoneRedundant: zoneRedundant
    workloadProfiles: [
      {
        name: 'Consumption'
        workloadProfileType: 'Consumption'
      }
    ]
  }
}

// Azure Files, mounted read/write into web, every worker and the scheduler so
// that all of them observe the same physical paths under Laravel's storage
// directory. See modules/storage.bicep for why this is required today.
resource applicationStorage 'Microsoft.App/managedEnvironments/storages@2025-01-01' = {
  parent: managedEnvironment
  name: storageDefinitionName
  properties: {
    azureFile: {
      accountName: storageAccount.name
      accountKey: storageAccount.listKeys().keys[0].value
      shareName: fileShareName
      accessMode: 'ReadWrite'
    }
  }
}

@description('Container Apps Environment resource id.')
output managedEnvironmentId string = managedEnvironment.id

@description('Container Apps Environment name.')
output managedEnvironmentName string = managedEnvironment.name

@description('Default ingress domain for the environment.')
output defaultDomain string = managedEnvironment.properties.defaultDomain

@description('Outbound IP addresses. Useful for PostgreSQL firewall narrowing later on.')
output staticIp string = managedEnvironment.properties.staticIp

@description('Name of the environment storage definition to reference from container volumes.')
output storageDefinitionName string = applicationStorage.name
