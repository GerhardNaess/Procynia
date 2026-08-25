// Storage account with two distinct roles:
//
//   1. An Azure Files share, mounted read/write into web, every queue worker and
//      the scheduler. This is a *compatibility layer*, not the target state:
//      Procynia resolves physical filesystem paths in nine places
//      (Storage::disk('local')->path(...)) and hands them to external poppler
//      processes (pdftotext / pdftohtml / pdfimages / pdfinfo). Those code paths
//      need a real POSIX path that every container sees identically, which a
//      remote object store cannot provide.
//
//   2. A blob container, created now as the documented target state for document
//      storage. Nothing writes to it yet - Laravel still runs on the local disk
//      driver - so it is deliberately left empty until the storage refactor.
//
// Blob versioning and soft delete apply to the blob service only; Azure Files
// gets its own share level soft delete.

@description('Storage account name. Globally unique, 3-24 lowercase alphanumeric characters.')
@minLength(3)
@maxLength(24)
param storageAccountName string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

@description('Replication SKU.')
@allowed([
  'Standard_LRS'
  'Standard_ZRS'
  'Standard_GRS'
  'Standard_GZRS'
])
param sku string = 'Standard_LRS'

@description('Name of the Azure Files share mounted into the containers.')
param fileShareName string = 'procynia-storage'

@description('Azure Files share quota in GiB.')
@minValue(1)
@maxValue(102400)
param fileShareQuotaGb int = 100

@description('Blob container reserved for the future document storage refactor.')
param blobContainerName string = 'documents'

@description('Blob soft delete retention in days. 0 disables it.')
@minValue(0)
@maxValue(365)
param blobSoftDeleteRetentionDays int = 30

@description('File share soft delete retention in days. 0 disables it.')
@minValue(0)
@maxValue(365)
param fileShareSoftDeleteRetentionDays int = 14

@description('Enable blob versioning. Recommended for the document container.')
param enableBlobVersioning bool = true

@description('Principal id of the workload identity. Granted data plane roles for the future blob migration and for identity based file access.')
param workloadPrincipalId string

var blobDataContributorRoleDefinitionId = 'ba92f5b4-2d11-453d-a403-e96b0029c9fe'
var fileDataSmbShareContributorRoleDefinitionId = '0c867c2a-1d8c-454a-a3db-ab2ea1bdc8bb'

resource storageAccount 'Microsoft.Storage/storageAccounts@2024-01-01' = {
  name: storageAccountName
  location: location
  tags: tags
  sku: {
    name: sku
  }
  kind: 'StorageV2'
  properties: {
    accessTier: 'Hot'
    minimumTlsVersion: 'TLS1_2'
    supportsHttpsTrafficOnly: true
    allowBlobPublicAccess: false
    allowSharedKeyAccess: true
    allowCrossTenantReplication: false
    defaultToOAuthAuthentication: false
    publicNetworkAccess: 'Enabled'
    networkAcls: {
      bypass: 'AzureServices'
      defaultAction: 'Allow'
    }
    encryption: {
      requireInfrastructureEncryption: false
      keySource: 'Microsoft.Storage'
      services: {
        blob: {
          enabled: true
          keyType: 'Account'
        }
        file: {
          enabled: true
          keyType: 'Account'
        }
      }
    }
  }
}

resource blobServices 'Microsoft.Storage/storageAccounts/blobServices@2024-01-01' = {
  parent: storageAccount
  name: 'default'
  properties: {
    isVersioningEnabled: enableBlobVersioning
    deleteRetentionPolicy: blobSoftDeleteRetentionDays > 0
      ? {
          enabled: true
          days: max(blobSoftDeleteRetentionDays, 1)
          allowPermanentDelete: false
        }
      : {
          enabled: false
        }
    containerDeleteRetentionPolicy: blobSoftDeleteRetentionDays > 0
      ? {
          enabled: true
          days: max(blobSoftDeleteRetentionDays, 1)
        }
      : {
          enabled: false
        }
  }
}

resource documentsContainer 'Microsoft.Storage/storageAccounts/blobServices/containers@2024-01-01' = {
  parent: blobServices
  name: blobContainerName
  properties: {
    publicAccess: 'None'
  }
}

resource fileServices 'Microsoft.Storage/storageAccounts/fileServices@2024-01-01' = {
  parent: storageAccount
  name: 'default'
  properties: {
    shareDeleteRetentionPolicy: fileShareSoftDeleteRetentionDays > 0
      ? {
          enabled: true
          days: max(fileShareSoftDeleteRetentionDays, 1)
        }
      : {
          enabled: false
        }
    protocolSettings: {
      smb: {
        versions: 'SMB3.0;SMB3.1.1'
        authenticationMethods: 'NTLMv2;Kerberos'
        channelEncryption: 'AES-128-GCM;AES-256-GCM'
      }
    }
  }
}

resource applicationShare 'Microsoft.Storage/storageAccounts/fileServices/shares@2024-01-01' = {
  parent: fileServices
  name: fileShareName
  properties: {
    shareQuota: fileShareQuotaGb
    enabledProtocols: 'SMB'
    accessTier: 'TransactionOptimized'
  }
}

resource blobDataContributor 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: storageAccount
  name: guid(storageAccount.id, workloadPrincipalId, blobDataContributorRoleDefinitionId)
  properties: {
    roleDefinitionId: subscriptionResourceId(
      'Microsoft.Authorization/roleDefinitions',
      blobDataContributorRoleDefinitionId
    )
    principalId: workloadPrincipalId
    principalType: 'ServicePrincipal'
  }
}

resource fileDataContributor 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: storageAccount
  name: guid(storageAccount.id, workloadPrincipalId, fileDataSmbShareContributorRoleDefinitionId)
  properties: {
    roleDefinitionId: subscriptionResourceId(
      'Microsoft.Authorization/roleDefinitions',
      fileDataSmbShareContributorRoleDefinitionId
    )
    principalId: workloadPrincipalId
    principalType: 'ServicePrincipal'
  }
}

@description('Storage account resource id.')
output storageAccountId string = storageAccount.id

@description('Storage account name.')
output storageAccountName string = storageAccount.name

@description('Azure Files share name mounted into the containers.')
output fileShareName string = applicationShare.name

@description('Blob container reserved for the future document storage refactor.')
output blobContainerName string = documentsContainer.name

@description('Primary blob endpoint.')
output blobEndpoint string = storageAccount.properties.primaryEndpoints.blob

@description('Primary file endpoint.')
output fileEndpoint string = storageAccount.properties.primaryEndpoints.file
