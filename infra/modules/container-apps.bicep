// Procynia workloads on Azure Container Apps.
//
// The workload topology is taken directly from docker-compose.yml rather than
// from a generic Laravel template. The audit that produced it:
//
//   * Every queue-* service in docker-compose.yml sets its own
//     REDIS_QUEUE_RETRY_AFTER (420 / 2700 / 2100 / 480). That value configures
//     the Redis queue *connection*, not the worker invocation, so two queues
//     with different retry_after values cannot share one container without
//     changing queue semantics. This is why the worker set is modelled as an
//     array of Container Apps instead of one consolidated worker.
//
//   * Several services run more than one queue:work process inside a single
//     container (4 for claim verification, 3 for maintainer batches, 4 for
//     pages). That in-container fan out is reproduced here via the "processes"
//     field, so a Wiki run keeps the concurrency it was tuned for.
//
//   * --timeout values reach 2100s for ai-requirements and 1860s for
//     enterprise-wiki. Every worker therefore runs with minReplicas =
//     maxReplicas: no scale rule exists that could evict a replica in the middle
//     of a 35 minute job.
//
// Container Apps probes only support httpGet and tcpSocket, so the compose
// healthchecks based on "php artisan ops:queue-heartbeat-status" cannot be
// reproduced as probes. The scheduler keeps writing queue heartbeats, and worker
// liveness is observed through the token protected endpoints on the web app:
//   GET /ops/health/queues/{queue}
//   GET /ops/health/queue-scheduler

import { queueWorker, secretBinding } from '../types.bicep'

@description('Prefix for every Container App name, e.g. procynia-stg. Container App names are capped at 32 characters.')
@maxLength(14)
param appNamePrefix string

@description('Azure region.')
param location string

@description('Resource tags.')
param tags object

@description('Container Apps Environment resource id.')
param managedEnvironmentId string

@description('Name of the environment storage definition backing the Azure Files mount.')
param storageDefinitionName string

@description('Path the Azure Files share is mounted on. Must match Laravel storage layout: the local disk root is storage_path("app/private").')
param storageMountPath string = '/var/www/html/storage/app'

@description('ACR login server used as the image prefix.')
param registryLoginServer string

@description('Resource id of the user assigned identity used for ACR pull and Key Vault secret resolution.')
param workloadIdentityId string

@description('Key Vault base URI, including the trailing slash.')
param keyVaultUri string

@description('Container secrets resolved from Key Vault and the environment variables they back.')
param secretBindings secretBinding[]

@description('Non secret environment variables shared by web, workers and the scheduler.')
param sharedEnvironmentVariables array

@description('Fully qualified image for the web container. Must serve HTTP on webTargetPort and answer GET /up.')
param webImage string

@description('Fully qualified image for the queue workers and the scheduler. Runs "php artisan" only, no HTTP server.')
param appImage string

@description('Port the web image listens on.')
param webTargetPort int = 8080

@description('Minimum web replicas.')
@minValue(1)
param webMinReplicas int = 1

@description('Maximum web replicas.')
@minValue(1)
param webMaxReplicas int = 2

@description('Concurrent requests per replica before scaling out.')
@minValue(1)
param webConcurrentRequests int = 50

@description('Web vCPU request.')
param webCpu string = '0.5'

@description('Web memory request.')
param webMemory string = '1Gi'

@description('Scheduler vCPU request.')
param schedulerCpu string = '0.25'

@description('Scheduler memory request.')
param schedulerMemory string = '0.5Gi'

@description('Seconds the scheduler is given to finish after SIGTERM.')
@minValue(30)
@maxValue(600)
param schedulerTerminationGracePeriodSeconds int = 60

@description('REDIS_QUEUE_RETRY_AFTER for the web app. Only used for jobs dispatched from HTTP requests. docker-compose.yml uses 420.')
param webRedisQueueRetryAfter int = 420

@description('Queue worker Container Apps.')
param workers queueWorker[]

