# Doffin Supplier Harvest

## Docker Runtime

The supplier harvest uses its own queue name from `config/doffin.php`:

- `supplier-harvests`

In the canonical Docker runtime, the queue worker is the `procynia-queue` service and it listens on Redis.

Inside that service, the worker command is:

```bash
php artisan queue:work redis --queue=supplier-harvests,supplier-lookups,ai-requirements,default --tries=1 --timeout=0
```

Start a harvest run from the CLI with:

```bash
docker compose exec app php artisan doffin:harvest-suppliers --from=YYYY-MM-DD --to=YYYY-MM-DD --type=RESULT
```

The admin page for this flow is:

- `/admin/doffin-supplier-harvest`

This flow uses the shared queue worker described in `docs/operations/queues.md`.

Host queue workers and `php artisan serve` are legacy debugging tools only and are not the normal runtime path.
