// User assigned managed identity shared by every Procynia Container App.
//
// A user assigned identity is used instead of system assigned identities because
// the identity has to exist *before* the Container Apps are created: the apps
// pull their image from ACR and resolve their secrets from Key Vault using this
// identity, so the role assignments cannot depend on the apps themselves.

@description('User assigned managed identity name.')
param identityName string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

resource identity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: identityName
  location: location
  tags: tags
}

@description('Resource id of the workload identity.')
output identityId string = identity.id

@description('Client id of the workload identity.')
output clientId string = identity.properties.clientId

@description('Principal (object) id used for role assignments.')
output principalId string = identity.properties.principalId

@description('Identity name.')
output identityName string = identity.name
