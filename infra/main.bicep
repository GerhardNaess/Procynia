// ===========================================================================
// Procynia - Azure infrastructure as code
// ===========================================================================
//
// Scope: resource group. The resource group itself is created by infra/deploy.sh,
// which keeps this template at a single scope and avoids a subscription level
// deployment just to call resourceGroup().
//
// Deployment is intentionally two phased, because Container Apps cannot start
// before their image exists in ACR and their secrets exist in Key Vault:
//
//   Phase 1  deployWorkloads = false
//            ACR, Log Analytics, Application Insights, Key Vault, Storage,
//            PostgreSQL Flexible Server (+ vector allowlist), Azure Managed
//            Redis, workload identity with its role assignments, and the
//            Container Apps Environment with the Azure Files mount.
//
//   Phase 2  deployWorkloads = true
//            web, the queue worker Container Apps and the single scheduler.
//
// Between the phases: seed the Key Vault secrets, build and push the images, and
// run the database migration job (which is what creates the vector extension).
// infra/README.md documents the exact order.
//
// No secret value is emitted as an output by this template or by any module.

targetScope = 'resourceGroup'

import { queueWorker, firewallRule } from './types.bicep'

// ---------------------------------------------------------------------------
// Environment identity
// ---------------------------------------------------------------------------

@description('Logical environment. Drives naming, tags and every sizing default.')
@allowed([
  'staging'
  'production'
])
param environmentName string

@description('Azure region for every resource.')
param location string = 'norwayeast'

@description('Short suffix that makes globally unique names deterministic per resource group. Override only to adopt existing resources.')
@minLength(3)
@maxLength(6)
param resourceNameSuffix string = take(uniqueString(resourceGroup().id), 5)

@description('Value used for the Laravel APP_NAME environment variable.')
param applicationDisplayName string = 'Procynia'

// ---------------------------------------------------------------------------
// Container registry
// ---------------------------------------------------------------------------

@description('ACR SKU. Basic is sufficient until private endpoints or geo replication are required.')
@allowed([
  'Basic'
  'Standard'
  'Premium'
])
param containerRegistrySku string = 'Basic'

// ---------------------------------------------------------------------------
// Monitoring
// ---------------------------------------------------------------------------

@description('Log Analytics retention in days.')
@minValue(30)
@maxValue(730)
param logAnalyticsRetentionDays int = 30

@description('Daily Log Analytics ingestion cap in GB. -1 means no cap.')
param logAnalyticsDailyQuotaGb int = -1

@description('Deploy a workspace based Application Insights component. Nothing in the application is instrumented against it yet.')
param deployApplicationInsights bool = true

// ---------------------------------------------------------------------------
// Key Vault
// ---------------------------------------------------------------------------

@description('Key Vault soft delete retention in days.')
@minValue(7)
@maxValue(90)
param keyVaultSoftDeleteRetentionDays int = 90

@description('Key Vault purge protection. Irreversible once enabled, so staging normally leaves it off.')
param keyVaultPurgeProtection bool = false

@description('Object id of the principal running the deployment. Receives Key Vault Secrets Officer so that "az keyvault secret set" works immediately. deploy.sh fills this in automatically.')
param deployerPrincipalId string = ''

@description('Also bind the Stripe secrets into the containers. Laravel Cashier is installed, but billing is not built yet, so this defaults to off.')
param includeStripeSecrets bool = false

@description('Also bind MAIL_USERNAME and MAIL_PASSWORD into the containers. Only relevant once a real mailer replaces MAIL_MAILER=log.')
param includeMailSecrets bool = false

// ---------------------------------------------------------------------------
// Storage
// ---------------------------------------------------------------------------

@description('Storage replication SKU.')
@allowed([
  'Standard_LRS'
  'Standard_ZRS'
  'Standard_GRS'
  'Standard_GZRS'
])
param storageSku string = 'Standard_LRS'

