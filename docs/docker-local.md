# Procynia Docker Local Runtime

This project uses a single local Docker Compose stack for the Laravel runtime.

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
docker compose up --build
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
docker compose logs -f queue
```

The worker command is:

```bash
php artisan queue:work redis --queue=supplier-harvests,ai-requirements,default --tries=1 --timeout=0
```

To restart it:

```bash
docker compose restart queue
```

## Scheduler

The scheduler runs as a dedicated service:

```bash
docker compose logs -f scheduler
```

The scheduler command is:

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
docker compose logs -f app web postgres redis queue scheduler
```

Laravel file logs are still written under `storage/logs` inside the shared project volume.

## Frontend build

The Docker phase here does not add a Node container. Keep using the existing host command:

```bash
npm run build
```

## Environment

- The stack reads the local `.env` file.
- On a fresh clone, create `.env` from `.env.example` and run `php artisan key:generate` once before the first boot.
- Database and Redis service names are pinned in `docker-compose.yml`.
- `public/storage` is served directly by Nginx through an explicit alias, so no storage-link bootstrap step is required.

## Secrets and Env

Keep these values in your local `.env` file and out of the repository:

- `APP_KEY`
- `OPENAI_API_KEY`
- `STRIPE_KEY`
- `STRIPE_SECRET`
- `STRIPE_WEBHOOK_SECRET`
- `DOFFIN_API_KEY`
- mail credentials
- AWS credentials

Docker pins the local runtime services explicitly:

- PostgreSQL host: `postgres`
- Redis host: `redis`
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
