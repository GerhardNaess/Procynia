# Test database safety

Why this document exists: on 2026-09-06 and again on 2026-09-07, `php artisan migrate:fresh --env=testing`
dropped every table in the **development** database `procynia`. The flag reports environment
"testing" and is not a safety mechanism. This file records what is actually enforced, what is not,
and the rules that follow from that.

## The trap (fixed 2026-09-07)

Every Laravel container carried real OS environment variables:

```
APP_ENV=local
DB_DATABASE=procynia
```

They came from two places in `docker-compose.yml`, on all nine Laravel services:

- the `environment:` block (`APP_ENV: local`, `DB_DATABASE: ${POSTGRES_DB}`)
- `env_file: .env`, which injects **every** variable in `.env` as an OS variable

**Compose OS-env beats Dotenv.** Laravel's Dotenv does not overwrite variables that already exist
in the process environment, so `.env.testing` was ignored for exactly the keys that decide which
database gets dropped. `--env=testing` changed which file was read, not which database won.

`env_file:` was the subtler half. Removing the two `environment:` lines alone would have changed
nothing, because `.env`'s own `APP_ENV=local` and `DB_DATABASE=procynia` were still being injected.

### What changed

`env_file: .env` and the duplicated Laravel keys were removed from all nine Laravel services
(`app`, `scheduler`, and the seven `queue-*` workers). Laravel reads `/var/www/html/.env` from the
mounted project directory on its own; the container never needed to inject it.

`.env.testing` now actually governs:

```
$ docker exec procynia-app php artisan tinker --env=testing --execute="..."
testing | cfg=procynia_test | live=procynia_test | user=procynia_test_user
```

### What Compose still pins, and why

| Variable | Why it must stay |
|---|---|
| `REDIS_HOST`, `REDIS_PORT` | `.env` holds host-machine values (`127.0.0.1:6380`); the container needs `redis:6379` |
| `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_CONNECTION`, `SESSION_STORE`, `QUEUE_CONNECTION` | Stateless-runtime contract — a restart or a second replica must not fall back to file/database state. `RedisRuntimeContractTest` asserts `SESSION_DRIVER: redis` is present in this file |
| `REDIS_QUEUE_RETRY_AFTER` | Differs per worker (420/480/2100/2700) and is checked against `--timeout` by `QueueTopologyContractTest` |
| `TZ` | Container runtime, not in `.env` |

None of these can steer the connection to a different database, which is the property that matters
here.

## What is enforced

**1. A restricted PostgreSQL role — the only boundary that does not depend on application config.**

`procynia_test_user` owns `procynia_test` and has no `CONNECT` privilege on `procynia` or on any
recovery database. PostgreSQL refuses the connection before Laravel gets a say.

| Database | test role CONNECT | test role CREATE |
|---|---|---|
| `procynia_test` | yes | yes |
| `procynia` | **no** | no |
| `procynia_recovery_check` | **no** | no |
| `procynia_restore_check` | **no** | no |
| `procynia_restore_validation` | **no** | no |

The role is not a superuser and cannot create databases or roles.

**2. The PHPUnit guard** (`Tests\TestCase::guardAgainstUnsafeTestingDatabase`), which runs in
`createApplication()` before `setUpTraits()` — that is, before any migration or truncation. It
refuses to continue unless all of these hold: environment is `testing`; config is not cached; the
configured database is `procynia_test`; a live `select current_database()` agrees; and a live
`select current_user` returns `procynia_test_user`.

The suite gets the right credentials from `phpunit.xml` (`force="true"`) and
`TestCase::primeTestingEnvironment()`, both of which override the container environment. Test
credentials have one source of truth: `Tests\TestCase::TEST_DATABASE_CREDENTIALS`.

**3. `--env=testing` now resolves correctly**, so the command that caused the original damage points
at `procynia_test` as `procynia_test_user`. Two independent layers now have to fail before
`procynia` is at risk: the environment would have to resolve to it *and* the connecting role would
have to have CONNECT on it.

## What is NOT enforced

Nothing prevents a human from deliberately running a destructive command against `procynia` as
`gehard` — that is the developer's own account and their own database. The protection is that no
*test* path can reach it, and that no ordinary flag silently redirects there.

An application-level guard on `CommandStarting` was tried and removed: it blocked the command
without `--env=testing` but was never invoked with it, and a guard that disappears exactly when the
environment is misconfigured is worse than none. The environment fix above addresses the same
problem at its source.

## Rules

1. **Never validate a safety guard by running a destructive command against `procynia`.** Guards are
   tested against `procynia_test` or a throwaway database created for the purpose. Destructive means
   `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`.
2. **Never treat `--env=testing` as protection.** Prove the target with a live query before any
   writing command:
   ```
   docker exec procynia-postgres psql -U gehard -d postgres -tAc "select current_database()"
   ```
   `DB::connection()->getDatabaseName()` reads config; `select current_database()` reads the
   connection. Only the second is evidence.
3. **Run the suite through PHPUnit**, which forces the safe credentials. Bare artisan commands do
   not get that protection.

## Setup dependency

`procynia_test` must have the `vector` extension before the suite runs. `CREATE EXTENSION` requires
a superuser, which the test role deliberately is not, so it is created once by the owner:

```sql
CREATE EXTENSION IF NOT EXISTS vector;   -- as gehard, in procynia_test
```

It survives `migrate:fresh`, and the migration that needs it uses `IF NOT EXISTS`. Recreating
`procynia_test` from scratch means running this again, as a superuser, before the first test run.

## Recreating the test database

```sql
-- as a superuser
DROP DATABASE procynia_test;
CREATE DATABASE procynia_test OWNER procynia_test_user;
\c procynia_test
ALTER SCHEMA public OWNER TO procynia_test_user;
CREATE EXTENSION IF NOT EXISTS vector;
```

Ownership matters: with only `GRANT ALL` the role can write rows but cannot `DROP TABLE`, so
`migrate:fresh` fails with "must be owner of table".
