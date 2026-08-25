// Azure Database for PostgreSQL Flexible Server.
//
// pgvector: Azure does not let a server load arbitrary extensions. The "vector"
// extension must first be added to the server level "azure.extensions"
// allowlist (done here, declaratively), and only afterwards can a client run
// CREATE EXTENSION. This module deliberately does NOT run CREATE EXTENSION and
// does NOT run Laravel migrations - see the ordering section in infra/README.md.
// Procynia already ships that statement in
// database/migrations/2026_05_21_000001_add_pgvector_embedding_column_to_knowledge_item_chunks_table.php
// so the migration job is the correct place for it.
//
// Backup: Azure native automated backup with point in time restore is the target
// state. The container based procynia:backup command is explicitly not part of
// this design.

@description('Flexible Server name. Globally unique DNS label.')
@minLength(3)
@maxLength(63)
param serverName string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

@description('PostgreSQL major version. Procynia runs pgvector/pgvector:0.8.2-pg16 locally, so 16 keeps parity.')
@allowed([
  '16'
  '17'
])
param postgresVersion string = '16'

@description('Compute SKU name, e.g. Standard_B1ms (Burstable) or Standard_D2ds_v5 (GeneralPurpose).')
param skuName string = 'Standard_B1ms'

@description('Compute tier. High availability and geo redundant backup are not available on Burstable.')
@allowed([
  'Burstable'
  'GeneralPurpose'
  'MemoryOptimized'
])
param skuTier string = 'Burstable'

@description('Storage size in GiB.')
@allowed([
  32
  64
  128
  256
  512
  1024
  2048
])
param storageSizeGb int = 32

@description('Enable storage auto grow.')
param storageAutoGrow bool = true

@description('Retention window for automated backups and point in time restore, in days.')
@minValue(7)
@maxValue(35)
param backupRetentionDays int = 7

@description('Geo redundant backup. Requires GeneralPurpose or MemoryOptimized.')
param geoRedundantBackup bool = false

@description('High availability mode. Requires GeneralPurpose or MemoryOptimized. ZoneRedundant additionally requires a region with availability zones.')
@allowed([
  'Disabled'
  'SameZone'
  'ZoneRedundant'
])
param highAvailabilityMode string = 'Disabled'

@description('Application database name.')
param databaseName string = 'procynia'

@description('Administrator login.')
param administratorLogin string

@description('Administrator password. Supplied at deployment time.')
@secure()
param administratorPassword string

@description('Allow connections from Azure services. Required while the Container Apps Environment has no VNet integration, because its egress comes from the Azure public range.')
param allowAzureServices bool = true

@description('Extra firewall rules, for example an office or operator IP range.')
param additionalFirewallRules array = []

@description('Comma separated extension allowlist written to the azure.extensions server parameter. Procynia only needs VECTOR.')
param allowedExtensions string = 'VECTOR'

resource server 'Microsoft.DBforPostgreSQL/flexibleServers@2024-08-01' = {
  name: serverName
  location: location
  tags: tags
  sku: {
    name: skuName
    tier: skuTier
  }
  properties: {
    version: postgresVersion
    administratorLogin: administratorLogin
    administratorLoginPassword: administratorPassword
    createMode: 'Default'
    storage: {
      storageSizeGB: storageSizeGb
      autoGrow: storageAutoGrow ? 'Enabled' : 'Disabled'
      tier: 'P4'
    }
    backup: {
      backupRetentionDays: backupRetentionDays
      geoRedundantBackup: geoRedundantBackup ? 'Enabled' : 'Disabled'
    }
    highAvailability: {
      mode: highAvailabilityMode
    }
    network: {
      publicNetworkAccess: 'Enabled'
    }
    authConfig: {
      activeDirectoryAuth: 'Disabled'
      passwordAuth: 'Enabled'
    }
  }
}

resource database 'Microsoft.DBforPostgreSQL/flexibleServers/databases@2024-08-01' = {
  parent: server
  name: databaseName
  properties: {
    charset: 'UTF8'
    collation: 'en_US.utf8'
  }
}

// Server parameter changes and firewall rules both mutate the server, so they are
// chained rather than issued concurrently.
resource extensionAllowlist 'Microsoft.DBforPostgreSQL/flexibleServers/configurations@2024-08-01' = {
  parent: server
  name: 'azure.extensions'
  properties: {
    value: allowedExtensions
    source: 'user-override'
  }
  dependsOn: [
    database
  ]
}

resource allowAzure 'Microsoft.DBforPostgreSQL/flexibleServers/firewallRules@2024-08-01' = if (allowAzureServices) {
  parent: server
  name: 'AllowAllAzureServices'
  properties: {
    startIpAddress: '0.0.0.0'
    endIpAddress: '0.0.0.0'
  }
  dependsOn: [
    extensionAllowlist
  ]
}

@batchSize(1)
resource extraRules 'Microsoft.DBforPostgreSQL/flexibleServers/firewallRules@2024-08-01' = [
  for rule in additionalFirewallRules: {
    parent: server
    name: rule.name
    properties: {
      startIpAddress: rule.startIpAddress
      endIpAddress: rule.endIpAddress
    }
    dependsOn: [
      allowAzure
      extensionAllowlist
    ]
  }
]

@description('Flexible Server resource id.')
output serverId string = server.id

@description('Flexible Server name.')
output serverName string = server.name

@description('Fully qualified domain name used as DB_HOST.')
output fullyQualifiedDomainName string = server.properties.fullyQualifiedDomainName

@description('Application database name used as DB_DATABASE.')
output databaseName string = database.name

@description('Administrator login used as DB_USERNAME.')
output administratorLogin string = administratorLogin