// ---------------------------------------------------------------------------
// Shared building blocks
// ---------------------------------------------------------------------------

var containerSecrets = [
  for binding in secretBindings: {
    name: binding.name
    keyVaultUrl: '${keyVaultUri}secrets/${binding.keyVaultSecretName}'
    identity: workloadIdentityId
  }
]

var secretEnvironmentVariables = [
  for binding in secretBindings: {
    name: binding.environmentVariable
    secretRef: binding.name
  }
]

var identityConfiguration = {
  type: 'UserAssigned'
  userAssignedIdentities: {
    '${workloadIdentityId}': {}
  }
}

var registryConfiguration = [
  {
    server: registryLoginServer
    identity: workloadIdentityId
  }
]

var sharedVolumes = [
  {
    name: 'app-storage'
    storageType: 'AzureFile'
    storageName: storageDefinitionName
  }
]

var sharedVolumeMounts = [
  {
    volumeName: 'app-storage'
    mountPath: storageMountPath
  }
]

// Reproduces the queue-* command block from docker-compose.yml, including the
// in-container process fan out and the SIGTERM trap that forwards termination to
// every child worker.
func queueWorkerCommand(queues string, processes int, tries int, backoff int, timeout int) string =>
  join(
    [
      'echo "[Procynia][Queue] starting ${processes} worker process(es) connection=redis queues=${queues} tries=${tries} backoff=${backoff} timeout=${timeout}"'
      'trap \'kill -TERM 0\' TERM INT'
      'worker_index=0'
      'while [ "\$worker_index" -lt ${processes} ]; do'
      '  php artisan queue:work redis --queue=${queues} --tries=${tries} --backoff=${backoff} --timeout=${timeout} --sleep=3 &'
      '  worker_index=\$((worker_index + 1))'
      'done'
      'wait'
    ],
    '\n'
  )

// ---------------------------------------------------------------------------
// Web
// ---------------------------------------------------------------------------

resource web 'Microsoft.App/containerApps@2025-01-01' = {
  name: '${appNamePrefix}-web'
  location: location
  tags: union(tags, { workload: 'web' })
  identity: identityConfiguration
  properties: {
    environmentId: managedEnvironmentId
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      maxInactiveRevisions: 3
      secrets: containerSecrets
      registries: registryConfiguration
      ingress: {
        external: true
        targetPort: webTargetPort
        transport: 'auto'
        allowInsecure: false
        clientCertificateMode: 'ignore'
        traffic: [
          {
            latestRevision: true
            weight: 100
          }
        ]
      }
    }
    template: {
      terminationGracePeriodSeconds: 60
      containers: [
        {
          name: 'web'
          image: webImage
          resources: {
            cpu: json(webCpu)
            memory: webMemory
          }
          env: concat(
            sharedEnvironmentVariables,
            secretEnvironmentVariables,
            [
              {
                name: 'PROCYNIA_ROLE'
                value: 'web'
              }
              {
                name: 'REDIS_QUEUE_RETRY_AFTER'
                value: string(webRedisQueueRetryAfter)
              }
            ]
          )
          volumeMounts: sharedVolumeMounts
          probes: [
            {
              type: 'Startup'
              httpGet: {
                path: '/up'
                port: webTargetPort
                scheme: 'HTTP'
              }
              initialDelaySeconds: 10
              periodSeconds: 5
              timeoutSeconds: 5
              failureThreshold: 30
            }
            {
              type: 'Readiness'
              httpGet: {
                path: '/up'
                port: webTargetPort
                scheme: 'HTTP'
              }
              periodSeconds: 10
              timeoutSeconds: 5
              failureThreshold: 3
            }
            {
              type: 'Liveness'
              httpGet: {
                path: '/up'
                port: webTargetPort
                scheme: 'HTTP'
              }
              initialDelaySeconds: 30
              periodSeconds: 30
              timeoutSeconds: 10
              failureThreshold: 5
            }
          ]
        }
      ]
      volumes: sharedVolumes
      scale: {
        minReplicas: webMinReplicas
        maxReplicas: webMaxReplicas
        rules: [
          {
            name: 'http-concurrency'
            http: {
              metadata: {
                concurrentRequests: string(webConcurrentRequests)
              }
            }
          }
        ]
      }
    }
  }
}

