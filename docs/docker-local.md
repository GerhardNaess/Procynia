# Procynia Docker Runtime

This project uses a single Docker Compose stack for the canonical Procynia runtime.
Do not use host `php artisan serve`, host queue workers, or host scheduler processes as the normal path.

## Services

- `app`: PHP-FPM runtime for Laravel and Artisan commands
- `web`: Nginx front end for HTTP traffic
- `postgres`: PostgreSQL database
- `redis`: Redis for cache, queue, and session storage
- `queue`: Laravel queue worker
- `scheduler`: Laravel scheduler worker

## Ports

- `web` on `http://localhost:8080`
- `postgres` on `127.0.0.1:5433`
- `redis` on `127.0.0.1:6380`

## Start

```bash
docker compose up -d
```

Then open:

```text
http://localhost:8080
```

## Stop

```bash
docker compose down
```

To remove volumes as well:

```bash
docker compose down -v
```

## Migrations

```bash
docker compose exec app php artisan migrate
```

## Queue worker

The queue worker runs as a dedicated service:

```bash
docker logs procynia-queue
```

Inside the container, the worker command is:

```bash
php artisan queue:work redis --queue=supplier-harvests,supplier-lookups,ai-requirements,default --tries=3 --backoff=60 --timeout=120 --sleep=3
```

To restart it:

```bash
docker compose restart queue
```

Production deploys should also restart queue workers with:

```bash
docker compose exec app php artisan queue:restart
```

See `docs/operations/queues.md` for the canonical queue operations note.

## Scheduler

The scheduler runs as a dedicated service:

```bash
docker logs procynia-scheduler
```

Inside the container, the scheduler command is:

```bash
php artisan schedule:work
```

To restart it:

```bash
docker compose restart scheduler
```

## Tests

```bash
docker compose exec app php artisan test
```

## Logs

```bash
docker logs procynia-app
docker logs procynia-web
docker logs procynia-postgres
docker logs procynia-redis
docker logs procynia-queue
docker logs procynia-scheduler
```

Laravel file logs are still written under `storage/logs` inside the shared project volume.

## Frontend build

The Docker phase here does not add a Node container. Keep using the existing host build command:

```bash
npm run build
```

## Environment

- The stack reads the local `.env` file.
- On a fresh clone, create `.env` from `.env.example` and run `php artisan key:generate` once before the first boot.
- Database and Redis service names are pinned in `docker-compose.yml`.
- `public/storage` is served directly by Nginx through an explicit alias, so no storage-link bootstrap step is required.
- Host runtime commands such as `php artisan serve`, `php artisan queue:work database`, and `php artisan schedule:work` are legacy troubleshooting tools, not the normal runtime path.

## Secrets and Env

`docker-compose.yml` never contains real credentials. All secrets are read from
your local `.env` file via Docker Compose variable interpolation (`${VAR}`).

**`.env` must define the following for the stack to start:**

```dotenv
# PostgreSQL service and app connection (values must match)
POSTGRES_DB=procynia
POSTGRES_USER=<your local db user>
POSTGRES_PASSWORD=<your local db password>
```

Laravel reads the database connection as `DB_USERNAME` / `DB_PASSWORD` / `DB_DATABASE`,
which the compose file maps from the same `POSTGRES_*` variables.

**Never commit `.env`.** It is listed in `.gitignore`.

**`.env.example`** contains safe placeholder values (`change_me`). Copy it to `.env`
and fill in your actual credentials before the first `docker compose up`.

Additional secrets to keep in `.env` and out of the repository:

- `APP_KEY`
- `OPENAI_API_KEY`
- `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`
- `DOFFIN_API_KEY`
- mail credentials
- AWS credentials
- `PROCYNIA_HEALTH_TOKEN`

Docker pins the local runtime service names explicitly in `docker-compose.yml`:

- PostgreSQL host (inside Docker): `postgres` — port 5432
- PostgreSQL host (from host / pgAdmin): `127.0.0.1` — port 5433
- Redis host (inside Docker): `redis` — port 6379
- Redis host (from host): `127.0.0.1` — port 6380
- App URL: `http://localhost:8080`

## Persistent Storage

- PostgreSQL data lives in the named volume `procynia_postgres_data`
- Redis AOF data lives in the named volume `procynia_redis_data`
- Laravel application storage lives in the repo `storage/` directory
- Laravel bootstrap cache lives in the repo `bootstrap/cache/` directory

## Later migration

This layout maps cleanly to Azure or on-prem later:

- keep the same Laravel image for `app`, `queue`, and `scheduler`
- replace local PostgreSQL/Redis with managed services or dedicated hosts
- keep the web front end behind a reverse proxy or ingress
- keep persistent data in volumes or managed storage without changing application code
