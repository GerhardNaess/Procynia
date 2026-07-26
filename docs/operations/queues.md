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
- `enterprise-wiki`
- `enterprise-wiki-pages`

## Worker commands

### Canonical Docker workers

Procynia runs **four separate worker services** in `docker-compose.yml`, not one combined worker — each Wiki/AI job family has very different runtime characteristics (a single Enterprise Wiki document flow job can legitimately run over half an hour; a supplier lookup job finishes in seconds), so one shared `--timeout` would either kill legitimate long jobs or leave short-queue jobs hung too long before being retried.

| Service | Queues | `--tries` | `--timeout` | Why |
|---|---|---|---|---|
| `queue` | `supplier-harvests,supplier-lookups,default` | 3 | 120s | Short, retryable jobs |
| `queue-ai-requirements` | `ai-requirements` | 1 | 2100s (35 min) | Full-document AI requirement extraction |
| `queue-enterprise-wiki` | `enterprise-wiki` | 1 | 1860s (31 min) | Document ingest/claim flow, can involve several AI calls in sequence |
| `queue-enterprise-wiki-pages` | `enterprise-wiki-pages` | 1 | 420s (7 min) | Single Wiki page generation |

The single-try, no-`--backoff`-retry queues (`ai-requirements`, `enterprise-wiki`, `enterprise-wiki-pages`) are deliberately `--tries=1`: these jobs already have their own internal resumability (staged sub-jobs, ingest-run status tracking) and re-running a partially-completed multi-step flow from scratch is not the correct recovery — a failure surfaces in `failed_jobs` for a human/ops decision instead.

Each container's own `command:` prints a startup line to its log, e.g.:

```text
[Procynia][Queue] Starting queue worker connection=redis queues=supplier-harvests,supplier-lookups,default tries=3 backoff=60 timeout=120 retry_after=420
```

### Legacy host worker

If you deliberately need to inspect a host-only debugging setup, the old database-queue worker path exists in history, but it is not the normal runtime and should not be used for daily operation.

## `queue:restart` does not start a stopped worker

`php artisan queue:restart` **only sets a cache flag** the running `queue:work` process checks between jobs — it tells an *already-running* worker to finish its current job and then exit gracefully. It does not, by itself, start anything.

If no worker process is currently running, `queue:restart` is a no-op with nothing to signal — jobs will sit in the queue until a worker starts.

The mechanism that actually keeps a worker running is the Docker Compose `restart: unless-stopped` policy on each worker service: when `queue:work` exits (whether from `queue:restart`, a crash, or the container/host restarting), Docker restarts the container, which runs the `command:` again and starts a fresh `queue:work` process picking up the current code. `queue:restart` and Docker's restart policy are two separate mechanisms that work together — the former asks for a clean handoff, the latter is what actually brings the worker back.

## Automatic restart after host reboot

Every worker service (`queue`, `queue-ai-requirements`, `queue-enterprise-wiki`, `queue-enterprise-wiki-pages`, `scheduler`) plus `redis` is configured with `restart: unless-stopped` in `docker-compose.yml`.

That means the Docker daemon restarts these containers automatically whenever it (re)starts — including after the host machine reboots — with no manual `queue:work` needed and no terminal window required.

**This depends on Docker Desktop itself coming up.** On macOS, `restart: unless-stopped` only takes effect once the Docker daemon is running. If you want the whole stack to come back automatically after a Mac reboot with no action at all, Docker Desktop must be configured to start at login (Docker Desktop → Settings → General → "Start Docker Desktop when you log in"). This is a one-time, per-machine Docker Desktop setting, not something this repository can configure — it is not touched by any file in this repo.

## Normal operation

### Normal oppstart

```bash
docker compose up -d
```

### Kontroller tjenester

```bash
docker compose ps
```

Every worker service now reports a `healthy`/`unhealthy`/`starting` status here (see "Docker healthcheck" below) — not just "Up".

### Se worker-logg

```bash
docker compose logs -f <worker-service>
# e.g. docker compose logs -f queue-enterprise-wiki
```

### Kontrollert restart av workers etter kodeendring

```bash
docker compose exec app php artisan queue:restart
```

The worker containers pick this up, finish their current job, exit, and the `restart: unless-stopped` policy starts them again automatically — no manual `queue:work` step.

### Failed jobs

```bash
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry <id-or-uuid>
docker compose exec app php artisan queue:retry all
```

### Restart just one worker service

```bash
docker compose restart queue-enterprise-wiki
```

## Docker healthcheck

Each worker/scheduler service has a `healthcheck:` in `docker-compose.yml` that runs a small, read-only artisan command inside the container — `ops:queue-heartbeat-status <queue>` or `ops:scheduler-heartbeat-status`. These reuse the exact same Redis-cached heartbeat timestamps as the `/ops/health/queues/{queue}` HTTP endpoints (see below), so a container only reports `healthy` once its own queue has actually processed a recent heartbeat job — not merely because PHP can start.

Neither command writes any data or dispatches a job; they only read an existing cache key.

The healthcheck `retries`/`interval` are tuned per worker to each queue's own worst-case single-job `--timeout`, so a busy-but-healthy worker mid-job is never mistaken for a dead one (e.g. `queue-ai-requirements` tolerates 40 minutes of staleness against a 35-minute job timeout). Verify with `docker compose ps`.

## How to verify a reboot start

After the host restarts, verify the services with:

```bash
docker compose ps
docker logs procynia-queue
docker logs procynia-queue-ai-requirements
docker logs procynia-queue-enterprise-wiki
docker logs procynia-queue-enterprise-wiki-pages
docker logs procynia-scheduler
docker logs procynia-redis
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
GET /ops/health/queues/enterprise-wiki
GET /ops/health/queues/enterprise-wiki-pages
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

#### Procynia - enterprise-wiki

- URL: `http://localhost:8080/ops/health/queues/enterprise-wiki`
- JSON query: `$.status`
- Condition: `==`
- Expected value: `ok`

#### Procynia - enterprise-wiki-pages

- URL: `http://localhost:8080/ops/health/queues/enterprise-wiki-pages`
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
