// Shared Bicep types for the Procynia Azure infrastructure.
//
// These types are imported by main.bicep and modules/container-apps.bicep so
// that environment parameter files are validated at build time instead of
// failing halfway through a deployment.

@export()
@description('One Laravel queue worker Container App. Mirrors a queue-* service in docker-compose.yml.')
type queueWorker = {
  @description('Short app-name suffix. Combined with the app prefix it must stay within the 32 character Container App name limit.')
  @minLength(2)
  @maxLength(18)
  name: string

  @description('Comma separated queue list passed verbatim to "php artisan queue:work --queue=".')
  @minLength(1)
  queues: string

  @description('Number of queue:work processes started inside a single replica (mirrors the "for i in 1 2 3" loop in docker-compose.yml).')
  @minValue(1)
  @maxValue(8)
  processes: int

  @description('--tries value.')
  @minValue(1)
  tries: int

  @description('--backoff value in seconds. Inert when tries = 1, kept so the contract matches docker-compose.yml.')
  @minValue(0)
  backoff: int

  @description('--timeout value in seconds. Must stay below retryAfter.')
  @minValue(30)
  timeout: int

  @description('REDIS_QUEUE_RETRY_AFTER for this worker. Must be greater than timeout or Laravel will release a job that is still running.')
  @minValue(30)
  retryAfter: int

  @description('Fixed replica count. minReplicas = maxReplicas, so no autoscaler can evict a long running job.')
  @minValue(1)
  @maxValue(20)
  replicas: int

  @description('vCPU request, e.g. "0.5". Container Apps requires memory = cpu * 2 Gi.')
  cpu: string

  @description('Memory request, e.g. "1Gi".')
  memory: string

  @description('Seconds Container Apps waits after SIGTERM before SIGKILL. Azure caps this at 600.')
  @minValue(30)
  @maxValue(600)
  terminationGracePeriodSeconds: int
}

@export()
@description('Mapping between a Container App secret, the Key Vault secret backing it, and the environment variable the application reads.')
type secretBinding = {
  @description('Container App secret name. Lowercase alphanumeric and dashes.')
  name: string

  @description('Secret name inside Key Vault.')
  keyVaultSecretName: string

  @description('Environment variable name exposed to the container.')
  environmentVariable: string
}

@export()
@description('A PostgreSQL firewall rule.')
type firewallRule = {
  name: string
  startIpAddress: string
  endIpAddress: string
}
