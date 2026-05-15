# Operational Runbooks

Procynia stores operational runbook categories as database data in `operational_runbook_categories`.

## Category Management

- The Driftsrutiner form reads categories from the database.
- New categories can be created directly from the category dropdown in Filament.
- The legacy `operational_runbooks.category` slug remains in place so existing runbooks keep their current category values.
- Existing categories are seeded into the database and should not be hardcoded in the resource class.

## Practical Notes

- Use the dropdown to pick an existing category when creating or editing a runbook.
- If a new operating theme is needed, create it from the dropdown instead of changing code.
- The `sort_order` and `is_active` values on categories are stored in the database so operations can manage them over time.