// ---------------------------------------------------------------------------
// Queue workers
// ---------------------------------------------------------------------------

resource queueWorkers 'Microsoft.App/containerApps@2025-01-01' = [
  for worker in workers: {
    name: '${appNamePrefix}-${worker.name}'
    location: location
    tags: union(tags, { workload: 'queue-worker', queues: worker.queues })
    identity: identityConfiguration
    properties: {
      environmentId: managedEnvironmentId
      workloadProfileName: 'Consumption'
      configuration: {
        activeRevisionsMode: 'Single'
        maxInactiveRevisions: 1
        secrets: containerSecrets
        registries: registryConfiguration
      }
      template: {
        terminationGracePeriodSeconds: worker.terminationGracePeriodSeconds
        containers: [
          {
            name: 'worker'
            image: appImage
            command: [
              '/bin/sh'
            ]
            args: [
              '-lc'
              queueWorkerCommand(worker.queues, worker.processes, worker.tries, worker.backoff, worker.timeout)
            ]
            resources: {
              cpu: json(worker.cpu)
              memory: worker.memory
            }
            env: concat(
              sharedEnvironmentVariables,
              secretEnvironmentVariables,
              [
                {
                  name: 'PROCYNIA_ROLE'
                  value: 'queue-worker'
                }
                {
                  name: 'PROCYNIA_QUEUES'
                  value: worker.queues
                }
                {
                  name: 'REDIS_QUEUE_RETRY_AFTER'
                  value: string(worker.retryAfter)
                }
              ]
            )
            volumeMounts: sharedVolumeMounts
          }
        ]
        volumes: sharedVolumes
        // Fixed replica count: minReplicas equals maxReplicas and no scale rule is
        // declared, so nothing can scale a long running AI or Wiki job to zero.
        scale: {
          minReplicas: worker.replicas
          maxReplicas: worker.replicas
        }
      }
    }
  }
]

// ---------------------------------------------------------------------------
// Scheduler - exactly one replica per environment
// ---------------------------------------------------------------------------

resource scheduler 'Microsoft.App/containerApps@2025-01-01' = {
  name: '${appNamePrefix}-scheduler'
  location: location
  tags: union(tags, { workload: 'scheduler' })
  identity: identityConfiguration
  properties: {
    environmentId: managedEnvironmentId
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      maxInactiveRevisions: 1
      secrets: containerSecrets
      registries: registryConfiguration
    }
    template: {
      terminationGracePeriodSeconds: schedulerTerminationGracePeriodSeconds
      containers: [
        {
          name: 'scheduler'
          image: appImage
          command: [
            '/bin/sh'
          ]
          args: [
            '-lc'
            'echo "[Procynia][Scheduler] starting scheduler worker"\nexec php artisan schedule:work'
          ]
          resources: {
            cpu: json(schedulerCpu)
            memory: schedulerMemory
          }
          env: concat(
            sharedEnvironmentVariables,
            secretEnvironmentVariables,
            [
              {
                name: 'PROCYNIA_ROLE'
                value: 'scheduler'
              }
            ]
          )
          volumeMounts: sharedVolumeMounts
        }
      ]
      volumes: sharedVolumes
      // Laravel's scheduler must never run twice. minReplicas = maxReplicas = 1.
      scale: {
        minReplicas: 1
        maxReplicas: 1
      }
    }
  }
}

@description('Public HTTPS URL of the web app.')
output webUrl string = 'https://${web.properties.configuration.ingress.fqdn}'

@description('Web Container App name.')
output webAppName string = web.name

@description('Scheduler Container App name.')
output schedulerAppName string = scheduler.name

@description('Queue worker Container App names.')
output queueWorkerAppNames array = [for (worker, index) in workers: queueWorkers[index].name]