@description('Azure Files share name.')
param fileShareName string = 'procynia-storage'

@description('Azure Files quota in GiB.')
param fileShareQuotaGb int = 100

@description('Azure Files soft delete retention in days.')
param fileShareSoftDeleteRetentionDays int = 14

@description('Blob container reserved for the future document storage target state.')
param blobContainerName string = 'documents'

@description('Blob soft delete retention in days.')
param blobSoftDeleteRetentionDays int = 30

@description('Blob versioning.')
param enableBlobVersioning bool = true

@description('Mount path for the Azure Files share. storage/app holds both the "local" (app/private) and "public" (app/public) Laravel disks, while framework cache, compiled views and sessions stay on the container filesystem where SMB latency cannot hurt them.')
param storageMountPath string = '/var/www/html/storage/app'

// ---------------------------------------------------------------------------
// PostgreSQL
// ---------------------------------------------------------------------------

@description('PostgreSQL major version.')
@allowed([
  '16'
  '17'
])
param postgresVersion string = '16'

@description('PostgreSQL compute SKU name.')
param postgresSkuName string = 'Standard_B1ms'

@description('PostgreSQL compute tier.')
@allowed([
  'Burstable'
  'GeneralPurpose'
  'MemoryOptimized'
])
param postgresSkuTier string = 'Burstable'

@description('PostgreSQL storage in GiB.')
@allowed([
  32
  64
  128
  256
  512
  1024
  2048
])
param postgresStorageSizeGb int = 32

@description('Automated backup and point in time restore retention, in days.')
@minValue(7)
@maxValue(35)
param postgresBackupRetentionDays int = 7

@description('Geo redundant backup. Requires GeneralPurpose or MemoryOptimized.')
param postgresGeoRedundantBackup bool = false

@description('PostgreSQL high availability mode.')
@allowed([
  'Disabled'
  'SameZone'
  'ZoneRedundant'
])
param postgresHighAvailabilityMode string = 'Disabled'

@description('Application database name.')
param postgresDatabaseName string = 'procynia'

@description('PostgreSQL administrator login.')
param postgresAdministratorLogin string = 'procynia_admin'

@description('PostgreSQL administrator password. Supplied at deployment time from the environment, never committed. deploy.sh refuses to run without it.')
@secure()
@minLength(12)
param postgresAdministratorPassword string

@description('Allow Azure services through the PostgreSQL firewall. Required while Container Apps has no VNet integration.')
param postgresAllowAzureServices bool = true

@description('Extra PostgreSQL firewall rules, for example an operator IP range.')
param postgresAdditionalFirewallRules firewallRule[] = []

@description('Server level extension allowlist (azure.extensions). Procynia only needs VECTOR for pgvector.')
param postgresAllowedExtensions string = 'VECTOR'

// ---------------------------------------------------------------------------
// Redis
// ---------------------------------------------------------------------------

@description('Azure Managed Redis SKU. Balanced_B0 is the cheapest and has no high availability option.')
param redisSku string = 'Balanced_B0'

@description('Azure Managed Redis high availability. Not supported on Balanced_B0.')
param redisHighAvailability bool = false

// ---------------------------------------------------------------------------
// Workloads
// ---------------------------------------------------------------------------

@description('Deploy the Container Apps. Keep false on the first deployment: the apps need an image in ACR and their secrets in Key Vault first.')
param deployWorkloads bool = false

@description('Repository name for the web image inside ACR.')
param webImageRepository string = 'procynia-web'

@description('Repository name for the CLI image used by the workers and the scheduler.')
param appImageRepository string = 'procynia-app'

@description('Image tag deployed to this environment. Prefer an immutable tag or digest over "latest".')
param imageTag string = 'latest'

@description('Full override for the web image reference. Leave empty to use <acr>/<webImageRepository>:<imageTag>.')
param webImage string = ''

