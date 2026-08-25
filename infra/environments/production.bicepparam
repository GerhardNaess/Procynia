using '../main.bicep'

// ===========================================================================
// Procynia production
// ===========================================================================
// Conservative, not oversized. No secret values live in this file.
//
// Required outside the repository:
//   PROCYNIA_PG_ADMIN_PASSWORD   PostgreSQL administrator password (env var)
//   PROCYNIA_IMAGE_TAG           Immutable image tag or digest, never "latest"
//   PROCYNIA_DEPLOYER_OBJECT_ID  Optional, deploy.sh resolves it automatically
//
// Every other secret is set directly in Key Vault. See infra/README.md.
//
// Production has never been deployed from this template. Treat the first run as a
// what-if review, not as a release.

param environmentName = 'production'
param location = readEnvironmentVariable('PROCYNIA_AZURE_LOCATION', 'norwayeast')

// --- Registry ---------------------------------------------------------------
// Standard only raises the included storage and throughput. Premium is deferred
// until private endpoints are introduced.
param containerRegistrySku = 'Standard'

// --- Monitoring -------------------------------------------------------------
param logAnalyticsRetentionDays = 90
param logAnalyticsDailyQuotaGb = -1
param deployApplicationInsights = true

// --- Key Vault --------------------------------------------------------------
// Purge protection is irreversible. That is the point in production.
param keyVaultSoftDeleteRetentionDays = 90
param keyVaultPurgeProtection = true
param deployerPrincipalId = readEnvironmentVariable('PROCYNIA_DEPLOYER_OBJECT_ID', '')
param includeStripeSecrets = false
param includeMailSecrets = false

// --- Storage ----------------------------------------------------------------
param storageSku = 'Standard_ZRS'
param fileShareName = 'procynia-storage'
param fileShareQuotaGb = 500
param fileShareSoftDeleteRetentionDays = 30
param blobContainerName = 'documents'
param blobSoftDeleteRetentionDays = 30
param enableBlobVersioning = true
param storageMountPath = '/var/www/html/storage/app'

// --- PostgreSQL -------------------------------------------------------------
// GeneralPurpose is required for high availability and geo redundant backup.
// D2ds_v5 is 2 vCPU / 8 GiB, which is the smallest GP size.
param postgresVersion = '16'
param postgresSkuName = 'Standard_D2ds_v5'
param postgresSkuTier = 'GeneralPurpose'
param postgresStorageSizeGb = 128
param postgresBackupRetentionDays = 35
param postgresGeoRedundantBackup = true
param postgresHighAvailabilityMode = 'ZoneRedundant'
param postgresDatabaseName = 'procynia'
param postgresAdministratorLogin = 'procynia_admin'
param postgresAdministratorPassword = readEnvironmentVariable('PROCYNIA_PG_ADMIN_PASSWORD', '')
// Kept open to Azure services only because Container Apps has no VNet
// integration yet. Narrow this to the environment outbound IP, or replace it
// with a private endpoint, before production carries real data.
param postgresAllowAzureServices = true
param postgresAdditionalFirewallRules = []
param postgresAllowedExtensions = 'VECTOR'

// --- Redis ------------------------------------------------------------------
// B1 is the smallest Azure Managed Redis SKU that supports high availability.
param redisSku = 'Balanced_B1'
param redisHighAvailability = true

// --- Workloads --------------------------------------------------------------
param deployWorkloads = false

param webImageRepository = 'procynia-web'
param appImageRepository = 'procynia-app'
param imageTag = readEnvironmentVariable('PROCYNIA_IMAGE_TAG', '')
param appUrl = ''
param webTargetPort = 8080
param webMinReplicas = 2
param webMaxReplicas = 6
param webConcurrentRequests = 40
param webCpu = '1.0'
param webMemory = '2Gi'
param schedulerCpu = '0.5'
param schedulerMemory = '1Gi'

param logLevel = 'info'
// Mail is out of scope for this phase. Flip to a real mailer, set
// includeMailSecrets = true and seed MAIL-USERNAME / MAIL-PASSWORD when that work
// happens.
param mailMailer = 'log'
param filesystemDisk = 'local'
param enterpriseWikiAiEnabled = false
param doffinScheduledImportEnabled = true
param doffinWatchInboxDiscoveryEnabled = true
param doffinBaseUrl = 'https://api.doffin.no'

// --- Queue workers ----------------------------------------------------------
// Same topology as staging, with the in-container process counts restored to the
// values docker-compose.yml was tuned to (4 claim verification, 3 maintainer
// batches, 4 pages) and larger resource requests for the long running AI and Wiki
// workloads. Fixed replica counts throughout: nothing autoscales a worker.
param workers = [
  {
    name: 'w-default'
    queues: 'supplier-harvests,supplier-lookups,default'
    processes: 2
    tries: 3
    backoff: 60
    timeout: 120
    retryAfter: 420
    replicas: 1
    cpu: '0.5'
    memory: '1Gi'
    terminationGracePeriodSeconds: 180
  }
  {
    name: 'w-ai-req'
    queues: 'ai-requirements'
    processes: 1
    tries: 1
    backoff: 60
    timeout: 2100
    retryAfter: 2700
    replicas: 1
    cpu: '1.0'
    memory: '2Gi'
    terminationGracePeriodSeconds: 600
  }
  {
    name: 'w-wiki'
    queues: 'enterprise-wiki'
    processes: 1
    tries: 1
    backoff: 60
    timeout: 1860
    retryAfter: 2100
    replicas: 1
    cpu: '1.0'
    memory: '2Gi'
    terminationGracePeriodSeconds: 600
  }
  {
    name: 'w-wiki-recon'
    queues: 'enterprise-wiki-reconciliation'
    processes: 1
    tries: 1
    backoff: 60
    timeout: 1800
    retryAfter: 2100
    replicas: 1
    cpu: '0.5'
    memory: '1Gi'
    terminationGracePeriodSeconds: 600
  }
  {
    name: 'w-wiki-claims'
    queues: 'enterprise-wiki-claim-verification'
    processes: 4
    tries: 1
    backoff: 60
    timeout: 1800
    retryAfter: 2100
    replicas: 1
    cpu: '1.0'
    memory: '2Gi'
    terminationGracePeriodSeconds: 600
  }
  {
    name: 'w-wiki-batches'
    queues: 'enterprise-wiki-maintainer-batches'
    processes: 3
    tries: 1
    backoff: 60
    timeout: 1800
    retryAfter: 2100
    replicas: 1
    cpu: '1.0'
    memory: '2Gi'
    terminationGracePeriodSeconds: 600
  }
  {
    name: 'w-wiki-pages'
    queues: 'enterprise-wiki-pages'
    processes: 4
    tries: 1
    backoff: 60
    timeout: 420
    retryAfter: 480
    replicas: 1
    cpu: '1.0'
    memory: '2Gi'
    terminationGracePeriodSeconds: 480
  }
]
