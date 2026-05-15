# Procynia Queue Operations

## Purpose

This document describes how Procynia queue workers run in the canonical Docker-based runtime, how they are restarted after deploy, and how queue and scheduler health is checked.

## Current queue connection

Docker is the normal runtime and uses Redis for queues.

- The Docker runtime sets `QUEUE_CONNECTION=redis`
- The worker service uses the Redis connection explicitly
- `.env.example` is a template only and is not production runtime configuration
- Host database queue usage is legacy only and should not be used as the normal operating mode

## Active queues

The application currently uses these queue names:

- `default`
- `supplier-harvests`
- `supplier-lookups`
- `ai-requirements`

## Worker commands

### Canonical Docker worker

Use the Redis-backed worker service defined in `docker-compose.yml`. Inside the `procynia-queue` container, the worker command is:

```bash
php artisan queue:work redis --queue=supplier-harvests,supplier-lookups,ai-requirements,default --tries=1 --timeout=0
```

### Legacy host worker

If you deliberately need to inspect a host-only debugging setup, the old database-queue worker path exists in history, but it is not the normal runtime and should not be used for daily operation.

## Deploy restart step

After new code has been deployed and before old workers continue processing new jobs, restart queue workers:

```bash
docker compose exec app php artisan queue:restart
```

This is required so long-running Laravel workers stop using old code after deploy.

## Automatic restart after host reboot

The Docker services `queue`, `scheduler`, and `redis` are configured with `restart: unless-stopped` in `docker-compose.yml`.

That means Docker will start them again automatically after the host machine reboots.

## How to verify a reboot start

After the host restarts, verify the services with:

```bash
docker compose ps
docker logs procynia-queue
docker logs procynia-scheduler
docker logs procynia-redis
```

The queue log should show:

```text
[Procynia][Queue] Starting queue worker connection=redis queues=supplier-harvests,supplier-lookups,ai-requirements,default
```

The scheduler log should show:

```text
[Procynia][Scheduler] Starting scheduler worker
```

## Queue and scheduler health

The operational health endpoint is:

```text
GET /ops/health/queue-scheduler
```

It checks two cached heartbeats:

- `ops.scheduler.heartbeat`
- `ops.queue.heartbeat`

The stale threshold is `300` seconds in `app/Services/Operations/QueueSchedulerHealthService.php`.

Response semantics:

- `200` means both heartbeats are fresh
- `503` means queue or scheduler should be investigated

## Per-queue health

Each Laravel queue now has its own heartbeat monitor endpoint. These check whether the corresponding queue has actually processed a heartbeat job recently, not merely whether the container is running.

Endpoints:

```text
GET /ops/health/queues/supplier-harvests
GET /ops/health/queues/supplier-lookups
GET /ops/health/queues/ai-requirements
GET /ops/health/queues/default
```

These endpoints all return JSON with the queue-specific heartbeat status. The health check is fresh when the last processed heartbeat is not older than `300` seconds. A missing or stale heartbeat returns `503`.

### Uptime Kuma monitors

Create one JSON Query monitor per queue:

#### Procynia - supplier-harvests

- URL: `http://localhost:8080/ops/health/queues/supplier-harvests`
- JSON query: `$.status`
- Condition: `==`
- Expected value: `ok`

#### Procynia - supplier-lookups

- URL: `http://localhost:8080/ops/health/queues/supplier-lookups`
- JSON query: `$.status`
- Condition: `==`
- Expected value: `ok`

#### Procynia - ai-requirements

- URL: `http://localhost:8080/ops/health/queues/ai-requirements`
- JSON query: `$.status`
- Condition: `==`
- Expected value: `ok`

#### Procynia - default

- URL: `http://localhost:8080/ops/health/queues/default`
- JSON query: `$.status`
- Condition: `==`
- Expected value: `ok`

The existing `/ops/health/queue-scheduler` endpoint remains the combined queue/scheduler control. Use it for the overall heartbeat status, and use the per-queue endpoints to verify each queue independently.

## Knowledge metadata jobs

These jobs currently use the default queue because they do not set an explicit queue name:

- `App\Jobs\GenerateKnowledgeChunkMetadataForDocument`
- `App\Jobs\GenerateKnowledgeChunkMetadataBatch`

If they ever begin to block heartbeat or other default work, a separate queue such as `knowledge-metadata` can be considered later. That is a future improvement, not part of the current fix.

## Docker versus non-Docker runtime

Docker is the documented production runtime for Procynia today.

If Procynia is ever run without Docker, the queue worker and scheduler will need a separate Supervisor or systemd setup. That is not required for the current Docker-based deployment.