@description('Full override for the worker and scheduler image reference. Leave empty to use <acr>/<appImageRepository>:<imageTag>.')
param appImage string = ''

@description('Public URL of the environment. Leave empty to derive the Container Apps default hostname.')
param appUrl string = ''

@description('Port the web image listens on.')
param webTargetPort int = 8080

@description('Minimum web replicas.')
param webMinReplicas int = 1

@description('Maximum web replicas.')
param webMaxReplicas int = 2

@description('Concurrent requests per web replica before scaling out.')
param webConcurrentRequests int = 50

@description('Web vCPU request. Container Apps requires memory to be exactly cpu * 2 Gi.')
param webCpu string = '0.5'

@description('Web memory request.')
param webMemory string = '1Gi'

@description('Scheduler vCPU request.')
param schedulerCpu string = '0.25'

@description('Scheduler memory request.')
param schedulerMemory string = '0.5Gi'

@description('Queue worker Container Apps. Defined per environment so the topology stays visible in one place.')
param workers queueWorker[]

@description('Laravel LOG_LEVEL.')
@allowed([
  'debug'
  'info'
  'notice'
  'warning'
  'error'
])
param logLevel string = 'info'

@description('MAIL_MAILER. Mail is out of scope for this phase, so both environments start on "log".')
param mailMailer string = 'log'

@description('FILESYSTEM_DISK. Stays on "local" - the application has not been refactored to a remote driver.')
param filesystemDisk string = 'local'

@description('ENTERPRISE_WIKI_AI_ENABLED.')
param enterpriseWikiAiEnabled bool = false

@description('DOFFIN_SCHEDULED_IMPORT_ENABLED.')
param doffinScheduledImportEnabled bool = false

@description('DOFFIN_WATCH_INBOX_DISCOVERY_ENABLED.')
param doffinWatchInboxDiscoveryEnabled bool = false

@description('DOFFIN_BASE_URL. config/doffin.php has no default for this.')
param doffinBaseUrl string = 'https://api.doffin.no'

@description('Application timezone. Matches date.timezone in docker/php/conf.d/local.ini and the Europe/Oslo scheduler expectations in routes/console.php.')
param applicationTimezone string = 'Europe/Oslo'

// ---------------------------------------------------------------------------
// Naming and tags
// ---------------------------------------------------------------------------

var environmentShortCode = environmentName == 'production' ? 'prd' : 'stg'
var baseName = 'procynia-${environmentName}'
var compactBaseName = replace(baseName, '-', '')

var names = {
  logAnalytics: 'log-${baseName}'
  applicationInsights: 'appi-${baseName}'
  workloadIdentity: 'id-${baseName}'
  containerRegistry: 'acr${compactBaseName}${resourceNameSuffix}'
  keyVault: 'kv-procynia-${environmentShortCode}-${resourceNameSuffix}'
  storageAccount: 'stprocynia${environmentShortCode}${resourceNameSuffix}'
  postgres: 'psql-${baseName}-${resourceNameSuffix}'
  redis: 'redis-${baseName}-${resourceNameSuffix}'
  containerAppsEnvironment: 'cae-${baseName}'
  containerAppPrefix: 'procynia-${environmentShortCode}'
}

var tags = {
  application: 'procynia'
  environment: environmentName
  'managed-by': 'bicep'
}

// ---------------------------------------------------------------------------
// Secret contract
// ---------------------------------------------------------------------------
// Key Vault secret names use dashes because Key Vault does not allow
// underscores. environmentVariable is the name the application actually reads.

