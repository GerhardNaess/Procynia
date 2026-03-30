# Doffin Supplier Harvest

## Local Runtime

The supplier harvest uses its own queue name from `config/doffin.php`:

- `supplier-harvests`

Start the local worker with:

```bash
php artisan queue:work --queue=supplier-harvests,default
```

Start a harvest run from the CLI with:

```bash
php artisan doffin:harvest-suppliers --from=YYYY-MM-DD --to=YYYY-MM-DD --type=RESULT
```

The admin page for this flow is:

- `/admin/doffin-supplier-harvest`
