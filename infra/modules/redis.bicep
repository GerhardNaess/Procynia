// Azure Managed Redis (Microsoft.Cache/redisEnterprise), not the retired
// Azure Cache for Redis (Microsoft.Cache/redis).
//
// Redis is mandatory for Procynia: docker-compose.yml runs every service with
// QUEUE_CONNECTION=redis, CACHE_STORE=redis and SESSION_DRIVER=redis.
//
// Two decisions worth knowing about:
//
//  1. clusteringPolicy = EnterpriseCluster. Procynia uses the non cluster aware
//     phpredis client (REDIS_CLIENT=phpredis). Against an OSSCluster endpoint
//     that client would receive MOVED redirects it cannot follow.
//
//  2. Access is handed to the application as a single REDIS_URL secret of the
//     form tls://default:<key>@<host>:10000/0, written straight into Key Vault.
//     Laravel's RedisManager promotes the "tls" URL scheme to the phpredis TLS
//     scheme, and config/database.php already reads REDIS_URL for both the
//     "default" and the "cache" connection. The explicit /0 database path
//     matters: Azure Managed Redis exposes a single logical database, so the
//     local REDIS_CACHE_DB=1 split cannot be carried over. Cache, session and
//     queue keys stay separated by their Laravel key prefixes instead.
//
// The access key is never emitted as an output.

@description('Azure Managed Redis cluster name.')
@minLength(3)
@maxLength(60)
param redisName string

@description('Azure region.')
param location string

@description('Azure Managed Redis SKU. Balanced_B0 is the smallest and cheapest; it does not support high availability.')
param skuName string = 'Balanced_B0'

@description('Resource tags.')
param tags object

@description('Enable high availability (zone redundant replication). Not available on Balanced_B0.')
param highAvailability bool = false

@description('Key vault that receives the connection secret.')
param keyVaultName string

@description('Key Vault secret name holding the full Redis connection URL.')
param redisUrlSecretName string = 'REDIS-URL'

@description('Eviction policy. NoEviction is required: Redis also backs the queues, and evicting a queue key would silently drop jobs.')
@allowed([
  'NoEviction'
  'AllKeysLRU'
  'AllKeysLFU'
  'AllKeysRandom'
  'VolatileLRU'
  'VolatileLFU'
  'VolatileRandom'
  'VolatileTTL'
])
param evictionPolicy string = 'NoEviction'

var redisPort = 10000

resource redis 'Microsoft.Cache/redisEnterprise@2025-04-01' = {
  name: redisName
  location: location
  tags: tags
  sku: {
    name: skuName
  }
  identity: {
    type: 'SystemAssigned'
  }
  properties: {
    minimumTlsVersion: '1.2'
    highAvailability: highAvailability ? 'Enabled' : 'Disabled'
  }
}

resource redisDatabase 'Microsoft.Cache/redisEnterprise/databases@2025-04-01' = {
  parent: redis
  name: 'default'
  properties: {
    clientProtocol: 'Encrypted'
    port: redisPort
    clusteringPolicy: 'EnterpriseCluster'
    evictionPolicy: evictionPolicy
    accessKeysAuthentication: 'Enabled'
    persistence: {
      aofEnabled: false
      rdbEnabled: false
    }
  }
}

resource keyVault 'Microsoft.KeyVault/vaults@2024-11-01' existing = {
  name: keyVaultName
}

resource redisUrlSecret 'Microsoft.KeyVault/vaults/secrets@2024-11-01' = {
  parent: keyVault
  name: redisUrlSecretName
  properties: {
    value: 'tls://default:${redisDatabase.listKeys().primaryKey}@${redis.properties.hostName}:${redisPort}/0'
    contentType: 'Laravel REDIS_URL (queue, cache, session)'
  }
}

@description('Azure Managed Redis resource id.')
output redisId string = redis.id

@description('Azure Managed Redis cluster name.')
output redisName string = redis.name

@description('Redis hostname. Not a secret on its own; access still requires the key.')
output hostName string = redis.properties.hostName

@description('Redis TLS port.')
output port int = redisPort

@description('Key Vault secret name holding the connection URL.')
output redisUrlSecretName string = redisUrlSecret.name