var requiredSecretBindings = [
  {
    name: 'app-key'
    keyVaultSecretName: 'APP-KEY'
    environmentVariable: 'APP_KEY'
  }
  {
    name: 'db-password'
    keyVaultSecretName: 'DB-PASSWORD'
    environmentVariable: 'DB_PASSWORD'
  }
  {
    name: 'redis-url'
    keyVaultSecretName: 'REDIS-URL'
    environmentVariable: 'REDIS_URL'
  }
  {
    name: 'openai-api-key'
    keyVaultSecretName: 'OPENAI-API-KEY'
    environmentVariable: 'OPENAI_API_KEY'
  }
  {
    name: 'doffin-api-key'
    keyVaultSecretName: 'DOFFIN-API-KEY'
    environmentVariable: 'DOFFIN_API_KEY'
  }
  {
    name: 'procynia-health-token'
    keyVaultSecretName: 'PROCYNIA-HEALTH-TOKEN'
    environmentVariable: 'PROCYNIA_HEALTH_TOKEN'
  }
]

var stripeSecretBindings = [
  {
    name: 'stripe-key'
    keyVaultSecretName: 'STRIPE-KEY'
    environmentVariable: 'STRIPE_KEY'
  }
  {
    name: 'stripe-secret'
    keyVaultSecretName: 'STRIPE-SECRET'
    environmentVariable: 'STRIPE_SECRET'
  }
  {
    name: 'stripe-webhook-secret'
    keyVaultSecretName: 'STRIPE-WEBHOOK-SECRET'
    environmentVariable: 'STRIPE_WEBHOOK_SECRET'
  }
]

var mailSecretBindings = [
  {
    name: 'mail-username'
    keyVaultSecretName: 'MAIL-USERNAME'
    environmentVariable: 'MAIL_USERNAME'
  }
  {
    name: 'mail-password'
    keyVaultSecretName: 'MAIL-PASSWORD'
    environmentVariable: 'MAIL_PASSWORD'
  }
]

var secretBindings = concat(
  requiredSecretBindings,
  includeStripeSecrets ? stripeSecretBindings : [],
  includeMailSecrets ? mailSecretBindings : []
)

// Secrets Bicep provisions itself, because it either has to know the value
// anyway (PostgreSQL admin password) or can read it from the resource it just
// created (Redis access key).
var bicepProvisionedSecretNames = [
  'DB-PASSWORD'
  'REDIS-URL'
]

var operatorProvisionedSecretNames = [
  for binding in secretBindings: contains(bicepProvisionedSecretNames, binding.keyVaultSecretName)
    ? ''
    : binding.keyVaultSecretName
]

// ---------------------------------------------------------------------------
// Platform
// ---------------------------------------------------------------------------

module monitoring 'modules/monitoring.bicep' = {
  name: 'monitoring'
  params: {
    logAnalyticsWorkspaceName: names.logAnalytics
    applicationInsightsName: names.applicationInsights
    location: location
    tags: tags
    retentionInDays: logAnalyticsRetentionDays
    dailyQuotaGb: logAnalyticsDailyQuotaGb
    deployApplicationInsights: deployApplicationInsights
  }
}

module identity 'modules/identity.bicep' = {
  name: 'workload-identity'
  params: {
    identityName: names.workloadIdentity
    location: location
    tags: tags
  }
}

module registry 'modules/registry.bicep' = {
  name: 'container-registry'
  params: {
    registryName: names.containerRegistry
    location: location
    tags: tags
    sku: containerRegistrySku
    pullPrincipalId: identity.outputs.principalId
  }
}

module keyVault 'modules/key-vault.bicep' = {
  name: 'key-vault'
  params: {
    keyVaultName: names.keyVault
    location: location
    tags: tags
    softDeleteRetentionInDays: keyVaultSoftDeleteRetentionDays
    enablePurgeProtection: keyVaultPurgeProtection
    readerPrincipalId: identity.outputs.principalId
    deployerPrincipalId: deployerPrincipalId
    databasePasswordSecretName: 'DB-PASSWORD'
    databasePassword: postgresAdministratorPassword
  }
}

