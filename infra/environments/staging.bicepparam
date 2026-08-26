using '../main.bicep'

// ===========================================================================
// Procynia staging
// ===========================================================================
// Cheap, single instance defaults. No secret values live in this file.
//
// Required outside the repository:
//   PROCYNIA_PG_ADMIN_PASSWORD   PostgreSQL administrator password (env var)
//   PROCYNIA_DEPLOYER_OBJECT_ID  Optional, deploy.sh resolves it via "az ad signed-in-user show"
//
// Every other secret is set directly in Key Vault. See infra/README.md.

param environmentName = 'staging'
param location = readEnvironmentVariable('PROCYNIA_AZURE_LOCATION', 'norwayeast')

// --- Registry ---------------------------------------------------------------
param containerRegistrySku = 'Basic'

// --- Monitoring -------------------------------------------------------------
param logAnalyticsRetentionDays = 30
param logAnalyticsDailyQuotaGb = 2
param deployApplicationInsights = true

// --- Key Vault --------------------------------------------------------------
// Purge protection stays off in staging so the environment can be torn down and
// the vault name reused. Production enables it.
param keyVaultSoftDeleteRetentionDays = 7
param keyVaultPurgeProtection = false
param deployerPrincipalId = readEnvironmentVariable('PROCYNIA_DEPLOYER_OBJECT_ID', '')
param includeStripeSecrets = false
param includeMailSecrets = false

// --- Storage ----------------------------------------------------------------
param storageSku = 'Standard_LRS'
param fileShareName = 'procynia-storage'
param fileShareQuotaGb = 100
param fileShareSoftDeleteRetentionDays = 7
param blobContainerName = 'documents'
param blobSoftDeleteRetentionDays = 14
param enableBlobVersioning = true
param storageMountPath = '/var/www/html/storage/app'

// --- PostgreSQL -------------------------------------------------------------
// Burstable B1ms is the cheapest tier that still runs pgvector workloads. It
// supports neither high availability nor geo redundant backup, which is why both
// are disabled here and enabled in production.
param postgresVersion = '16'
param postgresSkuName = 'Standard_B1ms'
param postgresSkuTier = 'Burstable'
param postgresStorageSizeGb = 32
param postgresBackupRetentionDays = 7
param postgresGeoRedundantBackup = false
param postgresHighAvailabilityMode = 'Disabled'
param postgresDatabaseName = 'procynia'
param postgresAdministratorLogin = 'procynia_admin'
param postgresAdministratorPassword = readEnvironmentVariable('PROCYNIA_PG_ADMIN_PASSWORD', '')
param postgresAllowAzureServices = true
param postgresAdditionalFirewallRules = []
param postgresAllowedExtensions = 'VECTOR'

// --- Redis ------------------------------------------------------------------
// Balanced_B0 is the smallest Azure Managed Redis SKU and has no HA option.
param redisSku = 'Balanced_B0'
param redisHighAvailability = false

// --- Workloads --------------------------------------------------------------
// Keep false until the images are in ACR and the Key Vault secrets are seeded.
// deploy.sh flips this with --with-apps.
param deployWorkloads = false

param webImageRepository = 'procynia-web'
param appImageRepository = 'procynia-app'
param imageTag = readEnvironmentVariable('PROCYNIA_IMAGE_TAG', 'latest')
param appUrl = ''
param webTargetPort = 8080
param webMinReplicas = 1
param webMaxReplicas = 2
param webConcurrentRequests = 50
param webCpu = '0.5'
param webMemory = '1Gi'
param schedulerCpu = '0.25'
param schedulerMemory = '0.5Gi'

param logLevel = 'info'
param mailMailer = 'log'
param filesystemDisk = 'local'
param enterpriseWikiAiEnabled = false
// The legacy Compose backup can never work in Container Apps: no Docker CLI, no Compose
// project. Azure PostgreSQL automated backup and point-in-time restore apply instead
// (see postgresBackupRetentionDays above). This also protects a database migrated from
// Compose that still carries backup_settings.backup_enabled = true.
param legacyBackupEnabled = false
param doffinScheduledImportEnabled = false
param doffinWatchInboxDiscoveryEnabled = false
param doffinBaseUrl = 'https://api.doffin.no'

// --- Queue workers ----------------------------------------------------------
// Mirrors the queue-* services in docker-compose.yml one to one. queues, tries,
// backoff and timeout are copied verbatim so queue semantics do not change; only
// the in-container process counts are reduced for staging (compose runs 4/3/4 for
// claim verification, maintainer batches and pages).
//
// Every worker uses replicas as a fixed count. No scale rule exists, so a 35
// minute ai-requirements job can never be scaled away mid flight.
param workers = [
  {
    name: 'w-default'
    queues: 'supplier-harvests,supplier-lookups,default'
    processes: 1
    tries: 3
    backoff: 60
    timeout: 120
    retryAfter: 420
    replicas: 1
    cpu: '0.25'
    memory: '0.5Gi'
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
    cpu: '0.5'
    memory: '1Gi'
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
    cpu: '0.5'
    memory: '1Gi'
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
    cpu: '0.25'
    memory: '0.5Gi'
    terminationGracePeriodSeconds: 600
  }
  {
    name: 'w-wiki-claims'
    queues: 'enterprise-wiki-claim-verification'
    processes: 2
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
    name: 'w-wiki-batches'
    queues: 'enterprise-wiki-maintainer-batches'
    processes: 2
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
    name: 'w-wiki-pages'
    queues: 'enterprise-wiki-pages'
    processes: 2
    tries: 1
    backoff: 60
    timeout: 420
    retryAfter: 480
    replicas: 1
    cpu: '0.5'
    memory: '1Gi'
    terminationGracePeriodSeconds: 480
  }
]
