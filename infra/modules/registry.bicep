// Azure Container Registry.
//
// The admin user stays disabled: Container Apps authenticate with the workload
// managed identity (AcrPull), and CI/local pushes use "az acr login" which is
// Entra based. No registry credential is ever stored as a static secret.

@description('Container registry name. Globally unique, alphanumeric only.')
@minLength(5)
@maxLength(50)
param registryName string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

@description('Registry SKU. Basic is sufficient for staging; Premium is only needed for private endpoints, geo-replication or content trust.')
@allowed([
  'Basic'
  'Standard'
  'Premium'
])
param sku string = 'Basic'

@description('Principal id of the workload identity that must be able to pull images.')
param pullPrincipalId string

@description('Days after which untagged manifests are deleted. 0 disables the policy. Requires Premium.')
param untaggedManifestRetentionDays int = 0

var acrPullRoleDefinitionId = '7f951dda-4ed3-4680-a7ca-43fe172d538d'

resource registry 'Microsoft.ContainerRegistry/registries@2023-07-01' = {
  name: registryName
  location: location
  tags: tags
  sku: {
    name: sku
  }
  properties: {
    adminUserEnabled: false
    publicNetworkAccess: 'Enabled'
    dataEndpointEnabled: false
    policies: sku == 'Premium' && untaggedManifestRetentionDays > 0
      ? {
          retentionPolicy: {
            status: 'enabled'
            days: untaggedManifestRetentionDays
          }
        }
      : {}
  }
}

resource acrPull 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: registry
  name: guid(registry.id, pullPrincipalId, acrPullRoleDefinitionId)
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', acrPullRoleDefinitionId)
    principalId: pullPrincipalId
    principalType: 'ServicePrincipal'
  }
}

@description('Registry resource id.')
output registryId string = registry.id

@description('Registry name, used by "az acr build" / "az acr login".')
output registryName string = registry.name

@description('Registry login server, used as the image prefix.')
output loginServer string = registry.properties.loginServer