module storage 'modules/storage.bicep' = {
  name: 'storage'
  params: {
    storageAccountName: names.storageAccount
    location: location
    tags: tags
    sku: storageSku
    fileShareName: fileShareName
    fileShareQuotaGb: fileShareQuotaGb
    fileShareSoftDeleteRetentionDays: fileShareSoftDeleteRetentionDays
    blobContainerName: blobContainerName
    blobSoftDeleteRetentionDays: blobSoftDeleteRetentionDays
    enableBlobVersioning: enableBlobVersioning
    workloadPrincipalId: identity.outputs.principalId
  }
}

module postgres 'modules/postgres.bicep' = {
  name: 'postgres'
  params: {
    serverName: names.postgres
    location: location
    tags: tags
    postgresVersion: postgresVersion
    skuName: postgresSkuName
    skuTier: postgresSkuTier
    storageSizeGb: postgresStorageSizeGb
    backupRetentionDays: postgresBackupRetentionDays
    geoRedundantBackup: postgresGeoRedundantBackup
    highAvailabilityMode: postgresHighAvailabilityMode
    databaseName: postgresDatabaseName
    administratorLogin: postgresAdministratorLogin
    administratorPassword: postgresAdministratorPassword
    allowAzureServices: postgresAllowAzureServices
    additionalFirewallRules: postgresAdditionalFirewallRules
    allowedExtensions: postgresAllowedExtensions
  }
}

module redis 'modules/redis.bicep' = {
  name: 'redis'
  params: {
    redisName: names.redis
    location: location
    tags: tags
    skuName: redisSku
    highAvailability: redisHighAvailability
    keyVaultName: keyVault.outputs.keyVaultName
    redisUrlSecretName: 'REDIS-URL'
  }
}

module containerAppsEnvironment 'modules/container-apps-environment.bicep' = {
  name: 'container-apps-environment'
  params: {
    environmentName: names.containerAppsEnvironment
    location: location
    tags: tags
    logAnalyticsWorkspaceName: monitoring.outputs.logAnalyticsWorkspaceName
    storageAccountName: storage.outputs.storageAccountName
    fileShareName: storage.outputs.fileShareName
    storageDefinitionName: 'procynia-app-storage'
    zoneRedundant: false
  }
}

// ---------------------------------------------------------------------------
// Runtime environment variable contract
// ---------------------------------------------------------------------------
// Only values that Laravel cannot resolve correctly on its own are set here.
// Notably absent on purpose:
//   APP_LOCALE            config/app.php already defaults to 'no'
//   OPENAI_BASE_URL/MODEL config/services.php already has the correct defaults
//   BCRYPT_ROUNDS         config default is already 12
// Present because the repository has no usable default:
//   PDF*_BINARY           config/services.php reads env() with no fallback
//   DOFFIN_BASE_URL       config/doffin.php reads env() with no fallback

var resolvedWebHostname = '${names.containerAppPrefix}-web.${containerAppsEnvironment.outputs.defaultDomain}'
var resolvedAppUrl = empty(appUrl) ? 'https://${resolvedWebHostname}' : appUrl

var sharedEnvironmentVariables = [
  {
    name: 'APP_NAME'
    value: applicationDisplayName
  }
  {
    name: 'APP_ENV'
    value: environmentName
  }
  {
    name: 'APP_DEBUG'
    value: 'false'
  }
  {
    name: 'APP_URL'
    value: resolvedAppUrl
  }
  {
    name: 'LOG_CHANNEL'
    value: 'stderr'
  }
  {
    name: 'LOG_LEVEL'
    value: logLevel
  }
  {
    name: 'LOG_DEPRECATIONS_CHANNEL'
    value: 'null'
  }
  {
    name: 'DB_CONNECTION'
    value: 'pgsql'
  }
  {
    name: 'DB_HOST'
    value: postgres.outputs.fullyQualifiedDomainName
  }
  {
    name: 'DB_PORT'
    value: '5432'
  }
  {
    name: 'DB_DATABASE'
    value: postgres.outputs.databaseName
  }
  {
    name: 'DB_USERNAME'
    value: postgres.outputs.administratorLogin
  }
  {
    name: 'DB_SSLMODE'
    value: 'require'
  }
  {
    name: 'REDIS_CLIENT'
    value: 'phpredis'
  }
  // Azure Managed Redis exposes a single logical database, so the local
  // REDIS_CACHE_DB=1 split from docker-compose.yml cannot be carried over.
  // Cache, session and queue keys remain separated by their Laravel prefixes.
  {
    name: 'REDIS_DB'
    value: '0'
  }
  {
    name: 'REDIS_CACHE_DB'
    value: '0'
  }
  {
    name: 'CACHE_STORE'
    value: 'redis'
  }
  {
    name: 'SESSION_DRIVER'
    value: 'redis'
  }
  {
    name: 'SESSION_CONNECTION'
    value: 'default'
  }
  {
    name: 'SESSION_STORE'
    value: 'redis'
  }
  {
    name: 'SESSION_SECURE_COOKIE'
    value: 'true'
  }
  {
    name: 'QUEUE_CONNECTION'
    value: 'redis'
  }
  {
    name: 'FILESYSTEM_DISK'
    value: filesystemDisk
  }
  {
    name: 'MAIL_MAILER'
    value: mailMailer
  }
  {
    name: 'TZ'
    value: applicationTimezone
  }
  {
    name: 'PDFTOTEXT_BINARY'
    value: '/usr/bin/pdftotext'
  }
  {
    name: 'PDFTOHTML_BINARY'
    value: '/usr/bin/pdftohtml'
  }
  {
    name: 'PDFIMAGES_BINARY'
    value: '/usr/bin/pdfimages'
  }
  {
    name: 'PDFINFO_BINARY'
    value: '/usr/bin/pdfinfo'
  }
  {
    name: 'DOFFIN_BASE_URL'
    value: doffinBaseUrl
  }
  {
    name: 'DOFFIN_SCHEDULED_IMPORT_ENABLED'
    value: string(doffinScheduledImportEnabled)
  }
  {
    name: 'DOFFIN_WATCH_INBOX_DISCOVERY_ENABLED'
    value: string(doffinWatchInboxDiscoveryEnabled)
  }
  {
    name: 'ENTERPRISE_WIKI_AI_ENABLED'
    value: string(enterpriseWikiAiEnabled)
  }
]

var resolvedWebImage = empty(webImage)
  ? '${registry.outputs.loginServer}/${webImageRepository}:${imageTag}'
  : webImage
var resolvedAppImage = empty(appImage)
  ? '${registry.outputs.loginServer}/${appImageRepository}:${imageTag}'
  : appImage

module workloads 'modules/container-apps.bicep' = if (deployWorkloads) {
  name: 'container-apps'
  params: {
    appNamePrefix: names.containerAppPrefix
    location: location
    tags: tags
    managedEnvironmentId: containerAppsEnvironment.outputs.managedEnvironmentId
    storageDefinitionName: containerAppsEnvironment.outputs.storageDefinitionName
    storageMountPath: storageMountPath
    registryLoginServer: registry.outputs.loginServer
    workloadIdentityId: identity.outputs.identityId
    keyVaultUri: keyVault.outputs.keyVaultUri
    secretBindings: secretBindings
    sharedEnvironmentVariables: sharedEnvironmentVariables
    webImage: resolvedWebImage
    appImage: resolvedAppImage
    webTargetPort: webTargetPort
    webMinReplicas: webMinReplicas
    webMaxReplicas: webMaxReplicas
    webConcurrentRequests: webConcurrentRequests
    webCpu: webCpu
    webMemory: webMemory
    schedulerCpu: schedulerCpu
    schedulerMemory: schedulerMemory
    workers: workers
  }
  dependsOn: [
    redis
  ]
}

// ---------------------------------------------------------------------------
// Outputs - identifiers and hostnames only, never secret material
// ---------------------------------------------------------------------------

@description('Environment this deployment targets.')
output environmentName string = environmentName

@description('Azure region.')
output location string = location

@description('Container registry name, for "az acr login" and "az acr build".')
output containerRegistryName string = registry.outputs.registryName

@description('Container registry login server, used as the image prefix.')
output containerRegistryLoginServer string = registry.outputs.loginServer

@description('Expected web image reference for this environment.')
output webImageReference string = resolvedWebImage

@description('Expected worker and scheduler image reference for this environment.')
output appImageReference string = resolvedAppImage

@description('Key Vault name.')
output keyVaultName string = keyVault.outputs.keyVaultName

@description('Key Vault base URI.')
output keyVaultUri string = keyVault.outputs.keyVaultUri

@description('Key Vault secrets provisioned by this template.')
output keyVaultSecretsProvisionedByBicep array = bicepProvisionedSecretNames

@description('Key Vault secrets an operator must set before deploying the workloads.')
output keyVaultSecretsRequiringOperatorInput array = filter(operatorProvisionedSecretNames, name => !empty(name))

@description('PostgreSQL host, used as DB_HOST.')
output postgresHost string = postgres.outputs.fullyQualifiedDomainName

@description('PostgreSQL database name.')
output postgresDatabaseName string = postgres.outputs.databaseName

@description('PostgreSQL administrator login.')
output postgresAdministratorLogin string = postgres.outputs.administratorLogin

@description('Extensions allowed at server level. "vector" still has to be created by a migration.')
output postgresAllowedExtensions string = postgresAllowedExtensions

@description('Azure Managed Redis hostname.')
output redisHostName string = redis.outputs.hostName

@description('Azure Managed Redis TLS port.')
output redisPort int = redis.outputs.port

@description('Storage account name.')
output storageAccountName string = storage.outputs.storageAccountName

@description('Azure Files share mounted into the containers.')
output fileShareName string = storage.outputs.fileShareName

@description('Path the Azure Files share is mounted on.')
output storageMountPath string = storageMountPath

@description('Blob container reserved for the future document storage target state.')
output blobContainerName string = storage.outputs.blobContainerName

@description('Log Analytics workspace name.')
output logAnalyticsWorkspaceName string = monitoring.outputs.logAnalyticsWorkspaceName

@description('Application Insights name, empty when not deployed.')
output applicationInsightsName string = monitoring.outputs.applicationInsightsName

@description('Container Apps Environment name.')
output containerAppsEnvironmentName string = containerAppsEnvironment.outputs.managedEnvironmentName

@description('Container Apps Environment default ingress domain.')
output containerAppsDefaultDomain string = containerAppsEnvironment.outputs.defaultDomain

@description('Container Apps outbound IP. Use it to narrow the PostgreSQL firewall once the environment is stable.')
output containerAppsOutboundIp string = containerAppsEnvironment.outputs.staticIp

@description('Workload managed identity name.')
output workloadIdentityName string = identity.outputs.identityName

@description('Workload managed identity client id.')
output workloadIdentityClientId string = identity.outputs.clientId

@description('Whether the Container Apps were part of this deployment.')
output workloadsDeployed bool = deployWorkloads

@description('Public URL of the environment.')
output applicationUrl string = deployWorkloads ? workloads!.outputs.webUrl : 'https://${resolvedWebHostname}'

@description('Queue worker Container App names, empty until the workloads are deployed.')
output queueWorkerAppNames array = deployWorkloads ? workloads!.outputs.queueWorkerAppNames : []

@description('Queue topology deployed to this environment.')
output queueTopology array = [
  for worker in workers: {
    app: '${names.containerAppPrefix}-${worker.name}'
    queues: worker.queues
    processes: worker.processes
    replicas: worker.replicas
    timeout: worker.timeout
    retryAfter: worker.retryAfter
  }
]
